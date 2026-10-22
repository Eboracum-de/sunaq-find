<?php
declare(strict_types=1);

namespace OCA\SunaqFind\Service;

/**
 * PHP port of SunaQ v0.8.7-rc1 rag/search_spec.py::normalize_search_spec.
 *
 * SearchSpec is a tolerant intent envelope, not a strict "all fields required"
 * LLM schema. Missing or malformed optional fields normalize to safe defaults.
 */
final class SearchSpecNormalizer {
    public function fromJson(string $json, string $originalQuery = ''): array {
        if ($json === '' || strlen($json) > 20000) {
            throw new \InvalidArgumentException('Planner output has an invalid size');
        }

        try {
            $value = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Planner output is not valid JSON');
        }

        return $this->normalize($value, $originalQuery);
    }

    public function normalize($value, string $originalQuery = ''): array {
        $raw = is_array($value) && !$this->isList($value) ? $value : [];

        $semanticQuery = is_string($raw['semantic_query'] ?? null)
            ? $this->cleanText($raw['semantic_query'], 1200)
            : '';
        if ($semanticQuery === '') {
            $semanticQuery = $this->cleanText($originalQuery, 1200);
        }

        $elasticQuery = is_string($raw['elastic_query'] ?? null)
            ? $this->cleanText($raw['elastic_query'], 1200)
            : '';
        $lexicalQuery = is_string($raw['lexical_query'] ?? null)
            ? $this->cleanText($raw['lexical_query'], 1200)
            : '';
        if ($lexicalQuery === '') {
            $lexicalQuery = $this->cleanText($originalQuery, 1200);
        }

        return [
            'retrieval_intent' => $this->cleanEnum(
                $raw['retrieval_intent'] ?? null,
                ['direct_lexical', 'planned_semantic', 'ambiguous'],
                'unknown'
            ),
            'lexical_sufficiency' => $this->cleanEnum(
                $raw['lexical_sufficiency'] ?? null,
                ['high', 'medium', 'low'],
                'unknown'
            ),
            'lexical_query' => $lexicalQuery,
            'term_interpretations' => $this->cleanTermInterpretations(
                $raw['term_interpretations'] ?? null,
                8,
                $originalQuery
            ),
            'elastic_query' => $elasticQuery,
            'semantic_query' => $semanticQuery,
            'alternative_interpretations' => $this->cleanAlternativeInterpretations(
                $raw['alternative_interpretations'] ?? null,
                2
            ),
            'entities' => $this->cleanList($raw['entities'] ?? null, 16),
            'entity_hints' => $this->cleanEntityHints($raw['entity_hints'] ?? null, 16),
            'concepts' => $this->cleanList($raw['concepts'] ?? null, 16),
            'concept_groups' => $this->cleanConceptGroups($raw['concept_groups'] ?? null, 12),
            'constraints' => $this->cleanConstraints($raw['constraints'] ?? null, 12),
            'verification_requirements' => $this->cleanList($raw['verification_requirements'] ?? null, 16),
            // Legacy lexical fields remain part of the 0.8.7 contract.
            'must' => $this->cleanList($raw['must'] ?? null, 24),
            'should' => $this->cleanList($raw['should'] ?? null, 24),
            'must_not' => $this->cleanList($raw['must_not'] ?? null, 24),
            'phrases' => $this->cleanList($raw['phrases'] ?? null, 24),
            'must_not_phrases' => $this->cleanList($raw['must_not_phrases'] ?? null, 24),
        ];
    }

    public function fallback(string $originalQuery): array {
        return $this->normalize([], $originalQuery);
    }

    public function hasLexicalTerms(array $spec): bool {
        return trim((string)($spec['elastic_query'] ?? '')) !== ''
            || !empty($spec['entities'])
            || !empty($spec['concept_groups'])
            || !empty($spec['must'])
            || !empty($spec['should'])
            || !empty($spec['phrases'])
            || !empty($spec['must_not'])
            || !empty($spec['must_not_phrases']);
    }

    public function positiveLexicalTerms(array $spec): bool {
        return trim((string)($spec['elastic_query'] ?? '')) !== ''
            || !empty($spec['entities'])
            || !empty($spec['concept_groups'])
            || !empty($spec['must'])
            || !empty($spec['should'])
            || !empty($spec['phrases'])
            || (
                (string)($spec['retrieval_intent'] ?? '') === 'direct_lexical'
                && (string)($spec['lexical_sufficiency'] ?? '') === 'high'
                && trim((string)($spec['lexical_query'] ?? '')) !== ''
            );
    }

    private function isList(array $value): bool {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }

    private function cleanEnum($value, array $allowed, string $default): string {
        $text = mb_strtolower($this->cleanText($value, 64), 'UTF-8');
        return in_array($text, $allowed, true) ? $text : $default;
    }

