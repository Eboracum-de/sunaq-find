<?php
declare(strict_types=1);

namespace OCA\SunaqFind\Service;

use OCP\IL10N;

final class SearchService {
    private $rewriter;
    private $planner;
    private $retrieval;
    private $evidenceBuilder;
    private $answerer;
    private $config;
    private $l10n;
    public function __construct(
        QueryRewriteService $rewriter,
        RetrievalPlanner $planner,
        RetrievalCoordinator $retrieval,
        EvidenceBuilder $evidenceBuilder,
        AnswerService $answerer,
        ConfigService $config,
        ?IL10N $l10n = null
    ) {
        $this->rewriter = $rewriter;
        $this->planner = $planner;
        $this->retrieval = $retrieval;
        $this->evidenceBuilder = $evidenceBuilder;
        $this->answerer = $answerer;
        $this->config = $config;
        $this->l10n = $l10n;
    }

    private function t(string $text, array $parameters = []): string {
        if (isset($this->l10n) && $this->l10n !== null) {
            return $this->l10n->t($text, $parameters);
        }

        return $parameters === [] ? $text : vsprintf($text, $parameters);
    }

    public function search(string $query, string $plannerReasoning = 'current'): array {
        $query = trim($query);
        if ($query === '' || mb_strlen($query, 'UTF-8') > 1200) {
            throw new \InvalidArgumentException($this->t('The search query must be between 1 and 1200 characters.'));
        }

        $plannerReasoning = strtolower(trim($plannerReasoning));
        if (!in_array($plannerReasoning, ['current', 'none', 'default', 'thinking'], true)) {
            $plannerReasoning = 'current';
        }
        if (!$this->config->isAnalysisMode()) {
            $plannerReasoning = 'current';
        }

        $rewrite = $this->rewriter->rewrite($query, $plannerReasoning);
        $plan = $this->planner->planFromSearchSpec(
            $rewrite['spec'],
            $query,
            (bool)$rewrite['files_fallback'],
            $this->config->isDirectLexicalRoutingEnabled()
        );
        $retrieval = $this->retrieval->retrieve(
            $plan,
            $query,
            $this->config->getSearchDocumentLimit()
        );
        $retrieval['hits'] = $this->deduplicateHits(
            is_array($retrieval['hits'] ?? null) ? $retrieval['hits'] : []
        );
        $evidence = $this->evidenceBuilder->build(
            $retrieval['hits'],
            $this->config->getLlmDocumentLimit()
        );

        $searchContext = $this->searchContext($rewrite['spec']);
        $searchContext['required_literals'] = is_array($plan['required_literals'] ?? null)
            ? $plan['required_literals']
            : [];
        $answerContext = $searchContext;
        unset($answerContext['alternative_interpretations']);
        $searchContext['search_mode'] = (string)($plan['search_mode'] ?? 'search_spec');
        $searchContext['direct_queries'] = is_array($plan['direct_queries'] ?? null)
            ? $plan['direct_queries']
            : [];
        $searchStrategy = $this->config->showSearchStrategy()
            ? $this->searchStrategy($searchContext)
            : '';
        $answerError = false;
        $answerErrorDetail = '';
        $answerFound = false;
        $answerEntityInterpretations = [];
        if ($evidence['documents'] === []) {
            $answer = $this->t('The current search interpretation did not find sufficient evidence.')
                . "\n\n" . $this->searchGuidance($query, $searchContext);
        } else {
            try {
                $assessment = $this->answerer->answerWithAssessment($query, $evidence['json'], $answerContext);
                $answerFound = (bool)$assessment['answer_found'];
                $answer = (string)$assessment['answer'];
                $answerEntityInterpretations = is_array($assessment['entity_interpretations'] ?? null)
                    ? $assessment['entity_interpretations']
                    : [];
                $answer .= "\n\n" . $this->searchGuidance($query, $searchContext);
                [$answer, $cited] = $this->normalizeCitations($answer, count($evidence['sources']));
                if ($cited !== []) {
                    $evidence['sources'] = array_values(array_filter(
                        $evidence['sources'],
                        static function (array $source) use ($cited): bool { return in_array((int)$source['index'], $cited, true); }
                    ));
                }
            } catch (\RuntimeException $e) {
                $answer = $e->getMessage();
                $answerError = true;
                $detail = $e->getPrevious();
                if ($detail instanceof \Throwable) {
                    $answerErrorDetail = trim($detail->getMessage());
                }
            }
        }

        if (!$this->config->showDocumentExcerpts()) {
            foreach ($evidence['sources'] as &$source) {
                if (is_array($source)) {
                    unset($source['snippet'], $source['path']);
                }
            }
            unset($source);
        }

        $analysisEnabled = $this->config->isAnalysisMode();
        $result = [
            'analysis_enabled' => $analysisEnabled,
            'answer' => $answer,
            'answer_error' => $answerError,
            'answer_found' => $answerFound,
            'search_strategy' => $searchStrategy,
            'sources' => $evidence['sources'],
        ];
        if ($analysisEnabled && $answerError && $answerErrorDetail !== '') {
            $result['answer_error_detail'] = $answerErrorDetail;
        }

        if ($analysisEnabled) {
            $result['query_analysis'] = [
                'rewrite_status' => (string)$rewrite['status'],
                'search_spec' => $rewrite['spec'],
                'fallback_used' => (bool)$rewrite['fallback_used'],
                'retrieval_languages' => $this->config->getRetrievalLanguages(),
                'planner_reasoning' => (string)($rewrite['reasoning_mode'] ?? 'current'),
                'answer_entity_interpretations' => $answerEntityInterpretations,
                'retrieval_plan' => [
                    'search_mode' => $plan['search_mode'],
                    'retrieval_arms' => $plan['retrieval_arms'],
                    'max_rounds' => $plan['max_rounds'],
                    'required_literals' => is_array($plan['required_literals'] ?? null)
                        ? $plan['required_literals']
                        : [],
                    'arm_capabilities' => $this->retrieval->capabilities(),
                ],
            ];
            $result['retrieval'] = [
                'candidate_count' => $retrieval['candidate_count'],
                'authorized_count' => $retrieval['allowed_count'],
                'denied_count' => $retrieval['denied_count'],
                'unresolved_count' => $retrieval['unresolved_count'],
                'acl_prefilter_status' => (string)($retrieval['acl_prefilter_status'] ?? 'unknown'),
                'acl_prefilter_applied' => (bool)($retrieval['acl_prefilter_applied'] ?? false),
                'acl_prefilter_fallback' => (bool)($retrieval['acl_prefilter_fallback'] ?? false),
                'acl_prefilter_reason' => (string)($retrieval['acl_prefilter_reason'] ?? ''),
                'unresolved_breakdown' => [
                    'missing_file_id' => (int)($retrieval['unresolved_missing_file_id'] ?? 0),
                    'not_found' => (int)($retrieval['unresolved_not_found'] ?? 0),
                    'resolution_failed' => (int)($retrieval['unresolved_resolution_failed'] ?? 0),
                ],
            ];
        }

        return $result;
    }

