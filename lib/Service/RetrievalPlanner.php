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
        $appliedConstraints = $this->safeStructuredConstraints($spec);
        $hardConstraints = array_values(array_map(
            static function (array $constraint): string { return (string)$constraint['value']; },
            $appliedConstraints
        ));
        if ($originalQuery !== '') {
            $safe = $this->hardConstraints->extract($originalQuery);
            $constraintKinds = [
                'filenames' => 'filename',
                'identifiers' => 'identifier',
                'dates' => 'date',
                'years' => 'year',
            ];
            foreach ($constraintKinds as $sourceKind => $appliedKind) {
                foreach (($safe[$sourceKind] ?? []) as $constraint) {
                    $constraint = trim((string)$constraint);
                    if ($constraint !== '' && !in_array($constraint, $hardConstraints, true)) {
                        $hardConstraints[] = $constraint;
                        $appliedConstraints[] = [
                            'kind' => $appliedKind,
                            'value' => $constraint,
                        ];
                    }
                }
            }
        }

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
                'hard_constraints' => $hardConstraints,
                'applied_constraints' => $appliedConstraints,
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
                'hard_constraints' => $hardConstraints,
                'applied_constraints' => $appliedConstraints,
                'retrieval_arms' => ['files'],
                'max_rounds' => 1,
            ];
        }

        $primaryLexicalGroups = [];
        $expandedLexicalGroups = [];
        $primarySeenGroups = [];
        $expandedSeenGroups = [];
        $hasExpansion = false;

        $addGroup = static function (
            array &$target,
            array &$seenGroups,
            bool $required,
            array $terms,
            string $kind
        ): void {
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
            $kindKey = strncmp($kind, 'constraint:', 11) === 0 ? $kind : 'lexical';
            $key = ($required ? '1:' : '0:') . $kindKey . ':' . implode("\x1f", array_map(
                static function (string $term): string { return mb_strtolower($term, 'UTF-8'); },
                $cleaned
            ));
            if (isset($seenGroups[$key])) {
                return;
            }
            $seenGroups[$key] = true;
            $target[] = [
                'required' => $required,
                'kind' => $kind,
                'terms' => $cleaned,
            ];
        };

        foreach (($spec['entities'] ?? []) as $entity) {
            $text = trim((string)$entity);
            if ($text === '') {
                continue;
            }
            $addGroup($primaryLexicalGroups, $primarySeenGroups, true, [$text], 'entity');
            $addGroup($expandedLexicalGroups, $expandedSeenGroups, true, [$text], 'entity');
        }

        foreach (($spec['concept_groups'] ?? []) as $group) {
            if (!is_array($group)) {
                continue;
            }

            $required = (bool)($group['required'] ?? false);
            $primaryTerms = is_array($group['primary_terms'] ?? null) ? $group['primary_terms'] : [];
            $expansionTerms = is_array($group['expansion_terms'] ?? null) ? $group['expansion_terms'] : [];
            $allTerms = is_array($group['terms'] ?? null) ? $group['terms'] : [];

            if ($primaryTerms === [] && $allTerms !== []) {
                $primaryTerms = [(string)$allTerms[0]];
                $expansionTerms = array_slice($allTerms, 1);
            }

            $addGroup($primaryLexicalGroups, $primarySeenGroups, $required, $primaryTerms, 'concept:primary');
            $addGroup($expandedLexicalGroups, $expandedSeenGroups, $required, $allTerms, 'concept:expanded');

            if ($expansionTerms !== []) {
                $hasExpansion = true;
            }
        }

        foreach ($appliedConstraints as $constraint) {
            $value = (string)($constraint['value'] ?? '');
            $kind = 'constraint:' . (string)($constraint['kind'] ?? 'literal');
            $addGroup($primaryLexicalGroups, $primarySeenGroups, true, [$value], $kind);
            $addGroup($expandedLexicalGroups, $expandedSeenGroups, true, [$value], $kind);
        }

        $lexicalGroups = $expandedLexicalGroups;

        $structuredIntent = $lexicalGroups !== [];

        return [
            'search_mode' => 'search_spec',
            'semantic_query' => trim((string)($spec['semantic_query'] ?? '')),
            'elastic_query' => $structuredIntent ? '' : trim((string)($spec['elastic_query'] ?? '')),
            'lexical_groups' => $lexicalGroups,
            'primary_lexical_groups' => $primaryLexicalGroups,
            'expanded_lexical_groups' => $expandedLexicalGroups,
            'has_expansion' => $hasExpansion,
            'must' => $this->texts($spec['must'] ?? []),
            'should' => $this->texts($spec['should'] ?? []),
            'must_not' => $this->texts($spec['must_not'] ?? []),
            'phrases' => $this->texts($spec['phrases'] ?? []),
            'must_not_phrases' => $this->texts($spec['must_not_phrases'] ?? []),
            'required_literals' => $requiredLiterals,
            'hard_constraints' => [],
            'applied_constraints' => $appliedConstraints,
            'retrieval_arms' => ['files'],
            'max_rounds' => $hasExpansion ? 2 : 1,
        ];
    }

    private function safeStructuredConstraints(array $spec): array {
        $out = [];
        $seen = [];

        foreach (is_array($spec['constraints'] ?? null) ? $spec['constraints'] : [] as $constraint) {
            if (!is_array($constraint)) {
                continue;
            }

            $kind = mb_strtolower(trim((string)($constraint['kind'] ?? '')), 'UTF-8');
            $value = trim((string)($constraint['value'] ?? ''));
            if ($kind === '' || $value === '') {
                continue;
            }

            if ($kind === 'year') {
                if (preg_match('/^(?:18|19|20|21)\d{2}$/', $value) !== 1) {
                    continue;
                }
            } elseif ($kind === 'date') {
                if (!$this->isValidDateConstraint($value)) {
                    continue;
                }
            } else {
                continue;
            }

            $key = $kind . "\x1f" . mb_strtolower($value, 'UTF-8');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [
                'kind' => $kind,
                'value' => $value,
            ];
        }

        return $out;
    }

    private function isValidDateConstraint(string $value): bool {
        $day = 0;
        $month = 0;
        $year = 0;

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $match) === 1) {
            $year = (int)$match[1];
            $month = (int)$match[2];
            $day = (int)$match[3];
        } elseif (preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{2,4})$/u', $value, $match) === 1) {
            $day = (int)$match[1];
            $month = (int)$match[2];
            $year = (int)$match[3];
        } else {
            return false;
        }

        return checkdate($month, $day, $year);
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
