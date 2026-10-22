<?php
declare(strict_types=1);

namespace OCA\SunaqFind\Service;

/**
 * Deterministic PHP port of the relevant SunaQ v0.8.7-rc1 files/ES compiler.
 * The LLM never owns Elasticsearch syntax.
 */
final class ElasticsearchCompiler {
    public function compile(array $plan, string $originalQuery, int $limit = 15): array {
        $bool = ['must' => [], 'should' => [], 'must_not' => [], 'filter' => []];
        $bool['filter'][] = ['term' => ['provider' => 'files']];

        // Same machine-control sidecar exclusion as the 0.8.7 files arm.
        $bool['must_not'][] = [
            'bool' => [
                'should' => [
                    ['wildcard' => ['title.keyword' => '*/.mailmeta.json']],
                    ['wildcard' => ['title.keyword' => '*/.*.mailmeta.json']],
                    ['wildcard' => ['title.keyword' => '*/.*.akirag.json']],
                    ['wildcard' => ['title.keyword' => '*/.*.sunaq.json']],
                    ['wildcard' => ['title.keyword' => '*/.*.metadata.json']],
                ],
                'minimum_should_match' => 1,
            ],
        ];

        $mode = (string)($plan['search_mode'] ?? 'search_spec');
        if ($mode === 'fallback_original') {
            $bool['should'][] = $this->originalQuestionClause($originalQuery);
        } elseif ($mode === 'direct_lexical') {
            foreach (($plan['direct_queries'] ?? []) as $query) {
                $query = trim((string)$query);
                if ($query !== '') {
                    $bool['should'][] = $this->originalQuestionClause($query);
                }
            }
            if ($bool['should'] === []) {
                $bool['should'][] = $this->originalQuestionClause($originalQuery);
            }
        } else {
            $elasticQuery = trim((string)($plan['elastic_query'] ?? ''));
            if ($elasticQuery !== '') {
                foreach ($this->nextcloudQueryTokens($elasticQuery) as $token) {
                    $clause = [
                        'bool' => [
                            'should' => $this->nextcloudFieldClauses($token),
                            'minimum_should_match' => 1,
                        ],
                    ];
                    $occur = (string)($token['occur'] ?? 'should');
                    if (!in_array($occur, ['must', 'should', 'must_not'], true)) {
                        $occur = 'should';
                    }
                    $bool[$occur][] = $clause;
                }
            } else {
                foreach (($plan['must'] ?? []) as $term) {
                    $bool['must'][] = $this->lexicalTermClause((string)$term);
                }
                foreach (($plan['phrases'] ?? []) as $term) {
                    $bool['must'][] = $this->lexicalPhraseClause((string)$term);
                }
                foreach (($plan['should'] ?? []) as $term) {
                    $bool['should'][] = $this->lexicalTermClause((string)$term);
                }
            }

            foreach (($plan['lexical_groups'] ?? []) as $group) {
                if (!is_array($group)) {
                    continue;
                }
                $alternatives = [];
                foreach (($group['terms'] ?? []) as $term) {
                    $term = trim((string)$term);
                    if ($term !== '') {
                        $alternatives[] = $this->lexicalTermClause($term);
                    }
                }
                if ($alternatives === []) {
                    continue;
                }
                $clause = ['bool' => ['should' => $alternatives, 'minimum_should_match' => 1]];
                $bool[!empty($group['required']) ? 'must' : 'should'][] = $clause;
            }

            foreach (($plan['must_not'] ?? []) as $term) {
                $bool['must_not'][] = $this->lexicalTermClause((string)$term);
            }
            foreach (($plan['must_not_phrases'] ?? []) as $term) {
                $bool['must_not'][] = $this->lexicalPhraseClause((string)$term);
            }

            // Fail safely into the tested broad 0.8.7 lexical view if an
            // otherwise valid SearchSpec carries no positive files anchors.
            if ($bool['must'] === [] && $bool['should'] === []) {
                $bool['should'][] = $this->originalQuestionClause($originalQuery);
            }
        }

        foreach (($plan['required_literals'] ?? []) as $literal) {
            $literal = trim((string)$literal);
            if ($literal !== '') {
                $bool['must'][] = $this->lexicalPhraseClause($literal);
            }
        }

        $bool = $this->deduplicate($bool);
        if ($bool['must'] === [] && $bool['should'] !== []) {
            $bool['minimum_should_match'] = 1;
        }

        return [
            'size' => max(1, min(20, $limit)),
            '_source' => ['file_id', 'fileId', 'id'],
            'query' => ['bool' => $bool],
            'highlight' => [
                'pre_tags' => [''],
                'post_tags' => [''],
                'fields' => [
                    'content' => ['fragment_size' => 1600, 'number_of_fragments' => 1],
                ],
            ],
        ];
    }