    private function deduplicateHits(array $hits): array {
        $kept = [];
        $seen = [];

        foreach ($hits as $hit) {
            if (!is_array($hit)) {
                continue;
            }

            $name = trim((string)($hit['name'] ?? ''));
            $path = trim((string)($hit['path'] ?? ''));
            if ($name === '') {
                $kept[] = $hit;
                continue;
            }

            $stem = pathinfo($name, PATHINFO_FILENAME);
            $stem = mb_strtolower($stem, 'UTF-8');
            $stem = preg_replace('/[^\p{L}\p{N}]+/u', '', $stem) ?? '';
            if ($stem === '') {
                $kept[] = $hit;
                continue;
            }

            $directory = str_replace('\\', '/', dirname($path));
            $directory = mb_strtolower(trim($directory), 'UTF-8');
            $snippet = mb_strtolower((string)($hit['snippet'] ?? ''), 'UTF-8');
            $snippet = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $snippet) ?? '';
            $snippet = preg_replace('/\s+/u', ' ', trim($snippet)) ?? '';
            $snippet = mb_substr($snippet, 0, 420, 'UTF-8');

            $key = $directory . '|' . $stem;
            if (isset($seen[$key])) {
                continue;
            }

            $duplicateElsewhere = false;
            if ($snippet !== '' && mb_strlen($snippet, 'UTF-8') >= 80) {
                foreach ($seen as $meta) {
                    if (($meta['stem'] ?? '') === $stem && ($meta['snippet'] ?? '') === $snippet) {
                        $duplicateElsewhere = true;
                        break;
                    }
                }
            }
            if ($duplicateElsewhere) {
                continue;
            }

            $seen[$key] = [
                'stem' => $stem,
                'snippet' => $snippet,
            ];
            $kept[] = $hit;
        }

