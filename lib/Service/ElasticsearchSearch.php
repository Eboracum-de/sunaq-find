<?php
declare(strict_types=1);

namespace OCA\SunaqFind\Service;

use OCP\Http\Client\IClientService;

final class ElasticsearchSearch {
    private $clientService;
    private $config;
    private $acl;
    private $compiler;
    public function __construct(
        IClientService $clientService,
        ConfigService $config,
        AclValidator $acl,
        ElasticsearchCompiler $compiler
    ) {
        $this->clientService = $clientService;
        $this->config = $config;
        $this->acl = $acl;
        $this->compiler = $compiler;
    }

    public function testConnection(): array {
        $cfg = $this->config->getElasticConfig();
        if ($cfg['url'] === '' || $cfg['index'] === '') {
            throw new \RuntimeException('Elasticsearch is not configured and could not be detected');
        }

        try {
            $response = $this->clientService->newClient()->post(
                $cfg['url'] . '/' . rawurlencode($cfg['index']) . '/_search',
                $this->options($cfg, [
                    'size' => 0,
                    'track_total_hits' => false,
                    'query' => ['match_all' => (object)[]],
                ])
            );
            $status = method_exists($response, 'getStatusCode')
                ? (int)$response->getStatusCode()
                : 200;
            if ($status >= 400) {
                throw new \RuntimeException('Elasticsearch HTTP ' . $status);
            }
            $decoded = json_decode((string)$response->getBody(), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || !isset($decoded['hits'])) {
                throw new \RuntimeException('Elasticsearch returned an invalid response');
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException('Elasticsearch connection test failed');
        }

        return [
            'ok' => true,
            'index' => $cfg['index'],
            'source' => $cfg['source'],
        ];
    }

    public function search(array $plan, string $originalQuery, int $limit = 15): array {
        $cfg = $this->config->getElasticConfig();
        if ($cfg['url'] === '' || $cfg['index'] === '') {
            throw new \RuntimeException('Elasticsearch is not configured and could not be detected');
        }

        $target = max(1, min(20, $limit));
        $body = $this->compiler->compile($plan, $originalQuery, $target);

        $prefilterEnabled = $this->config->isAclPrefilterEnabled();
        $prefilterContext = [
            'available' => false,
            'user' => '',
            'groups' => [],
            'reason' => $prefilterEnabled ? 'unavailable' : 'disabled_by_admin',
        ];
        if ($prefilterEnabled) {
            try {
                $context = $this->acl->prefilterContext();
                if (is_array($context)) {
                    $prefilterContext = $context;
                }
            } catch (\Throwable $e) {
                // Metadata prefilter is an optimization only. Live ACL remains
                // authoritative and must keep working if identity metadata fails.
            }
        }

        $prefilterApplied = $prefilterEnabled
            && !empty($prefilterContext['available'])
            && trim((string)($prefilterContext['user'] ?? '')) !== '';
        $prefilterFallback = $prefilterEnabled && !$prefilterApplied;
        $prefilterStatus = $prefilterApplied
            ? 'applied'
            : ($prefilterEnabled ? 'fallback' : 'disabled');

        if ($prefilterApplied) {
            $body = $this->withAclPrefilter(
                $body,
                (string)$prefilterContext['user'],
                is_array($prefilterContext['groups'] ?? null) ? $prefilterContext['groups'] : []
            );
        }

        $state = [
            'allowed' => [],
            'candidate_count' => 0,
            'denied' => 0,
            'unresolved' => 0,
            'unresolved_missing_file_id' => 0,
            'unresolved_not_found' => 0,
            'unresolved_resolution_failed' => 0,
            'seen_file_ids' => [],
        ];
        $this->authorizeHits(
            $this->executeSearch($cfg, $body),
            $state,
            $target
        );

        return [
            'config_source' => $cfg['source'],
            'candidate_count' => $state['candidate_count'],
            'allowed_count' => count($state['allowed']),
            'denied_count' => $state['denied'],
            'unresolved_count' => $state['unresolved'],
            'acl_prefilter_status' => $prefilterStatus,
            'acl_prefilter_applied' => $prefilterApplied,
            'acl_prefilter_fallback' => $prefilterFallback,
            'acl_prefilter_reason' => (string)($prefilterContext['reason'] ?? ($prefilterApplied ? 'ok' : 'unavailable')),
            'unresolved_missing_file_id' => $state['unresolved_missing_file_id'],
            'unresolved_not_found' => $state['unresolved_not_found'],
            'unresolved_resolution_failed' => $state['unresolved_resolution_failed'],
            'hits' => $state['allowed'],
        ];
    }

    private function executeSearch(array $cfg, array $body): array {
        try {
            $response = $this->clientService->newClient()->post(
                $cfg['url'] . '/' . rawurlencode($cfg['index']) . '/_search',
                $this->options($cfg, $body)
            );
            if (method_exists($response, 'getStatusCode') && $response->getStatusCode() >= 400) {
                throw new \RuntimeException('Elasticsearch request failed');
            }
            $decoded = json_decode((string)$response->getBody(), true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Elasticsearch search is temporarily unavailable');
        }

        $rawHits = $decoded['hits']['hits'] ?? [];
        if (!is_array($rawHits)) {
            throw new \RuntimeException('Elasticsearch returned an invalid search response');
        }
        return $rawHits;
    }

    private function authorizeHits(
        array $rawHits,
        array &$state,
        int $target
    ): void {
        foreach ($rawHits as $hit) {
            if (
                count($state['allowed']) >= $target
                || $state['candidate_count'] >= $target
            ) {
                break;
            }
            if (!is_array($hit)) {
                $state['candidate_count']++;
                $state['unresolved']++;
                $state['unresolved_missing_file_id']++;
                continue;
            }

            $fileId = $this->extractFileId($hit);
            if ($fileId === null) {
                $state['candidate_count']++;
                $state['unresolved']++;
                $state['unresolved_missing_file_id']++;
                continue;
            }
            if (isset($state['seen_file_ids'][$fileId])) {
                continue;
            }
            $state['seen_file_ids'][$fileId] = true;
            $state['candidate_count']++;

            try {
                $authorization = $this->acl->inspect($fileId);
            } catch (\Throwable $e) {
                $authorization = ['allowed' => false, 'reason' => 'resolution_failed'];
            }
            if (empty($authorization['allowed'])) {
                $reason = (string)($authorization['reason'] ?? 'resolution_failed');
                if ($reason === 'not_found') {
                    $state['unresolved']++;
                    $state['unresolved_not_found']++;
                } elseif ($reason === 'resolution_failed') {
                    $state['unresolved']++;
                    $state['unresolved_resolution_failed']++;
                } else {
                    $state['denied']++;
                }
                continue;
            }

            // Security boundary: only touch hit content after live NC ACL.
            $fragments = $hit['highlight']['content'] ?? [];
            $snippet = is_array($fragments)
                ? implode(' … ', array_map('strval', array_slice($fragments, 0, 1)))
                : '';
            $snippet = html_entity_decode(strip_tags($snippet), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $snippet = preg_replace('/\s+/u', ' ', trim($snippet));

            $state['allowed'][] = [
                'authorized' => true,
                'file_id' => (int)$authorization['file_id'],
                'name' => (string)($authorization['name'] ?? ''),
                'path' => (string)($authorization['path'] ?? ''),
                'mimetype' => (string)($authorization['mimetype'] ?? ''),
                'url' => (string)($authorization['url'] ?? ''),
                'score' => isset($hit['_score']) && is_numeric($hit['_score']) ? (float)$hit['_score'] : null,
                'snippet' => mb_substr(is_string($snippet) ? $snippet : '', 0, 1600, 'UTF-8'),
            ];
        }
    }

    private function withAclPrefilter(array $body, string $user, array $groups): array {
        $user = trim($user);
        if ($user === '') {
            return $body;
        }

        // FullTextSearch 23 stores ACL metadata directly as keyword
        // fields (owner/users/groups). Current releases keep those base fields
        // as keyword as well and may additionally expose a .keyword subfield.
        // Query the long-lived base fields so the same prefilter works across
        // old and current FullTextSearch indices.
        $should = [
            ['term' => ['owner' => $user]],
            ['term' => ['users' => $user]],
            ['term' => ['users' => '__all']],
        ];
        foreach ($groups as $group) {
            $group = trim((string)$group);
            if ($group !== '') {
                $should[] = ['term' => ['groups' => $group]];
            }
        }

        if (!isset($body['query']['bool']) || !is_array($body['query']['bool'])) {
            return $body;
        }
        if (!isset($body['query']['bool']['filter']) || !is_array($body['query']['bool']['filter'])) {
            $body['query']['bool']['filter'] = [];
        }
        $body['query']['bool']['filter'][] = [
            'bool' => [
                'should' => $should,
                'minimum_should_match' => 1,
            ],
        ];
        return $body;
    }

    private function options(array $cfg, array $body): array {
        $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/json'];
        if ($cfg['username'] !== '' || $cfg['password'] !== '') {
            $headers['Authorization'] = 'Basic ' . base64_encode($cfg['username'] . ':' . $cfg['password']);
        }

        return [
            'headers' => $headers,
            'body' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'timeout' => 12,
            'connect_timeout' => 3,
            'verify' => !$cfg['allow_self_signed'],
            'nextcloud' => ['allow_local_address' => (bool)$cfg['allow_private_network']],
        ];
    }

    private function extractFileId(array $hit): ?int {
        $source = isset($hit['_source']) && is_array($hit['_source']) ? $hit['_source'] : [];
        foreach (['file_id', 'fileId', 'id'] as $key) {
            if (isset($source[$key]) && preg_match('/^[0-9]+$/', (string)$source[$key])) {
                return (int)$source[$key];
            }
        }

        $id = (string)($hit['_id'] ?? '');
        if (preg_match('/^(?:files:)?([0-9]+)$/', $id, $match)) {
            return (int)$match[1];
        }
        return null;
    }
}
