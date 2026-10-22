<?php
declare(strict_types=1);

namespace OCA\SunaqFind\Service;

/**
 * Small PHP port of SunaQ v0.8.7-rc1 planner.py::plan_from_search_spec.
 *
 * Only the files arm is active today. The plan retains an arm boundary so
 * vector/graph/resolver/mail/web can be added later without changing SearchSpec.
 */
final class RetrievalPlanner {
    private $hardConstraints;
    public const ARM_HOOKS = ['files', 'vector', 'graph', 'resolver', 'mail', 'web'];

    public function __construct(HardConstraintExtractor $hardConstraints) {
        $this->hardConstraints = $hardConstraints;
    }

    public function planFromSearchSpec(
        array $spec,
        string $originalQuery,
        bool $filesFallback = false,
        bool $directLexicalRouting = false
    ): array {
        $requiredLiterals = $this->quotedLiterals($originalQuery);

        if ($filesFallback) {
            return [
                'search_mode' => 'fallback_original',
                'semantic_query' => trim((string)($spec['semantic_query'] ?? $originalQuery)),
                'elastic_query' => '',
                'lexical_groups' => [],
                'must' => [],
                'should' => [],
                'must_not' => [],
                'phrases' => [],
                'must_not_phrases' => [],
                'required_literals' => $requiredLiterals,
                'retrieval_arms' => ['files'],
                'max_rounds' => 1,
            ];
        }

        if (
            $directLexicalRouting
            && (string)($spec['retrieval_intent'] ?? '') === 'direct_lexical'
            && (string)($spec['lexical_sufficiency'] ?? '') === 'high'
        ) {
            return [
                'search_mode' => 'direct_lexical',
                'semantic_query' => trim((string)($spec['semantic_query'] ?? $originalQuery)),
                'elastic_query' => '',
                'direct_queries' => $this->directQueries($spec, $originalQuery),
                'lexical_groups' => [],
                'must' => [],
                'should' => [],
                'must_not' => [],
                'phrases' => [],
                'must_not_phrases' => [],
                'required_literals' => $requiredLiterals,
                'retrieval_arms' => ['files'],
                'max_rounds' => 1,
            ];
        }

        $lexicalGroups = [];
        $seenGroups = [];

        $addGroup = function (bool $required, array $terms, string $kind) use (&$lexicalGroups, &$seenGroups): void {
            $cleaned = [];
            $seenTerms = [];
            foreach ($terms as $raw) {
                $text = trim((string)$raw);
                $folded = mb_strtolower($text, 'UTF-8');
                if ($text === '' || isset($seenTerms[$folded])) {
                    continue;
                }
                $seenTerms[$folded] = true;
                $cleaned[] = $text;
            }
            if ($cleaned === []) {
                return;
            }
            $key = ($required ? '1:' : '0:') . implode("\x1f", array_map(
                static function (string $term): string { return mb_strtolower($term, 'UTF-8'); },
                $cleaned
            ));
            if (isset($seenGroups[$key])) {
                return;
            }
            $seenGroups[$key] = true;
            $lexicalGroups[] = [
                'required' => $required,
                'kind' => $kind,
                'terms' => $cleaned,
            ];
        };

        foreach (($spec['entities'] ?? []) as $entity) {
            $text = trim((string)$entity);
            if ($text !== '') {
                $addGroup(true, [$text], 'entity');
            }
        }

        foreach (($spec['concept_groups'] ?? []) as $group) {
            if (!is_array($group)) {
                continue;
            }
            $addGroup(
                (bool)($group['required'] ?? false),
                is_array($group['terms'] ?? null) ? $group['terms'] : [],
                'concept'
            );
        }

        if ($originalQuery !== '') {
            $safe = $this->hardConstraints->extract($originalQuery);
            foreach (['filenames', 'identifiers', 'dates', 'years'] as $constraintKind) {
                foreach (($safe[$constraintKind] ?? []) as $constraint) {
                    $addGroup(true, [(string)$constraint], 'constraint:' . $constraintKind);
                }
            }
        }

        $structuredIntent = $lexicalGroups !== [];

        return [
            'search_mode' => 'search_spec',
            'semantic_query' => trim((string)($spec['semantic_query'] ?? '')),
            'elastic_query' => $structuredIntent ? '' : trim((string)($spec['elastic_query'] ?? '')),
            'lexical_groups' => $lexicalGroups,
            'must' => $this->texts($spec['must'] ?? []),
            'should' => $this->texts($spec['should'] ?? []),
            'must_not' => $this->texts($spec['must_not'] ?? []),
            'phrases' => $this->texts($spec['phrases'] ?? []),
            'must_not_phrases' => $this->texts($spec['must_not_phrases'] ?? []),
            'required_literals' => $requiredLiterals,
            'retrieval_arms' => ['files'],
            'max_rounds' => 1,
        ];
    }

    private function quotedLiterals(string $query): array {
        if ($query === '') {
            return [];
        }

        preg_match_all('/(?<!\\\\)"([^"\\r\\n]{1,300})(?<!\\\\)"/u', $query, $matches);
        $out = [];
        $seen = [];
        foreach (($matches[1] ?? []) as $raw) {
            $term = trim((string)$raw);
            $key = mb_strtolower($term, 'UTF-8');
            if ($term === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $term;
            if (count($out) >= 8) {
                break;
            }
        }
        return $out;
    }

    private function directQueries(array $spec, string $originalQuery): array {
        $lexicalQuery = trim((string)($spec['lexical_query'] ?? ''));
        if ($lexicalQuery === '') {
            $lexicalQuery = trim($originalQuery);
        }
        if ($lexicalQuery === '') {
            return [];
        }

        $queries = [$lexicalQuery];
        $repaired = $lexicalQuery;
        foreach (($spec['term_interpretations'] ?? []) as $item) {
            $status = is_array($item) ? (string)($item['status'] ?? '') : '';
            if (!in_array($status, ['probable_typo', 'normalization'], true)) {
                continue;
            }
            $original = trim((string)($item['original'] ?? ''));
            $alternatives = is_array($item['alternatives'] ?? null) ? $item['alternatives'] : [];
            if ($original === '' || count($alternatives) !== 1) {
                continue;
            }
            $alternative = trim((string)$alternatives[0]);
            if ($alternative === '' || mb_strtolower($alternative, 'UTF-8') === mb_strtolower($original, 'UTF-8')) {
                continue;
            }
            $pattern = '/' . preg_quote($original, '/') . '/iu';
            $next = preg_replace_callback(
                $pattern,
                static function (array $match) use ($alternative): string { return $alternative; },
                $repaired,
                1
            );
            if (is_string($next)) {
                $repaired = $next;
            }
        }

        if ($repaired !== $lexicalQuery) {
            $queries[] = $repaired;
        }
        return $queries;
    }

    private function texts($value): array {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            $text = trim((string)$item);
            if ($text !== '') {
                $out[] = $text;
            }
        }
        return $out;
    }
}