    private function cleanText($value, int $maxLength = 300): string {
        if ($value === null || is_array($value) || is_object($value)) {
            return '';
        }
        $text = preg_replace('/\s+/u', ' ', trim((string)$value));
        $text = is_string($text) ? $text : '';
        return mb_substr($text, 0, $maxLength, 'UTF-8');
    }

    private function cleanList($value, int $maxItems = 24): array {
        if (!is_array($value) || !$this->isList($value)) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($value as $item) {
            $text = $this->cleanText($item);
            $folded = mb_strtolower($text, 'UTF-8');
            if ($text === '' || isset($seen[$folded])) {
                continue;
            }
            $seen[$folded] = true;
            $out[] = $text;
            if (count($out) >= $maxItems) {
                break;
            }
        }
        return $out;
    }

    private function cleanConceptGroups($value, int $maxItems = 12): array {
        if (!is_array($value) || !$this->isList($value)) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($value as $item) {
            if (!is_array($item) || $this->isList($item)) {
                continue;
            }
            $terms = $this->cleanList($item['terms'] ?? null, 12);
            if ($terms === []) {
                continue;
            }
            $required = (bool)($item['required'] ?? false);
            $key = ($required ? '1:' : '0:') . implode("\x1f", array_map(
                static function (string $term): string { return mb_strtolower($term, 'UTF-8'); },
                $terms
            ));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['required' => $required, 'terms' => $terms];
            if (count($out) >= $maxItems) {
                break;
            }
        }
        return $out;
    }

    private function cleanTermInterpretations($value, int $maxItems = 8, string $originalQuery = ''): array {
        if (!is_array($value) || !$this->isList($value)) {
            return [];
        }

        $allowed = [
            'probable_typo' => true,
            'normalization' => true,
            'preserved' => true,
            'ambiguous' => true,
            'unrecognized' => true,
        ];
        $out = [];
        $seen = [];
        foreach ($value as $item) {
            if (!is_array($item) || $this->isList($item)) {
                continue;
            }
            $original = $this->cleanText($item['original'] ?? null, 300);
            $status = mb_strtolower($this->cleanText($item['status'] ?? null, 32), 'UTF-8');
            $alternatives = $this->cleanList($item['alternatives'] ?? null, 4);
            $key = mb_strtolower($original, 'UTF-8') . "\x1f" . $status;
            if (
                $original === ''
                || !isset($allowed[$status])
                || isset($seen[$key])
                || ($originalQuery !== '' && mb_strpos($originalQuery, $original, 0, 'UTF-8') === false)
            ) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [
                'original' => $original,
                'status' => $status,
                'alternatives' => $alternatives,
            ];
            if (count($out) >= $maxItems) {
                break;
            }
        }
        return $out;
    }

    private function cleanAlternativeInterpretations($value, int $maxItems = 2): array {
        if (!is_array($value) || !$this->isList($value)) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($value as $item) {
            if (!is_array($item) || $this->isList($item)) {
                continue;
            }
            $meaning = $this->cleanText($item['meaning'] ?? null, 300);
            $query = $this->cleanText($item['query'] ?? null, 1200);
            if ($meaning === '' || $query === '') {
                continue;
            }
            $key = mb_strtolower($meaning . "\x1f" . $query, 'UTF-8');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [
                'meaning' => $meaning,
                'query' => $query,
            ];
            if (count($out) >= $maxItems) {
                break;
            }
        }
        return $out;
    }

    private function cleanEntityHints($value, int $maxItems = 16): array {
        if (!is_array($value) || !$this->isList($value)) {
            return [];
        }

        $allowed = ['person' => true, 'organization' => true, 'location' => true, 'address' => true];
        $out = [];
        $seen = [];
        foreach ($value as $item) {
            if (!is_array($item) || $this->isList($item)) {
                continue;
            }
            $text = $this->cleanText($item['text'] ?? null);
            $kind = mb_strtolower($this->cleanText($item['kind'] ?? null, 32), 'UTF-8');
            $key = mb_strtolower($text, 'UTF-8') . "\x1f" . $kind;
            if ($text === '' || !isset($allowed[$kind]) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['text' => $text, 'kind' => $kind];
            if (count($out) >= $maxItems) {
                break;
            }
        }
        return $out;
    }

    private function cleanConstraints($value, int $maxItems = 12): array {
        if (!is_array($value) || !$this->isList($value)) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($value as $item) {
            if (!is_array($item) || $this->isList($item)) {
                continue;
            }
            $kind = $this->cleanText($item['kind'] ?? null, 120);
            $constraintValue = $this->cleanText($item['value'] ?? null, 300);
            $key = mb_strtolower($kind, 'UTF-8') . "\x1f" . mb_strtolower($constraintValue, 'UTF-8');
            if ($kind === '' || $constraintValue === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['kind' => $kind, 'value' => $constraintValue];
            if (count($out) >= $maxItems) {
                break;
            }
        }
        return $out;
    }
}