        return $kept;
    }

    private function searchContext(array $spec): array {
        return [
            'lexical_query' => trim((string)($spec['lexical_query'] ?? '')),
            'semantic_query' => trim((string)($spec['semantic_query'] ?? '')),
            'alternative_interpretations' => is_array($spec['alternative_interpretations'] ?? null)
                ? $spec['alternative_interpretations']
                : [],
            'term_interpretations' => is_array($spec['term_interpretations'] ?? null)
                ? $spec['term_interpretations']
                : [],
            'entities' => is_array($spec['entities'] ?? null) ? $spec['entities'] : [],
            'concept_groups' => is_array($spec['concept_groups'] ?? null) ? $spec['concept_groups'] : [],
            'constraints' => is_array($spec['constraints'] ?? null) ? $spec['constraints'] : [],
            'verification_requirements' => is_array($spec['verification_requirements'] ?? null)
                ? $spec['verification_requirements']
                : [],
        ];
    }

    private function searchGuidance(string $query, array $context): string {
        $lines = ['**' . $this->t('Searched for:') . '**'];

        $semantic = trim((string)($context['semantic_query'] ?? ''));
        if ($semantic !== '') {
            $lines[] = '- ' . $this->t('Search interpretation: %s', [$semantic]);
        }

        $termNotice = $this->termInterpretationNotice($context);
        if ($termNotice !== '') {
            $lines[] = $termNotice;
        }

        $lines[] = '';
        $lines[] = $this->t('Different wording can lead to a different search interpretation, different search terms, and different results.');

        foreach ($this->alternativeQueries($query, $context) as $alternativeQuery) {
            $lines[] = '- ' . $this->t('Possible alternative search: %s', [$alternativeQuery]);
        }

        return implode("\n", $lines);
    }

    private function searchStrategy(array $context): string {
        $lines = [];
        $parts = [];
        foreach (is_array($context['required_literals'] ?? null) ? $context['required_literals'] : [] as $literal) {
            $literal = trim((string)$literal);
            if ($literal !== '') {
                $parts[] = '"' . $literal . '"';
            }
        }

        $searchMode = (string)($context['search_mode'] ?? 'search_spec');
        $directQueries = array_values(array_filter(array_map(
            static function ($query): string { return trim((string)$query); },
            is_array($context['direct_queries'] ?? null) ? $context['direct_queries'] : []
        ), static function (string $query): bool { return $query !== ''; }));

        if ($searchMode === 'direct_lexical' && $directQueries !== []) {
            $parts[] = count($directQueries) === 1
                ? $directQueries[0]
                : '(' . implode(' | ', $directQueries) . ')';
        } else {
            foreach (($context['entities'] ?? []) as $entity) {
                $entity = trim((string)$entity);
                if ($entity !== '') {
                    $parts[] = $entity;
                }
            }
            foreach (($context['concept_groups'] ?? []) as $group) {
                if (!is_array($group)) {
                    continue;
                }
                $terms = array_values(array_filter(array_map(
                    static function ($term): string { return trim((string)$term); },
                    is_array($group['terms'] ?? null) ? $group['terms'] : []
                ), static function (string $term): bool { return $term !== ''; }));
                if ($terms !== []) {
                    $parts[] = '(' . implode(' | ', $terms) . ')';
                }
            }
        }

        if ($parts !== []) {
            $lines[] = '- ' . $this->t('Search terms used: %s', [implode(' + ', $parts)]);
        }

        $requirements = array_values(array_filter(array_map(
            static function ($item): string { return trim((string)$item); },
            is_array($context['verification_requirements'] ?? null)
                ? $context['verification_requirements']
                : []
        ), static function (string $item): bool { return $item !== ''; }));
        if ($requirements !== []) {
            $lines[] = '- ' . $this->t('Evidence was considered sufficient when:');
            foreach (array_slice($requirements, 0, 4) as $requirement) {
                $lines[] = '  - ' . $requirement;
            }
        }

        return implode("\n", $lines);
    }

    private function alternativeQueries(string $originalQuery, array $context): array {
        $semantic = mb_strtolower(trim((string)($context['semantic_query'] ?? '')), 'UTF-8');
        $requiredLiterals = array_values(array_filter(array_map(
            static function ($value): string { return trim((string)$value); },
            is_array($context['required_literals'] ?? null) ? $context['required_literals'] : []
        ), static function (string $value): bool { return $value !== ''; }));

        $queries = [];
        $seen = [];
        $add = static function (string $query) use (&$queries, &$seen): void {
            $query = trim($query);
            $folded = mb_strtolower($query, 'UTF-8');
            if ($query === '' || isset($seen[$folded])) {
                return;
            }
            $seen[$folded] = true;
            $queries[] = $query;
        };

        foreach (is_array($context['term_interpretations'] ?? null)
            ? $context['term_interpretations']
            : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $original = trim((string)($item['original'] ?? ''));
            $status = (string)($item['status'] ?? '');
            $alternatives = array_values(array_filter(array_map(
                static function ($value): string { return trim((string)$value); },
                is_array($item['alternatives'] ?? null) ? $item['alternatives'] : []
            ), static function (string $value): bool { return $value !== ''; }));
            if ($original === '' || $alternatives === []) {
                continue;
            }

            $isRequiredLiteral = false;
            foreach ($requiredLiterals as $literal) {
                if (mb_strtolower($literal, 'UTF-8') === mb_strtolower($original, 'UTF-8')) {
                    $isRequiredLiteral = true;
                    break;
                }
            }

            if ($isRequiredLiteral) {
                $alternativeQuery = $this->replaceQuotedTerm(
                    $originalQuery,
                    $original,
                    $alternatives[0]
                );
                if ($alternativeQuery !== null) {
                    $add($alternativeQuery);
                }
                continue;
            }

            if (in_array($status, ['probable_typo', 'normalization'], true)) {
                $literalQuery = $this->quoteOriginalTerm($originalQuery, $original);
                if ($literalQuery !== null) {
                    $add($literalQuery);
                }
            }
        }

        foreach (is_array($context['alternative_interpretations'] ?? null)
            ? $context['alternative_interpretations']
            : [] as $alternative) {
            if (!is_array($alternative)) {
                continue;
            }
            $query = trim((string)($alternative['query'] ?? ''));
            $folded = mb_strtolower($query, 'UTF-8');
            if ($query === '' || $folded === $semantic) {
                continue;
            }
            $add($query);
        }

        return array_slice($queries, 0, 3);
    }

    private function quoteOriginalTerm(string $query, string $original): ?string {
        $position = mb_strpos($query, $original, 0, 'UTF-8');
        if ($position === false) {
            return null;
        }

        $length = mb_strlen($original, 'UTF-8');
        $before = $position > 0 ? mb_substr($query, $position - 1, 1, 'UTF-8') : '';
        $afterPosition = $position + $length;
        $after = $afterPosition < mb_strlen($query, 'UTF-8')
            ? mb_substr($query, $afterPosition, 1, 'UTF-8')
            : '';
        if ($before === '"' && $after === '"') {
            return null;
        }

        return mb_substr($query, 0, $position, 'UTF-8')
            . '"' . $original . '"'
            . mb_substr($query, $afterPosition, null, 'UTF-8');
    }

    private function replaceQuotedTerm(string $query, string $original, string $alternative): ?string {
        $needle = '"' . $original . '"';
        $position = mb_strpos($query, $needle, 0, 'UTF-8');
        if ($position === false) {
            return null;
        }

        return mb_substr($query, 0, $position, 'UTF-8')
            . $alternative
            . mb_substr(
                $query,
                $position + mb_strlen($needle, 'UTF-8'),
                null,
                'UTF-8'
            );
    }

    private function termInterpretationNotice(array $context): string {
        $messages = [];
        foreach (is_array($context['term_interpretations'] ?? null) ? $context['term_interpretations'] : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $original = trim((string)($item['original'] ?? ''));
            $status = (string)($item['status'] ?? '');
            $alternatives = array_values(array_filter(array_map(
                static function ($value): string { return trim((string)$value); },
                is_array($item['alternatives'] ?? null) ? $item['alternatives'] : []
            ), static function (string $value): bool { return $value !== ''; }));
            if ($original === '') {
                continue;
            }

            if ($status === 'probable_typo' && count($alternatives) === 1) {
                $messages[] = $this->t(
                    '“%s” was recognized as a possible typo; “%s” was also considered.',
                    [$original, $alternatives[0]]
                );
            } elseif ($status === 'normalization' && $alternatives !== []) {
                $messages[] = $this->t(
                    '“%s” was understood as a spelling or naming variant; also considered: “%s”.',
                    [$original, implode('”, “', $alternatives)]
                );
            } elseif ($status === 'preserved') {
                $suffix = $alternatives !== []
                    ? ' ' . $this->t(
                        'A possible alternative form “%s” was recognized but not used automatically as a correction.',
                        [implode('”, “', $alternatives)]
                    )
                    : '';
                $messages[] = $this->t(
                    '“%s” was preserved in its original form as requested by the user.',
                    [$original]
                ) . $suffix;
            } elseif ($status === 'ambiguous') {
                $suffix = $alternatives !== []
                    ? ' ' . $this->t('Possible readings: “%s”.', [implode('”, “', $alternatives)])
                    : '';
                $messages[] = $this->t('“%s” was not interpreted unambiguously.', [$original]) . $suffix;
            } elseif ($status === 'unrecognized') {
                $suffix = $alternatives !== []
                    ? ' ' . $this->t('Possible readings: “%s”.', [implode('”, “', $alternatives)])
                    : '';
                $messages[] = $this->t('“%s” was not reliably recognized as a term.', [$original]) . $suffix;
            }
        }

        return $messages !== []
            ? $this->t('Search note: %s', [implode(' ', $messages)])
            : '';
    }

    private function normalizeCitations(string $answer, int $sourceCount): array {
        $cited = [];
        $normalized = preg_replace_callback('/\[([0-9]{1,3})\]/', function (array $match) use ($sourceCount, &$cited): string {
            $index = (int)$match[1];
            if ($index < 1 || $index > $sourceCount) {
                return '';
            }
            if (!in_array($index, $cited, true)) {
                $cited[] = $index;
            }
            return '[' . $index . ']';
        }, $answer);

        sort($cited, SORT_NUMERIC);
        return [is_string($normalized) ? trim($normalized) : trim($answer), $cited];
    }
}
