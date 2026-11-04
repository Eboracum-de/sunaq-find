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
        $directive = $this->parseDirective($query);

        if ($directive['mode'] === 'help') {
            return $this->helpResult();
        }

        $listMode = $directive['mode'] === 'list';
        $query = (string)$directive['query'];
        if ($query === '' || mb_strlen($query, 'UTF-8') > 1200) {
            if ($listMode) {
                throw new \InvalidArgumentException($this->t('Use /list followed by a search request.'));
            }
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
        $retrievalLimit = $this->config->getSearchDocumentLimit();
        $retrieval = $this->retrieval->retrieve(
            $plan,
            $query,
            $retrievalLimit
        );
        $retrieval['hits'] = $this->deduplicateHits(
            is_array($retrieval['hits'] ?? null) ? $retrieval['hits'] : []
        );
        $evidence = $listMode
            ? [
                'json' => '',
                'documents' => [],
                'sources' => $this->listSources($retrieval['hits']),
            ]
            : $this->evidenceBuilder->build(
                $retrieval['hits'],
                $this->config->getLlmDocumentLimit()
            );
        $retrievedForEvaluation = count($retrieval['hits']);
        $evaluatedDocuments = $listMode ? 0 : count($evidence['documents']);
        $evaluationLimitNotice = $listMode
            ? ''
            : $this->evaluationLimitNotice(
                $retrievedForEvaluation,
                $evaluatedDocuments
            );
        $totalMatches = isset($retrieval['total_matches']) && is_numeric($retrieval['total_matches'])
            ? max(0, (int)$retrieval['total_matches'])
            : null;
        $retrievalLimitNotice = $this->retrievalLimitNotice(
            $totalMatches,
            $retrievalLimit,
            (bool)($retrieval['acl_prefilter_applied'] ?? false)
        );
        $limitNotice = $retrievalLimitNotice !== ''
            ? $retrievalLimitNotice
            : $evaluationLimitNotice;

        $searchContext = $this->searchContext($rewrite['spec']);
        $searchContext['required_literals'] = is_array($plan['required_literals'] ?? null)
            ? $plan['required_literals']
            : [];
        $searchContext['hard_constraints'] = is_array($plan['hard_constraints'] ?? null)
            ? $plan['hard_constraints']
            : [];
        $searchContext['applied_constraints'] = is_array($plan['applied_constraints'] ?? null)
            ? $plan['applied_constraints']
            : [];
        $answerContext = $searchContext;
        unset($answerContext['alternative_interpretations']);
        $searchContext['search_mode'] = (string)($plan['search_mode'] ?? 'search_spec');
        $searchContext['direct_queries'] = is_array($plan['direct_queries'] ?? null)
            ? $plan['direct_queries']
            : [];
        $searchContext['primary_lexical_groups'] = is_array($plan['primary_lexical_groups'] ?? null)
            ? $plan['primary_lexical_groups']
            : [];
        $searchContext['expanded_lexical_groups'] = is_array($plan['expanded_lexical_groups'] ?? null)
            ? $plan['expanded_lexical_groups']
            : [];
        $searchContext['retrieval_stages'] = is_array($retrieval['retrieval_stages'] ?? null)
            ? $retrieval['retrieval_stages']
            : [];
        $searchStrategy = $this->config->showSearchStrategy()
            ? $this->searchStrategy($searchContext)
            : '';
        $answerError = false;
        $answerErrorDetail = '';
        $answerFound = false;
        $answerCitationExplanations = [];
        $answerEntityInterpretations = [];
        if ($listMode) {
            $listed = count($evidence['sources']);
            $answerFound = $listed > 0;
            $answer = $listed > 0
                ? $this->t('Found %s documents.', [(string)$listed])
                : $this->t('No matching documents were found.');
            if ($retrievalLimitNotice !== '') {
                $answer .= "\n\n" . $retrievalLimitNotice;
            }
        } elseif ($evidence['documents'] === []) {
            $answer = $this->t('The current search interpretation did not find sufficient evidence.')
                . "\n\n" . $this->searchGuidance($query, $searchContext);
        } else {
            try {
                $assessment = $this->answerer->answerWithAssessment($query, $evidence['json'], $answerContext);
                $answerFound = (bool)$assessment['answer_found'];
                $answer = (string)$assessment['answer'];
                $answerCitationExplanations = $this->filterCitationExplanations(
                    is_array($assessment['citation_explanations'] ?? null)
                        ? $assessment['citation_explanations']
                        : [],
                    is_array($evidence['documents'] ?? null)
                        ? $evidence['documents']
                        : []
                );
                $answerEntityInterpretations = is_array($assessment['entity_interpretations'] ?? null)
                    ? $assessment['entity_interpretations']
                    : [];
                if ($limitNotice !== '') {
                    $answer .= "\n\n" . $limitNotice;
                }
                $answer .= "\n\n" . $this->searchGuidance($query, $searchContext);
                if (!$answerFound) {
                    $answer = $this->stripEvidenceMarkers($answer);
                    $evidence['sources'] = [];
                } else {
                    [$answer, $cited] = $this->normalizeEvidenceCitations($answer, $evidence['sources']);
                    if ($cited === []) {
                        $answerFound = false;
                        $answerCitationExplanations = [];
                        $answer = $this->t(
                            'The generated answer could not be verified against the retrieved evidence.'
                        );
                        if ($limitNotice !== '') {
                            $answer .= "\n\n" . $limitNotice;
                        }
                        $answer .= "\n\n" . $this->searchGuidance($query, $searchContext);
                        $evidence['sources'] = [];
                    } else {
                        $evidence['sources'] = array_values(array_filter(
                            $evidence['sources'],
                            static function (array $source) use ($cited): bool { return in_array((int)$source['index'], $cited, true); }
                        ));
                    }
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
            'mode' => $listMode ? 'list' : 'answer',
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
                'original_request' => $query,
                'rewrite_status' => (string)$rewrite['status'],
                'search_spec' => $rewrite['spec'],
                'request_partition' => $this->requestPartition($rewrite['spec'], $plan),
                'fallback_used' => (bool)$rewrite['fallback_used'],
                'retrieval_languages' => $this->config->getRetrievalLanguages(),
                'planner_reasoning' => (string)($rewrite['reasoning_mode'] ?? 'current'),
                'answer_citation_explanations' => $answerCitationExplanations,
                'answer_entity_interpretations' => $answerEntityInterpretations,
                'retrieval_plan' => [
                    'search_mode' => $plan['search_mode'],
                    'retrieval_arms' => $plan['retrieval_arms'],
                    'max_rounds' => $plan['max_rounds'],
                    'required_literals' => is_array($plan['required_literals'] ?? null)
                        ? $plan['required_literals']
                        : [],
                    'hard_constraints' => is_array($plan['hard_constraints'] ?? null)
                        ? $plan['hard_constraints']
                        : [],
                    'applied_constraints' => is_array($plan['applied_constraints'] ?? null)
                        ? $plan['applied_constraints']
                        : [],
                    'lexical_groups' => is_array($plan['lexical_groups'] ?? null)
                        ? $plan['lexical_groups']
                        : [],
                    'primary_lexical_groups' => is_array($plan['primary_lexical_groups'] ?? null)
                        ? $plan['primary_lexical_groups']
                        : [],
                    'expanded_lexical_groups' => is_array($plan['expanded_lexical_groups'] ?? null)
                        ? $plan['expanded_lexical_groups']
                        : [],
                    'has_expansion' => (bool)($plan['has_expansion'] ?? false),
                    'arm_capabilities' => $this->retrieval->capabilities(),
                ],
            ];
            $result['answer_evidence'] = $this->analysisEvidence(
                is_array($evidence['documents'] ?? null) ? $evidence['documents'] : []
            );
            $result['retrieval'] = [
                'candidate_count' => $retrieval['candidate_count'],
                'authorized_count' => $retrieval['allowed_count'],
                'total_matches' => $totalMatches,
                'total_matches_relation' => (string)($retrieval['total_matches_relation'] ?? ''),
                'retrieval_limit' => $retrievalLimit,
                'retrieval_truncated' => $retrievalLimitNotice !== '',
                'retrieved_for_evaluation' => $retrievedForEvaluation,
                'evaluated_document_count' => $evaluatedDocuments,
                'evaluation_truncated' => $evaluationLimitNotice !== '',
                'denied_count' => $retrieval['denied_count'],
                'unresolved_count' => $retrieval['unresolved_count'],
                'acl_prefilter_status' => (string)($retrieval['acl_prefilter_status'] ?? 'unknown'),
                'acl_prefilter_applied' => (bool)($retrieval['acl_prefilter_applied'] ?? false),
                'acl_prefilter_fallback' => (bool)($retrieval['acl_prefilter_fallback'] ?? false),
                'acl_prefilter_reason' => (string)($retrieval['acl_prefilter_reason'] ?? ''),
                'stages' => is_array($retrieval['retrieval_stages'] ?? null)
                    ? $retrieval['retrieval_stages']
                    : [],
                'authorized_hits' => $this->analysisRetrievalHits(
                    is_array($retrieval['hits'] ?? null) ? $retrieval['hits'] : []
                ),
                'unresolved_breakdown' => [
                    'missing_file_id' => (int)($retrieval['unresolved_missing_file_id'] ?? 0),
                    'not_found' => (int)($retrieval['unresolved_not_found'] ?? 0),
                    'resolution_failed' => (int)($retrieval['unresolved_resolution_failed'] ?? 0),
                ],
            ];
        }

        return $result;
    }

    private function parseDirective(string $query): array {
        $query = trim($query);

        if (strcasecmp($query, '/help') === 0) {
            return ['mode' => 'help', 'query' => ''];
        }

        if (preg_match('/^\/list(?:\s+(.*))?$/isu', $query, $matches) === 1) {
            return [
                'mode' => 'list',
                'query' => trim((string)($matches[1] ?? '')),
            ];
        }

        return ['mode' => 'answer', 'query' => $query];
    }

    private function helpResult(): array {
        return [
            'mode' => 'help',
            'analysis_enabled' => false,
            'answer' => $this->helpText(),
            'answer_error' => false,
            'answer_found' => true,
            'search_strategy' => '',
            'sources' => [],
        ];
    }

    private function helpText(): string {
        $path = dirname(__DIR__, 2) . '/docs/HELP.md';
        $content = @file_get_contents($path);
        if (!is_string($content) || trim($content) === '') {
            return '# ' . $this->t('Help');
        }

        $lines = preg_split('/\R/u', trim($content));
        if (!is_array($lines)) {
            return trim($content);
        }

        foreach ($lines as &$line) {
            if (preg_match('/^(#{1,6}\s+)(.+)$/u', $line, $matches) === 1) {
                $line = (string)$matches[1] . $this->t((string)$matches[2]);
            } elseif (trim($line) !== '') {
                $line = $this->t($line);
            }
        }
        unset($line);

        return implode("\n", $lines);
    }

    private function listSources(array $hits): array {
        $sources = [];
        foreach ($hits as $hit) {
            if (!is_array($hit) || ($hit['authorized'] ?? false) !== true) {
                continue;
            }

            $fileId = (int)($hit['file_id'] ?? 0);
            $name = trim((string)($hit['name'] ?? ''));
            if ($fileId <= 0 || $name === '') {
                continue;
            }

            $sources[] = [
                'index' => count($sources) + 1,
                'evidence_id' => 'file-' . $fileId,
                'file_id' => $fileId,
                'name' => mb_substr($name, 0, 300, 'UTF-8'),
                'path' => mb_substr((string)($hit['path'] ?? ''), 0, 700, 'UTF-8'),
                'url' => (string)($hit['url'] ?? ''),
                'snippet' => mb_substr((string)($hit['snippet'] ?? ''), 0, 360, 'UTF-8'),
            ];
        }

        return $sources;
    }

    private function analysisRetrievalHits(array $hits): array {
        $out = [];
        foreach ($hits as $hit) {
            if (!is_array($hit)) {
                continue;
            }
            $out[] = [
                'file_id' => (int)($hit['file_id'] ?? 0),
                'name' => mb_substr((string)($hit['name'] ?? ''), 0, 300, 'UTF-8'),
                'path' => mb_substr((string)($hit['path'] ?? ''), 0, 700, 'UTF-8'),
                'score' => isset($hit['score']) && is_numeric($hit['score'])
                    ? (float)$hit['score']
                    : null,
                'retrieval_stage' => mb_substr((string)($hit['retrieval_stage'] ?? ''), 0, 32, 'UTF-8'),
                'snippet' => mb_substr((string)($hit['snippet'] ?? ''), 0, 360, 'UTF-8'),
            ];
        }
        return $out;
    }

    private function analysisEvidence(array $documents): array {
        $out = [];
        foreach ($documents as $record) {
            if (!is_array($record)) {
                continue;
            }

            $index = (int)($record['source_index'] ?? 0);
            $evidenceId = trim((string)($record['evidence_id'] ?? ''));

            $out[] = [
                'index' => $index,
                'citation' => $index > 0 ? '[' . $index . ']' : '',
                'evidence_id' => $evidenceId,
                'file_id' => (int)($record['file_id'] ?? 0),
                'name' => mb_substr((string)($record['name'] ?? ''), 0, 300, 'UTF-8'),
                'path' => mb_substr((string)($record['path'] ?? ''), 0, 700, 'UTF-8'),
                'snippet' => mb_substr((string)($record['snippet'] ?? ''), 0, 360, 'UTF-8'),
            ];
        }
        return $out;
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

    private function requestPartition(array $spec, array $plan): array {
        return [
            'information_interest' => [
                'semantic_query' => trim((string)($spec['semantic_query'] ?? '')),
                'entities' => is_array($spec['entities'] ?? null) ? $spec['entities'] : [],
                'concepts' => is_array($spec['concepts'] ?? null) ? $spec['concepts'] : [],
                'concept_groups' => is_array($spec['concept_groups'] ?? null) ? $spec['concept_groups'] : [],
                'constraints' => is_array($spec['constraints'] ?? null) ? $spec['constraints'] : [],
            ],
            'search_specifications' => [
                'term_interpretations' => is_array($spec['term_interpretations'] ?? null)
                    ? $spec['term_interpretations']
                    : [],
                'required_literals' => is_array($plan['required_literals'] ?? null)
                    ? $plan['required_literals']
                    : [],
            ],
            'answer_requirements' => is_array($spec['answer_requirements'] ?? null)
                ? $spec['answer_requirements']
                : [],
        ];
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
            'answer_requirements' => is_array($spec['answer_requirements'] ?? null)
                ? $spec['answer_requirements']
                : [],
        ];
    }

    private function retrievalLimitNotice(?int $totalMatches, int $retrievalLimit, bool $aclPrefilterApplied): string {
        if (
            !$aclPrefilterApplied
            || $totalMatches === null
            || $retrievalLimit <= 0
            || $totalMatches <= $retrievalLimit
        ) {
            return '';
        }

        $minimumForNotice = max(
            $retrievalLimit + 5,
            (int)ceil($retrievalLimit * 1.5)
        );
        if ($totalMatches < $minimumForNotice) {
            return '';
        }

        return '**' . $this->t('Note:') . '** '
            . $this->t(
                'This search found substantially more matching documents than can be considered in one run. If possible, describe the information you are looking for more precisely to narrow the result set.'
            );
    }

    private function evaluationLimitNotice(int $retrieved, int $evaluated): string {
        if ($evaluated <= 0 || $retrieved <= $evaluated) {
            return '';
        }

        $minimumForNotice = max(
            $evaluated + 2,
            (int)ceil($evaluated * 1.25)
        );
        if ($retrieved < $minimumForNotice) {
            return '';
        }

        return '**' . $this->t('Note:') . '** '
            . $this->t(
                'Evaluation limits prevented all retrieved documents from being considered. If possible, describe the information you are looking for more precisely to narrow the result set.'
            );
    }

    private function searchGuidance(string $query, array $context): string {
        $lines = ['**' . $this->t('Searched for:') . '**'];

        $requiredLiterals = array_values(array_filter(array_map(
            static function ($value): string { return trim((string)$value); },
            is_array($context['required_literals'] ?? null) ? $context['required_literals'] : []
        ), static function (string $value): bool { return $value !== ''; }));

        $semantic = trim((string)($context['semantic_query'] ?? ''));
        if ($requiredLiterals !== []) {
            $lines[] = '- ' . $this->t('Search request with literal wording preserved: %s', [$query]);
        } elseif ($semantic !== '') {
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
        $searchMode = (string)($context['search_mode'] ?? 'search_spec');

        if ($searchMode === 'direct_lexical') {
            $directQueries = array_values(array_filter(array_map(
                static function ($query): string { return trim((string)$query); },
                is_array($context['direct_queries'] ?? null) ? $context['direct_queries'] : []
            ), static function (string $query): bool { return $query !== ''; }));
            if ($directQueries !== []) {
                $lines[] = '- ' . $this->t(
                    'Primary search: %s',
                    [count($directQueries) === 1 ? $directQueries[0] : '(' . implode(' | ', $directQueries) . ')']
                );
            }
        } elseif ($searchMode === 'search_spec') {
            $requiredLiterals = is_array($context['required_literals'] ?? null)
                ? $context['required_literals']
                : [];
            $primary = $this->strategyExpression(
                is_array($context['primary_lexical_groups'] ?? null)
                    ? $context['primary_lexical_groups']
                    : [],
                $requiredLiterals
            );
            $expanded = $this->strategyExpression(
                is_array($context['expanded_lexical_groups'] ?? null)
                    ? $context['expanded_lexical_groups']
                    : [],
                $requiredLiterals
            );

            if ($primary !== '') {
                $lines[] = '- ' . $this->t('Primary search: %s', [$primary]);
            }
            if ($expanded !== '' && $expanded !== $primary) {
                $lines[] = '- ' . $this->t('Expanded search: %s', [$expanded]);

                $expandedStage = null;
                foreach (is_array($context['retrieval_stages'] ?? null) ? $context['retrieval_stages'] : [] as $stage) {
                    if (is_array($stage) && (string)($stage['stage'] ?? '') === 'expanded') {
                        $expandedStage = $stage;
                        break;
                    }
                }
                if (is_array($expandedStage)) {
                    if (!empty($expandedStage['executed'])) {
                        $lines[] = '- ' . $this->t(
                            'Expansion status: executed because the primary search did not fill the retrieval target; %s additional authorized documents were added.',
                            [(string)max(0, (int)($expandedStage['authorized_added'] ?? 0))]
                        );
                    } elseif ((string)($expandedStage['reason'] ?? '') === 'primary_filled_target') {
                        $lines[] = '- ' . $this->t('Expansion status: not needed because the primary search filled the retrieval target.');
                    } elseif ((string)($expandedStage['reason'] ?? '') === 'expanded_search_failed') {
                        $lines[] = '- ' . $this->t('Expansion status: the optional expansion search failed; primary results were retained.');
                    }
                }
            }
        } else {
            $parts = [];
            foreach (is_array($context['required_literals'] ?? null) ? $context['required_literals'] : [] as $literal) {
                $literal = trim((string)$literal);
                if ($literal !== '') {
                    $parts[] = '"' . $literal . '"';
                }
            }
            foreach (($context['entities'] ?? []) as $entity) {
                $entity = trim((string)$entity);
                if ($entity !== '') {
                    $parts[] = $entity;
                }
            }
            if ($parts !== []) {
                $lines[] = '- ' . $this->t('Search terms used: %s', [implode(' + ', $parts)]);
            }
        }

        $constraintParts = [];
        foreach (is_array($context['applied_constraints'] ?? null) ? $context['applied_constraints'] : [] as $constraint) {
            if (!is_array($constraint)) {
                continue;
            }
            $kind = mb_strtolower(trim((string)($constraint['kind'] ?? '')), 'UTF-8');
            $value = trim((string)($constraint['value'] ?? ''));
            if ($value === '') {
                continue;
            }

            if ($kind === 'year') {
                $constraintParts[] = $this->t('year %s', [$value]);
            } elseif ($kind === 'date') {
                $constraintParts[] = $this->t('date %s', [$value]);
            } else {
                $constraintParts[] = '"' . $value . '"';
            }
        }
        if ($constraintParts !== []) {
            $lines[] = '- ' . $this->t('Search constraints used: %s', [implode(' + ', $constraintParts)]);
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

    private function strategyExpression(array $groups, array $requiredLiterals = []): string {
        $requiredParts = [];
        $optionalParts = [];
        $seen = [];
        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }
            $terms = array_values(array_filter(array_map(
                static function ($term): string { return trim((string)$term); },
                is_array($group['terms'] ?? null) ? $group['terms'] : []
            ), static function (string $term): bool { return $term !== ''; }));
            if ($terms === []) {
                continue;
            }
            $part = count($terms) === 1 ? $terms[0] : '(' . implode(' | ', $terms) . ')';
            $folded = mb_strtolower($part, 'UTF-8');
            if (isset($seen[$folded])) {
                continue;
            }
            $seen[$folded] = true;
            if (!empty($group['required'])) {
                $requiredParts[] = $part;
            } else {
                $optionalParts[] = $part;
            }
        }
        foreach ($requiredLiterals as $literal) {
            $literal = trim((string)$literal);
            if ($literal === '') {
                continue;
            }
            $part = '"' . $literal . '"';
            $folded = mb_strtolower($part, 'UTF-8');
            if (!isset($seen[$folded])) {
                $seen[$folded] = true;
                $requiredParts[] = $part;
            }
        }

        $segments = [];
        if ($requiredParts !== []) {
            $segments[] = implode(' + ', $requiredParts);
        }
        if ($optionalParts !== []) {
            $segments[] = $this->t('optional: %s', [implode(' | ', $optionalParts)]);
        }
        return implode('; ', $segments);
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

            $isRequiredLiteral = false;
            foreach (is_array($context['required_literals'] ?? null) ? $context['required_literals'] : [] as $literal) {
                if (mb_strtolower(trim((string)$literal), 'UTF-8') === mb_strtolower($original, 'UTF-8')) {
                    $isRequiredLiteral = true;
                    break;
                }
            }

            if ($isRequiredLiteral) {
                $suffix = $alternatives !== []
                    ? ' ' . $this->t(
                        'A possible alternative form “%s” was recognized but the quoted original remained mandatory.',
                        [implode('”, “', $alternatives)]
                    )
                    : '';
                $messages[] = $this->t(
                    'The quoted term “%s” was searched literally as requested.',
                    [$original]
                ) . $suffix;
            } elseif ($status === 'probable_typo' && count($alternatives) === 1) {
                $messages[] = $this->t(
                    '“%s” was recognized as a possible typo; also considered: “%s”.',
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

    private function filterCitationExplanations(array $items, array $documents): array {
        $known = [];
        foreach ($documents as $document) {
            if (!is_array($document)) {
                continue;
            }
            $evidenceId = trim((string)($document['evidence_id'] ?? ''));
            if ($evidenceId !== '') {
                $known[$evidenceId] = true;
            }
        }

        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $evidenceId = trim((string)($item['evidence_id'] ?? ''));
            if ($evidenceId !== '' && isset($known[$evidenceId])) {
                $out[] = $item;
            }
        }
        return $out;
    }

    private function stripEvidenceMarkers(string $answer): string {
        $stripped = preg_replace(
            '/\\s*\\[\\[([A-Za-z0-9][A-Za-z0-9._:-]{0,127})\\]\\]/',
            '',
            $answer
        );

        return is_string($stripped) ? trim($stripped) : trim($answer);
    }

    private function normalizeEvidenceCitations(string $answer, array $sources): array {
        $evidenceToIndex = [];
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }
            $evidenceId = trim((string)($source['evidence_id'] ?? ''));
            $index = (int)($source['index'] ?? 0);
            if ($evidenceId !== '' && $index > 0) {
                $evidenceToIndex[$evidenceId] = $index;
            }
        }

        $cited = [];
        $normalized = preg_replace_callback(
            '/\[\[([A-Za-z0-9][A-Za-z0-9._:-]{0,127})\]\]/',
            function (array $match) use ($evidenceToIndex, &$cited): string {
                $evidenceId = (string)$match[1];
                if (!isset($evidenceToIndex[$evidenceId])) {
                    return '';
                }
                $index = (int)$evidenceToIndex[$evidenceId];
                if (!in_array($index, $cited, true)) {
                    $cited[] = $index;
                }
                return '[' . $index . ']';
            },
            $answer
        );

        sort($cited, SORT_NUMERIC);
        return [is_string($normalized) ? trim($normalized) : trim($answer), $cited];
    }
}