    private function lexicalTermClause(string $term): array {
        return [
            'bool' => [
                'should' => $this->nextcloudFieldClauses([
                    'text' => $term,
                    'match' => 'match_phrase_prefix',
                ]),
                'minimum_should_match' => 1,
            ],
        ];
    }

    private function lexicalPhraseClause(string $term): array {
        $value = trim($term);
        return [
            'bool' => [
                'should' => [
                    ['match_phrase' => ['content' => $value]],
                    ['match_phrase' => ['title' => $value]],
                ],
                'minimum_should_match' => 1,
            ],
        ];
    }

    private function nextcloudFieldClauses(array $token): array {
        $match = trim((string)($token['match'] ?? ''));
        if (!in_array($match, ['match', 'match_phrase_prefix', 'match_phrase'], true)) {
            $match = !empty($token['phrase']) ? 'match_phrase' : 'match';
        }
        $value = (string)($token['text'] ?? '');
        return [
            [$match => ['content' => $value]],
            [$match => ['title' => $value]],
        ];
    }

    /**
     * Port of rag/elastic_query.py::nextcloud_query_tokens.
     */
    private function nextcloudQueryTokens(string $query): array {
        $text = trim($this->normalizeQuotes($query));
        if ($text === '') {
            return [];
        }

        preg_match_all('/[+-]?"(?:\\\\.|[^\\"])*"|\S+/u', $text, $matches);
        $parsed = [];
        foreach (($matches[0] ?? []) as $raw) {
            $token = trim((string)$raw);
            if ($token === '') {
                continue;
            }

            $occur = 'should';
            if (isset($token[0]) && $token[0] === '+') {
                $occur = 'must';
                $token = trim(substr($token, 1));
            } elseif (isset($token[0]) && $token[0] === '-') {
                $occur = 'must_not';
                $token = trim(substr($token, 1));
            }
            if ($token === '') {
                continue;
            }

            $quoted = isset($token[0]) && $token[0] === '"';
            $matchType = 'match_phrase_prefix';
            if ($quoted) {
                $matchType = strpos($token, ' ') !== false ? 'match_phrase_prefix' : 'match';
            }

            $token = trim(str_replace(['"', '\\\\'], ['', '\\'], $token));
            if ($token === '') {
                continue;
            }

            $parsed[] = [
                'occur' => $occur,
                'text' => $token,
                'phrase' => $quoted,
                'match' => $matchType,
            ];
        }
        return $parsed;
    }

    /**
     * Port of rag/search.py::original_question_clause.
     */
    private function originalQuestionClause(string $question): array {
        return [
            'bool' => [
                'should' => [
                    [
                        'multi_match' => [
                            'query' => $question,
                            'fields' => ['combined^2', 'content'],
                            'type' => 'best_fields',
                            'operator' => 'or',
                            'minimum_should_match' => '25%',
                            'boost' => 1.0,
                        ],
                    ],
                    [
                        'match' => [
                            'combined' => [
                                'query' => $question,
                                'operator' => 'or',
                                'minimum_should_match' => '25%',
                                'fuzziness' => 'AUTO',
                                'prefix_length' => 2,
                                'max_expansions' => 50,
                                'boost' => 0.35,
                            ],
                        ],
                    ],
                ],
                'minimum_should_match' => 1,
            ],
        ];
    }

    private function normalizeQuotes(string $text): string {
        return strtr($text, [
            '“' => '"', '”' => '"', '„' => '"', '‟' => '"',
            '«' => '"', '»' => '"', '‹' => '"', '›' => '"',
        ]);
    }

    private function deduplicate(array $bool): array {
        foreach (['must', 'should', 'must_not', 'filter'] as $occur) {
            $seen = [];
            $result = [];
            foreach (($bool[$occur] ?? []) as $clause) {
                $key = json_encode($clause, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (!is_string($key) || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $result[] = $clause;
            }
            $bool[$occur] = $result;
        }
        return $bool;
    }
}
