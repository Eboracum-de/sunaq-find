<?php
declare(strict_types=1);

namespace OCP\Http\Client {
    if (!interface_exists(IClientService::class)) {
        interface IClientService {
            public function newClient();
        }
    }
}

namespace {
    use OCA\SunaqFind\Service\AclValidator;
    use OCA\SunaqFind\Service\AnswerService;
    use OCA\SunaqFind\Service\ConfigService;
    use OCA\SunaqFind\Service\ElasticsearchCompiler;
    use OCA\SunaqFind\Service\ElasticsearchSearch;
    use OCA\SunaqFind\Service\EvidenceBuilder;
    use OCA\SunaqFind\Service\HardConstraintExtractor;
    use OCA\SunaqFind\Service\LlmClient;
    use OCA\SunaqFind\Service\QueryRewriteService;
    use OCA\SunaqFind\Service\RetrievalPlanner;
    use OCA\SunaqFind\Service\SearchSpecNormalizer;
    use OCA\SunaqFind\Service\SearchService;
    use OCP\Http\Client\IClientService;

    $root = dirname(__DIR__);
    foreach ([
        'ConfigService.php',
        'SearchSpecNormalizer.php',
        'HardConstraintExtractor.php',
        'RetrievalPlanner.php',
        'LlmClient.php',
        'QueryRewriteService.php',
        'ElasticsearchCompiler.php',
        'AclValidator.php',
        'ElasticsearchSearch.php',
        'EvidenceBuilder.php',
        'AnswerService.php',
        'SearchService.php',
    ] as $file) {
        require_once $root . '/lib/Service/' . $file;
    }

    final class TestFailure extends \RuntimeException {}

    function check(bool $condition, string $message): void {
        if (!$condition) {
            throw new TestFailure($message);
        }
    }

    function expectException(callable $callable, string $class): \Throwable {
        try {
            $callable();
        } catch (\Throwable $e) {
            check($e instanceof $class, 'Expected ' . $class . ', got ' . $e::class);
            return $e;
        }
        throw new TestFailure('Expected exception ' . $class);
    }

    class FakeLlmClient extends LlmClient {
        public array $calls = [];

        public function __construct(private string|\Throwable $result) {}

        public function complete(
            array $messages,
            int $maxTokens = 1200,
            bool $jsonMode = false,
            int $timeout = 35,
            ?bool $think = null,
            ?string $reasoningMode = null,
        ): string {
            $this->calls[] = [
                'messages' => $messages,
                'max_tokens' => $maxTokens,
                'json_mode' => $jsonMode,
                'timeout' => $timeout,
                'think' => $think,
                'reasoning_mode' => $reasoningMode,
            ];
            if ($this->result instanceof \Throwable) {
                throw $this->result;
            }
            return $this->result;
        }
    }

    class FakeConfigService extends ConfigService {
        public function __construct() {}

        public function getRetrievalLanguages(): array {
            return ['de'];
        }

        public function isDirectLexicalRoutingEnabled(): bool {
            return false;
        }

        public function isAclPrefilterEnabled(): bool {
            return true;
        }

        public function getSearchDocumentLimit(): int {
            return 15;
        }

        public function getLlmDocumentLimit(): int {
            return 12;
        }

        public function showDocumentExcerpts(): bool {
            return true;
        }

        public function showSearchStrategy(): bool {
            return true;
        }

        public function getElasticConfig(): array {
            return [
                'url' => 'http://elasticsearch.invalid:9200',
                'index' => 'nextcloud',
                'username' => '',
                'password' => '',
                'source' => 'test',
                'allow_private_network' => true,
                'allow_self_signed' => false,
            ];
        }
    }

    class FakeLlmConfigService extends ConfigService {
        public function __construct(
            private string $url,
            private string $model,
        ) {}

        public function getLlmConfig(): array {
            return [
                'url' => $this->url,
                'model' => $this->model,
                'api_key' => 'test-key',
                'allow_private_network' => false,
                'allow_self_signed' => false,
            ];
        }
    }

    final class CapturingClientService implements IClientService {
        public array $calls = [];

        public function newClient(): object {
            return new class($this) {
                public function __construct(private CapturingClientService $owner) {}

                public function post(string $url, array $options): object {
                    $this->owner->calls[] = [
                        'url' => $url,
                        'options' => $options,
                        'body' => json_decode((string)($options['body'] ?? ''), true),
                    ];
                    return new class {
                        public function getStatusCode(): int { return 200; }
                        public function getBody(): string {
                            return json_encode([
                                'choices' => [[
                                    'message' => ['content' => '{"ok":true}'],
                                ]],
                            ], JSON_THROW_ON_ERROR);
                        }
                    };
                }
            };
        }
    }

    class FakeAclValidator extends AclValidator {
        public function __construct() {}

        public function inspect(int $fileId): array {
            return ['allowed' => false, 'reason' => 'not_readable'];
        }
    }

    final class ThrowingClientService implements IClientService {
        public function newClient(): object {
            return new class {
                public function post(string $url, array $options): void {
                    throw new \RuntimeException('upstream secret body and credentials');
                }
            };
        }
    }

    final class StatusClientService implements IClientService {
        public function __construct(private int $status) {}

        public function newClient(): object {
            return new class($this->status) {
                public function __construct(private int $status) {}

                public function post(string $url, array $options): object {
                    return new class($this->status) {
                        public function __construct(private int $status) {}
                        public function getStatusCode(): int { return $this->status; }
                        public function getBody(): string { return '{}'; }
                    };
                }
            };
        }
    }

    final class StaticClientService implements IClientService {
        public function __construct(private array $payload) {}

        public function newClient(): object {
            return new class($this->payload) {
                public function __construct(private array $payload) {}

                public function post(string $url, array $options): object {
                    return new class($this->payload) {
                        public function __construct(private array $payload) {}
                        public function getStatusCode(): int { return 200; }
                        public function getBody(): string {
                            return json_encode($this->payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                        }
                    };
                }
            };
        }
    }

    final class SequenceClientService implements IClientService {
        public array $calls = [];
        public int $index = 0;

        public function __construct(public array $payloads) {}

        public function newClient(): object {
            return new class($this) {
                public function __construct(private SequenceClientService $owner) {}

                public function post(string $url, array $options): object {
                    $this->owner->calls[] = [
                        'url' => $url,
                        'body' => json_decode((string)($options['body'] ?? ''), true),
                    ];
                    $payload = $this->owner->payloads[
                        min($this->owner->index, max(0, count($this->owner->payloads) - 1))
                    ] ?? ['hits' => ['hits' => []]];
                    $this->owner->index++;

                    return new class($payload) {
                        public function __construct(private array $payload) {}
                        public function getStatusCode(): int { return 200; }
                        public function getBody(): string {
                            return json_encode($this->payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                        }
                    };
                }
            };
        }
    }

    final class SelectiveAclValidator extends AclValidator {
        public function __construct() {}

        public function inspect(int $fileId): array {
            if ($fileId !== 2) {
                return ['allowed' => false, 'reason' => 'not_readable'];
            }
            return [
                'allowed' => true,
                'file_id' => 2,
                'name' => 'allowed.pdf',
                'path' => '/allowed.pdf',
                'mimetype' => 'application/pdf',
                'permissions' => 1,
                'url' => '/apps/files/f/2',
            ];
        }
    }

    final class UnavailablePrefilterAclValidator extends AclValidator {
        public function __construct() {}

        public function prefilterContext(): array {
            return ['available' => false, 'user' => '', 'groups' => []];
        }

        public function inspect(int $fileId): array {
            return ['allowed' => false, 'reason' => 'not_readable'];
        }
    }

    final class PrefilterAclValidator extends AclValidator {
        public function __construct(private array $allowedIds = [2]) {}

        public function prefilterContext(): array {
            return [
                'available' => true,
                'user' => 'alice',
                'groups' => ['finance', 'board'],
            ];
        }

        public function inspect(int $fileId): array {
            if (!in_array($fileId, $this->allowedIds, true)) {
                return ['allowed' => false, 'reason' => 'not_readable'];
            }
            return [
                'allowed' => true,
                'file_id' => $fileId,
                'name' => 'allowed-' . $fileId . '.pdf',
                'path' => '/allowed-' . $fileId . '.pdf',
                'mimetype' => 'application/pdf',
                'permissions' => 1,
                'url' => '/apps/files/f/' . $fileId,
            ];
        }
    }

    final class UnresolvedPrefilterAclValidator extends AclValidator {
        public function __construct() {}

        public function prefilterContext(): array {
            return [
                'available' => true,
                'user' => 'alice',
                'groups' => [],
            ];
        }

        public function inspect(int $fileId): array {
            return ['allowed' => false, 'reason' => 'not_found'];
        }
    }

    $tests = [];

    $tests['native OpenAI GPT-5.6 request mirrors 0.8.7 translation'] = function (): void {
        $http = new CapturingClientService();
        $client = new LlmClient(
            $http,
            new FakeLlmConfigService('https://api.openai.com/v1', 'gpt-5.6-luna')
        );

        $result = $client->complete(
            [['role' => 'user', 'content' => 'test']],
            900,
            true,
            35,
            false
        );

        check($result === '{"ok":true}', 'Native OpenAI response was not decoded');
        $body = $http->calls[0]['body'];
        check(isset($body['max_completion_tokens']) && $body['max_completion_tokens'] === 900, 'max_completion_tokens missing');
        check(!array_key_exists('max_tokens', $body), 'Native OpenAI request still uses max_tokens');
        check(!array_key_exists('temperature', $body), 'Native GPT-5.6 request still sends temperature');
        check(($body['reasoning_effort'] ?? null) === 'none', 'No-think rewrite did not map to reasoning_effort=none');
        check(($body['response_format']['type'] ?? null) === 'json_object', 'JSON mode missing');
    };

    $tests['third-party OpenAI-compatible request keeps portable fields'] = function (): void {
        $http = new CapturingClientService();
        $client = new LlmClient(
            $http,
            new FakeLlmConfigService('http://ollama.invalid:11434/v1', 'qwen3:8b')
        );

        $client->complete(
            [['role' => 'user', 'content' => 'test']],
            900,
            false,
            35,
            false
        );

        $body = $http->calls[0]['body'];
        check(isset($body['max_tokens']) && $body['max_tokens'] === 900, 'Third-party request lost max_tokens');
        check(($body['temperature'] ?? null) === 0, 'Third-party request lost temperature');
        check(!array_key_exists('max_completion_tokens', $body), 'Third-party request got native OpenAI token field');
        check(!array_key_exists('reasoning_effort', $body), 'Third-party request got native GPT-5.6 reasoning field');
        check(!array_key_exists('think', $body), 'Legacy compatible-provider path unexpectedly changed thinking mode');
    };


    $tests['LLM HTTP 429 is classified as rate or quota failure'] = function (): void {
        $client = new LlmClient(
            new StatusClientService(429),
            new FakeLlmConfigService('https://api.groq.com/openai/v1', 'qwen/qwen3.8-27b')
        );

        $exception = expectException(
            fn() => $client->complete([['role' => 'user', 'content' => 'test']], 64, false),
            \RuntimeException::class
        );

        check(
            $exception->getMessage() === 'LLM rate limit or usage quota exceeded (HTTP 429)',
            'HTTP 429 was not mapped to a useful provider-neutral diagnostic'
        );
    };

    $tests['ACL metadata prefilter uses native user and group fields before live ACL'] = function (): void {
        $client = new SequenceClientService([[
            'hits' => ['hits' => [[
                '_id' => 'files:2',
                '_score' => 10.0,
                '_source' => ['file_id' => 2],
                'highlight' => ['content' => ['visible content']],
            ]]],
        ]]);
        $search = new ElasticsearchSearch(
            $client,
            new FakeConfigService(),
            new PrefilterAclValidator([2]),
            new ElasticsearchCompiler()
        );
        $plan = (new RetrievalPlanner(new HardConstraintExtractor()))
            ->planFromSearchSpec((new SearchSpecNormalizer())->fallback('Umsatz Beispielmuseum'), 'Umsatz Beispielmuseum', true);

        $result = $search->search($plan, 'Umsatz Beispielmuseum', 1);

        check($result['allowed_count'] === 1, 'Prefiltered visible hit was not retained');
        check($result['acl_prefilter_applied'] === true, 'ACL metadata prefilter was not reported as applied');
        check($result['acl_prefilter_fallback'] === false, 'Unexpected unfiltered fallback was used');
        check(count($client->calls) === 1, 'Successful prefilter unexpectedly caused a second ES query');

        $filter = $client->calls[0]['body']['query']['bool']['filter'] ?? [];
        $serialized = json_encode($filter, JSON_UNESCAPED_UNICODE);
        check(str_contains((string)$serialized, '"owner":"alice"'), 'Owner prefilter clause missing');
        check(str_contains((string)$serialized, '"users":"alice"'), 'Direct-user prefilter clause missing');
        check(str_contains((string)$serialized, '"users":"__all"'), 'Public-access prefilter clause missing');
        check(str_contains((string)$serialized, '"groups":"finance"'), 'Group prefilter clause missing');
        check(!str_contains((string)$serialized, '.keyword'), 'ACL prefilter still depends on post-NC23 .keyword subfields');
        check(!str_contains((string)$serialized, '"match"'), 'ACL prefilter must not use analyzed match clauses');
    };

    $tests['empty valid ACL metadata prefilter result stays empty'] = function (): void {
        $client = new SequenceClientService([
            ['hits' => ['hits' => []]],
            ['hits' => ['hits' => [[
                '_id' => 'files:2',
                '_score' => 9.0,
                '_source' => ['file_id' => 2],
                'highlight' => ['content' => ['must not be fetched']],
            ]]]],
        ]);
        $search = new ElasticsearchSearch(
            $client,
            new FakeConfigService(),
            new PrefilterAclValidator([2]),
            new ElasticsearchCompiler()
        );
        $plan = (new RetrievalPlanner(new HardConstraintExtractor()))
            ->planFromSearchSpec((new SearchSpecNormalizer())->fallback('Umsatz Beispielmuseum'), 'Umsatz Beispielmuseum', true);

        $result = $search->search($plan, 'Umsatz Beispielmuseum', 15);

        check($result['candidate_count'] === 0, 'Empty valid prefilter should produce zero candidates');
        check($result['allowed_count'] === 0, 'Empty valid prefilter unexpectedly produced authorized hits');
        check($result['acl_prefilter_applied'] === true, 'Valid empty prefilter was not reported as applied');
        check($result['acl_prefilter_fallback'] === false, 'Empty valid prefilter incorrectly triggered fallback');
        check(count($client->calls) === 1, 'Empty valid prefilter triggered an unfiltered second ES query');
    };

    $tests['valid ACL metadata prefilter does not trigger unfiltered refill'] = function (): void {
        $client = new SequenceClientService([
            [
                'hits' => ['hits' => [[
                    '_id' => 'files:1',
                    '_score' => 11.0,
                    '_source' => ['file_id' => 1],
                    'highlight' => ['content' => ['hidden content']],
                ]]],
            ],
            [
                'hits' => ['hits' => [[
                    '_id' => 'files:2',
                    '_score' => 9.0,
                    '_source' => ['file_id' => 2],
                    'highlight' => ['content' => ['would have been authorized']],
                ]]],
            ],
        ]);
        $search = new ElasticsearchSearch(
            $client,
            new FakeConfigService(),
            new PrefilterAclValidator([2]),
            new ElasticsearchCompiler()
        );
        $plan = (new RetrievalPlanner(new HardConstraintExtractor()))
            ->planFromSearchSpec((new SearchSpecNormalizer())->fallback('Umsatz Beispielmuseum'), 'Umsatz Beispielmuseum', true);

        $result = $search->search($plan, 'Umsatz Beispielmuseum', 15);

        check($result['allowed_count'] === 0, 'Valid prefilter unexpectedly refilled from unfiltered candidates');
        check($result['candidate_count'] === 1, 'Valid prefilter candidate count is wrong');
        check($result['denied_count'] === 1, 'Live ACL denial after prefilter was not counted');
        check($result['acl_prefilter_applied'] === true, 'Prefilter application flag missing');
        check($result['acl_prefilter_fallback'] === false, 'Valid prefilter incorrectly reported fallback');
        check(count($client->calls) === 1, 'Valid prefilter triggered an unfiltered second ES query');
        check(!str_contains(json_encode($result, JSON_UNESCAPED_UNICODE), 'hidden content'), 'Denied content leaked into retrieval result');
    };

    $tests['stale Elasticsearch candidate is unresolved rather than ACL denied'] = function (): void {
        $client = new SequenceClientService([[
            'hits' => ['hits' => [[
                '_id' => 'files:99',
                '_score' => 7.0,
                '_source' => ['file_id' => 99],
                'highlight' => ['content' => ['stale indexed content']],
            ]]],
        ]]);
        $search = new ElasticsearchSearch(
            $client,
            new FakeConfigService(),
            new UnresolvedPrefilterAclValidator(),
            new ElasticsearchCompiler()
        );
        $plan = (new RetrievalPlanner(new HardConstraintExtractor()))
            ->planFromSearchSpec((new SearchSpecNormalizer())->fallback('Beispiel'), 'Beispiel', true);

        $result = $search->search($plan, 'Beispiel', 15);

        check($result['candidate_count'] === 1, 'Stale candidate was not counted');
        check($result['allowed_count'] === 0, 'Stale candidate unexpectedly became authorized');
        check($result['denied_count'] === 0, 'Unresolvable candidate is still reported as ACL denied');
        check($result['unresolved_count'] === 1, 'Unresolvable candidate is not reported as unresolved');
    };

    $tests['technical prefilter failure uses bounded unfiltered fallback'] = function (): void {
        $fallbackHits = [];
        for ($id = 1; $id <= 60; $id++) {
            $fallbackHits[] = [
                '_id' => 'files:' . $id,
                '_score' => 100 - $id,
                '_source' => ['file_id' => $id],
                'highlight' => ['content' => ['hidden-' . $id]],
            ];
        }

        $client = new SequenceClientService([
            ['hits' => ['hits' => $fallbackHits]],
        ]);
        $search = new ElasticsearchSearch(
            $client,
            new FakeConfigService(),
            new UnavailablePrefilterAclValidator(),
            new ElasticsearchCompiler()
        );
        $plan = (new RetrievalPlanner(new HardConstraintExtractor()))
            ->planFromSearchSpec((new SearchSpecNormalizer())->fallback('Umsatz Beispielmuseum'), 'Umsatz Beispielmuseum', true);

        $result = $search->search($plan, 'Umsatz Beispielmuseum', 15);

        check($result['candidate_count'] === 15, 'Technical fallback exceeded the configured 15-hit Elasticsearch limit');
        check($result['denied_count'] === 15, 'Technical fallback denied count does not match the Elasticsearch hit limit');
        check($result['acl_prefilter_applied'] === false, 'Unavailable prefilter was incorrectly reported as applied');
        check($result['acl_prefilter_fallback'] === true, 'Technical fallback flag missing');
        check(count($client->calls) === 1, 'Technical fallback should execute exactly one unfiltered ES query');
        check(($client->calls[0]['body']['size'] ?? 0) === 15, 'Technical fallback changed the configured Elasticsearch hit limit');
        check(
            empty($client->calls[0]['body']['query']['bool']['filter']),
            'Technical fallback unexpectedly contains ACL metadata filter'
        );
    };

    $tests['disabled ACL prefilter keeps hard Elasticsearch limit and live ACL'] = function (): void {
        $hits = [];
        for ($id = 1; $id <= 15; $id++) {
            $hits[] = [
                '_id' => 'files:' . $id,
                '_score' => 5.0,
                '_source' => ['file_id' => $id],
                'highlight' => ['content' => ['candidate-' . $id]],
            ];
        }

        $client = new SequenceClientService([
            ['hits' => ['hits' => $hits]],
        ]);
        $config = new class extends FakeConfigService {
            public function isAclPrefilterEnabled(): bool {
                return false;
            }
        };
        $search = new ElasticsearchSearch(
            $client,
            $config,
            new PrefilterAclValidator([]),
            new ElasticsearchCompiler()
        );
        $plan = (new RetrievalPlanner(new HardConstraintExtractor()))
            ->planFromSearchSpec((new SearchSpecNormalizer())->fallback('Beispiel'), 'Beispiel', true);

        $result = $search->search($plan, 'Beispiel', 15);

        check($result['candidate_count'] === 15, 'Disabled prefilter did not keep the configured Elasticsearch hit limit');
        check($result['allowed_count'] === 0 && $result['denied_count'] === 15, 'Live ACL was not applied to all unfiltered candidates');
        check(($result['acl_prefilter_status'] ?? '') === 'disabled', 'Disabled prefilter status is not explicit');
        check($result['acl_prefilter_applied'] === false, 'Disabled prefilter was reported as applied');
        check($result['acl_prefilter_fallback'] === false, 'Intentional prefilter disablement was reported as fallback');
        check(($client->calls[0]['body']['size'] ?? 0) === 15, 'Disabled prefilter changed the configured Elasticsearch hit limit');
        check(
            empty($client->calls[0]['body']['query']['bool']['filter']),
            'Disabled prefilter unexpectedly added ACL metadata filters'
        );
    };

    $tests['unified search shortcut is prioritized ahead of standard file providers'] = function () use ($root): void {
        $provider = file_get_contents($root . '/lib/Search/SearchShortcutProvider.php');
        check(is_string($provider), 'Unified-search provider source could not be read');
        check(
            str_contains($provider, 'return -1;'),
            'SunaQ Find unified-search provider is not prioritized'
        );
    };

    $tests['runtime language and localization contracts are explicit'] = function () use ($root): void {
        $rewriter = file_get_contents($root . '/lib/Service/QueryRewriteService.php');
        $answerer = file_get_contents($root . '/lib/Service/AnswerService.php');
        $main = file_get_contents($root . '/templates/main.php');
        $admin = file_get_contents($root . '/templates/admin.php');
        $searchJs = file_get_contents($root . '/js/search.js');
        $de = json_decode((string)file_get_contents($root . '/l10n/de.json'), true);

        check(is_string($rewriter) && is_string($answerer), 'Prompt sources could not be read');
        check(str_contains($rewriter, "'interaction_language' => \$this->interactionLanguage()"), 'Planner does not receive interaction language');
        check(str_contains($rewriter, 'retrieval_languages'), 'Planner lost independent retrieval languages');
        check(str_contains($answerer, 'Write the answer in INTERACTION_LANGUAGE'), 'Answer prompt is not interaction-language aware');
        check(!str_contains($answerer, 'auf Deutsch'), 'Answer prompt still hard-codes German');
        check(str_contains($rewriter, 'Do not emit preserved merely because an ordinary term or entity remains'), 'Planner still allows spurious preserved notices');
        check(str_contains($answerer, 'prefer a compact Markdown table'), 'Answer prompt lost structured presentation guidance');
        check(str_contains($answerer, 'never collapse several Markdown table rows onto'), 'Answer prompt does not require physical table row line breaks');
        check(str_contains((string)$main, "\$l->t('Ask questions about your Nextcloud documents with AI.')"), 'Main template is not localized');
        check(str_contains((string)$admin, "\$l->t('Connection tests use the currently saved configuration. Save changes before testing.')"), 'Admin template is not localized');
        check(str_contains((string)$searchJs, "t('sunaqfind', 'Searching …')"), 'Search JavaScript is not localized');
        check(is_array($de) && (($de['translations']['Search'] ?? '') === 'Suchen'), 'German translation catalog is missing core UI strings');
        check(
            (($de['translations']['“%s” was understood as a spelling or naming variant; also considered: “%s”.'] ?? '') !== ''),
            'German translation for normalization search notes is missing'
        );
        check(
            (($de['translations']['“%s” was preserved in its original form as requested by the user.'] ?? '') !== ''),
            'German translation for preserved search notes is missing'
        );
    };

    $tests['visible product branding is SunaQ Find'] = function () use ($root): void {
        $info = file_get_contents($root . '/appinfo/info.xml');
        $main = file_get_contents($root . '/templates/main.php');
        $admin = file_get_contents($root . '/templates/admin.php');
        $section = file_get_contents($root . '/lib/Settings/AdminSection.php');
        $provider = file_get_contents($root . '/lib/Search/SearchShortcutProvider.php');
        check(is_string($info) && is_string($main) && is_string($admin) && is_string($section) && is_string($provider), 'Branding files could not be read');
        check(str_contains($info, '<name>SunaQ Find</name>'), 'App metadata still exposes old product name');
        check(str_contains($main, '<h2>SunaQ Find</h2>'), 'Main UI still exposes old product name');
        check(str_contains($main, 'Ask questions about your Nextcloud documents with AI.'), 'Main UI tagline is not user-focused');
        check(str_contains($admin, '<h2>SunaQ Find</h2>'), 'Admin UI still exposes old product name');
        check(str_contains($admin, "\$l->t('Version:')"), 'Admin UI does not expose the installed app version');
        check(str_contains($admin, "\$_['app_version']"), 'Admin UI version is not rendered from runtime settings data');
        check(str_contains($section, "t('SunaQ Find')"), 'Admin section still exposes old product name');
        check(str_contains($provider, "t('SunaQ Find')"), 'Unified search provider still exposes old product name');
    };

    $tests['cross-version file links and app icons use compatible variants'] = function () use ($root): void {
        $acl = file_get_contents($root . '/lib/Service/AclValidator.php');
        $section = file_get_contents($root . '/lib/Settings/AdminSection.php');
        $light = file_get_contents($root . '/img/app.svg');
        $dark = file_get_contents($root . '/img/app-dark.svg');

        check(is_string($acl) && is_string($section) && is_string($light) && is_string($dark), 'Compatibility icon/link files could not be read');
        check(str_contains($acl, "'files.View.showFile'"), 'Legacy NC23 Files route casing is missing');
        check(str_contains($acl, "'files.view.showFile'"), 'Current Files route casing fallback is missing');
        check(str_contains($section, "imagePath('sunaqfind', 'app-dark.svg')"), 'Admin settings do not use dark icon variant');
        check(str_contains($light, 'stroke="#fff"'), 'Navigation app icon is not white');
        check(str_contains($dark, 'stroke="#000"'), 'Admin/dark app icon is not black');
        check(str_contains($light, '<circle cx="14.2" cy="14.2" r="10.4"/>'), 'Minimal Q icon circle geometry is missing');
        check(str_contains($light, 'M19.8 19.8 27.4 27.4'), 'Minimal Q icon tail geometry is missing');
    };

    $tests['admin settings read version from Nextcloud app manager'] = function () use ($root): void {
        $settings = file_get_contents($root . '/lib/Settings/Admin.php');
        check(is_string($settings), 'Admin settings source could not be read');
        check(str_contains($settings, 'use OCP\\App\\IAppManager;'), 'Admin settings do not use the public Nextcloud app manager API');
        check(str_contains($settings, 'private $appManager;') && str_contains($settings, '$this->appManager = $appManager;'), 'Nextcloud app manager is not injected into admin settings');
        check(
            str_contains($settings, "\$this->appManager->getAppVersion(Application::APP_ID)"),
            'Admin version is not read from the installed app metadata'
        );
    };

    $tests['admin Elasticsearch connection test uses configured index without ACL search'] = function (): void {
        $client = new StaticClientService([
            'hits' => ['hits' => []],
        ]);
        $search = new ElasticsearchSearch(
            $client,
            new FakeConfigService(),
            new FakeAclValidator(),
            new ElasticsearchCompiler()
        );
        $result = $search->testConnection();

        check($result['ok'] === true, 'Elasticsearch connection test did not report success');
        check(($result['index'] ?? '') === 'nextcloud', 'Elasticsearch connection test lost configured index');
    };

    $tests['admin LLM connection test reuses normal client adapter'] = function (): void {
        $http = new CapturingClientService();
        $client = new LlmClient(
            $http,
            new FakeLlmConfigService('https://api.openai.com/v1', 'gpt-5.6-luna')
        );
        $result = $client->testConnection();

        check($result['ok'] === true, 'LLM connection test did not report success');
        check(($result['model'] ?? '') === 'gpt-5.6-luna', 'LLM connection test lost model name');
        check(count($http->calls) === 1, 'LLM connection test did not use exactly one request');
        check(($http->calls[0]['options']['timeout'] ?? null) === 60, 'LLM connection test timeout is not 60 seconds');
    };

    $tests['admin UI exposes stored configuration connection tests'] = function () use ($root): void {
        $routes = file_get_contents($root . '/appinfo/routes.php');
        $template = file_get_contents($root . '/templates/admin.php');
        $script = file_get_contents($root . '/js/admin.js');
        check(is_string($routes) && is_string($template) && is_string($script), 'Admin test UI files could not be read');
        check(str_contains($routes, '/admin/test/elasticsearch'), 'Elasticsearch admin test route missing');
        check(str_contains($routes, '/admin/test/llm'), 'LLM admin test route missing');
        check(str_contains($template, 'Test Elasticsearch'), 'Elasticsearch test button missing');
        check(str_contains($template, 'Test AI'), 'AI test button missing');
        check(str_contains($template, 'currently saved configuration'), 'Saved-config test semantics are not explained');
        check(str_contains($script, '/admin/test/elasticsearch'), 'Elasticsearch test button is not wired');
        check(str_contains($script, '/admin/test/llm'), 'LLM test button is not wired');
    };

    $tests['fresh installs allow configured private endpoints by default'] = function () use ($root): void {
        $config = file_get_contents($root . '/lib/Service/ConfigService.php');
        $controller = file_get_contents($root . '/lib/Controller/AdminController.php');
        check(is_string($config) && is_string($controller), 'Network-default sources could not be read');
        check(
            substr_count($config, "boolValue('allow_private_network', true)") >= 3,
            'Private endpoint access is not default-on throughout config reads'
        );
        check(
            str_contains($controller, "'allow_private_network' => \$boolean(\$params, 'allowPrivateNetwork', true)"),
            'Admin save does not preserve the private-endpoint default'
        );
    };

    $tests['compatibility floor avoids PHP 8.1 and post-NC23 API dependencies'] = function () use ($root): void {
        $info = file_get_contents($root . '/appinfo/info.xml');
        $config = file_get_contents($root . '/lib/Service/ConfigService.php');
        $provider = file_get_contents($root . '/lib/Search/SearchShortcutProvider.php');
        $answer = file_get_contents($root . '/lib/Service/AnswerService.php');
        $normalizer = file_get_contents($root . '/lib/Service/SearchSpecNormalizer.php');

        check(is_string($info) && is_string($config) && is_string($provider), 'Compatibility sources could not be read');
        check(str_contains($info, '<nextcloud min-version="23" max-version="35" />'), 'Nextcloud compatibility floor is not 23');
        check(str_contains($info, '<php min-version="7.4" />'), 'PHP compatibility floor is not 7.4');
        check(!str_contains($config, 'use OCP\\IAppConfig;'), 'Config has a hard dependency on version-sensitive IAppConfig');
        check(!preg_match('/__construct\\s*\\([^)]*IAppConfig/s', $config), 'Config constructor hard-depends on IAppConfig');
        check(str_contains($config, "interface_exists('\\\\OCP\\\\IAppConfig')"), 'Current Nextcloud typed app-config compatibility guard is missing');
        check(str_contains($config, "getValueString(\$app, \$key, '', true)"), 'Lazy current FullTextSearch app config is not read');
        check(
            str_contains($provider, 'getOrder(string $route, array $routeParameters): int'),
            'Unified Search order signature is not compatible with NC23-27'
        );
        check(!str_contains((string)$answer, 'array_is_list('), 'AnswerService still requires PHP 8.1 array_is_list');
        check(!str_contains((string)$normalizer, 'array_is_list('), 'SearchSpecNormalizer still requires PHP 8.1 array_is_list');
        check(!preg_match('/\\bmixed\\s+\\$/', (string)$answer), 'AnswerService still uses PHP 8 mixed types');
        check(!preg_match('/\\bmixed\\s+\\$/', (string)$normalizer), 'SearchSpecNormalizer still uses PHP 8 mixed types');
    };

    $tests['search submit button is part of browser-robust flex searchbar'] = function () use ($root): void {
        $css = file_get_contents($root . '/css/probe.css');
        check(is_string($css), 'Could not read probe.css');
        check(str_contains($css, '.sunaqfind-searchbar {'), 'Searchbar style missing');
        check(str_contains($css, 'display: flex;'), 'Searchbar is not a flex container');
        check(str_contains($css, 'overflow: hidden;'), 'Searchbar does not clip child browser styles to the common frame');
        check(str_contains($css, 'position: static;'), 'Search button still depends on absolute positioning');
        check(str_contains($css, 'flex: 0 0 48px;'), 'Search button does not occupy the full searchbar height lane');
        check(str_contains($css, 'border: 0 !important;'), 'Native input/button border overrides are not neutralized');
        check(!str_contains($css, 'top: 5px;'), 'Legacy pixel inset still affects search button');
        check(!str_contains($css, 'transform: translateY(-50%)'), 'Legacy transform centering still affects search button');
    };

    $tests['admin save explicitly persists false toggle values and confirms effective state'] = function () use ($root): void {
        $controller = file_get_contents($root . '/lib/Controller/AdminController.php');
        $script = file_get_contents($root . '/js/admin.js');
        check(is_string($controller) && is_string($script), 'Admin persistence files could not be read');
        check(str_contains($controller, '$this->request->getParams()'), 'Admin save still relies on implicit controller parameter binding');
        check(str_contains($controller, 'array_key_exists($key, $values)'), 'Admin boolean parser cannot distinguish missing values');
        check(str_contains($script, "setChecked('sunaqfind-analysis-mode', config.analysis_mode)"), 'Saved analysis mode is not reflected from server state');
        check(str_contains($script, "'Saved – analysis mode '"), 'Admin save confirmation does not report effective analysis mode');
    };

    $tests['ACL validator keeps PHP 7.4-compatible declared dependencies'] = function () use ($root): void {
        $acl = file_get_contents($root . '/lib/Service/AclValidator.php');
        check(is_string($acl), 'ACL validator source could not be read');
        check(str_contains($acl, 'private $rootFolder;'), 'ACL root-folder dependency is a dynamic property');
        check(str_contains($acl, 'private $userSession;'), 'ACL user-session dependency is a dynamic property');
        check(str_contains($acl, 'private $groupManager;'), 'ACL group-manager dependency is a dynamic property');
        check(str_contains($acl, "'reason' => 'group_lookup_failed'"), 'ACL prefilter failure reason is missing');
    };

    $tests['analysis mode is admin diagnostics and defaults off'] = function () use ($root): void {
        $template = file_get_contents($root . '/templates/admin.php');
        $config = file_get_contents($root . '/lib/Service/ConfigService.php');
        $service = file_get_contents($root . '/lib/Service/SearchService.php');
        check(is_string($template) && is_string($config) && is_string($service), 'Analysis mode sources could not be read');
        check(
            str_contains($config, "return \$this->boolValue('analysis_mode');"),
            'Analysis mode no longer defaults off'
        );
        check(
            str_contains($template, 'Administrator diagnostics for SearchSpec, retrieval and ACL checks. Keep this disabled during normal operation.'),
            'Admin UI does not describe analysis mode as temporary admin diagnostics'
        );
        check(str_contains($service, "'denied_count' => \$retrieval['denied_count']"), 'Analysis mode lost denied candidate count');
        check(str_contains($service, "'unresolved_count' => \$retrieval['unresolved_count']"), 'Analysis mode lost unresolved candidate count');
        check(str_contains($service, "'acl_prefilter_reason'"), 'Analysis mode lost ACL prefilter failure reason');
        check(str_contains($service, "'unresolved_breakdown'"), 'Analysis mode lost unresolved-candidate breakdown');
    };

    $tests['admin UI exposes deterministic retrieval controls'] = function () use ($root): void {
        $template = file_get_contents($root . '/templates/admin.php');
        $script = file_get_contents($root . '/js/admin.js');
        $config = file_get_contents($root . '/lib/Service/ConfigService.php');
        $search = file_get_contents($root . '/lib/Service/ElasticsearchSearch.php');
        check(is_string($template) && is_string($script) && is_string($config) && is_string($search), 'Retrieval control files could not be read');
        check(str_contains($template, 'Use ACL metadata prefilter'), 'ACL prefilter checkbox missing');
        check(str_contains($template, 'Maximum Elasticsearch hits'), 'Elasticsearch hit limit input missing');
        check(str_contains($template, 'Maximum documents for AI evaluation'), 'AI document limit input missing');
        check(str_contains($script, 'aclPrefilterEnabled'), 'ACL prefilter setting is not saved');
        check(str_contains($script, 'searchDocumentLimit'), 'Elasticsearch hit limit is not saved');
        check(str_contains($script, 'llmDocumentLimit'), 'AI document limit is not saved');
        check(str_contains($config, "boolValue('acl_prefilter_enabled', true)"), 'ACL prefilter does not default on');
        check(str_contains($config, "intValue('search_document_limit', 15, 1, 20)"), 'Elasticsearch hit limit does not default to 15');
        check(str_contains($config, "intValue('llm_document_limit', 12, 1, 20)"), 'AI document limit does not default to 12');
        check(!str_contains($search, 'candidateBudget'), 'Elasticsearch search still contains automatic overfetch logic');
    };

    $tests['admin UI exposes excerpt and search strategy toggles'] = function () use ($root): void {
        $template = file_get_contents($root . '/templates/admin.php');
        $script = file_get_contents($root . '/js/admin.js');
        $config = file_get_contents($root . '/lib/Service/ConfigService.php');
        check(is_string($template) && is_string($script) && is_string($config), 'Display preference files could not be read');
        check(str_contains($template, 'Show document excerpts'), 'Document excerpt checkbox missing');
        check(str_contains($template, 'Show search strategy'), 'Search strategy checkbox missing');
        check(str_contains($script, 'showDocumentExcerpts'), 'Document excerpt setting is not saved');
        check(str_contains($script, 'showSearchStrategy'), 'Search strategy setting is not saved');
        check(str_contains($config, "boolValue('show_document_excerpts', true)"), 'Document excerpts are not default-on');
        check(str_contains($config, "boolValue('show_search_strategy', true)"), 'Search strategy is not default-on');
    };

    $tests['search strategy is separated from normal search guidance'] = function (): void {
        $service = (new \ReflectionClass(SearchService::class))->newInstanceWithoutConstructor();

        $guidanceMethod = new \ReflectionMethod(SearchService::class, 'searchGuidance');
        $guidanceMethod->setAccessible(true);
        $strategyMethod = new \ReflectionMethod(SearchService::class, 'searchStrategy');
        $strategyMethod->setAccessible(true);

        $context = [
            'semantic_query' => 'Wie hoch ist der Umsatz des Beispielmuseums?',
            'entities' => ['Beispielmuseum'],
            'concept_groups' => [[
                'required' => true,
                'terms' => ['Umsatz', 'Einnahmen'],
            ]],
            'verification_requirements' => ['Treffer enthält belastbare Umsatzangaben.'],
            'alternative_interpretations' => [],
            'term_interpretations' => [],
            'required_literals' => [],
        ];

        $guidance = $guidanceMethod->invoke($service, 'Umsatz Beispielmuseum', $context);
        $strategy = $strategyMethod->invoke($service, $context);

        check(str_contains($guidance, 'Search interpretation: Wie hoch ist der Umsatz des Beispielmuseums?'), 'Search interpretation was lost');
        check(!str_contains($guidance, 'Search terms used'), 'Search terms still leak into normal guidance');
        check(!str_contains($guidance, 'Evidence was considered sufficient when'), 'Evidence criterion still leaks into normal guidance');
        check(str_contains($guidance, 'Different wording can lead'), 'General query variation guidance was lost');
        check(str_contains($strategy, 'Beispielmuseum + (Umsatz | Einnahmen)'), 'Search terms missing from separate strategy');
        check(str_contains($strategy, 'Evidence was considered sufficient when'), 'Evidence criterion missing from separate strategy');
    };

    $tests['planner reasoning experiment maps explicit modes'] = function (): void {
        $nativeHttp = new CapturingClientService();
        $native = new LlmClient(
            $nativeHttp,
            new FakeLlmConfigService('https://api.openai.com/v1', 'gpt-5.6-luna')
        );
        $native->complete([['role' => 'user', 'content' => 'test']], 900, false, 35, false, 'thinking');
        check(
            ($nativeHttp->calls[0]['body']['reasoning_effort'] ?? null) === 'medium',
            'Native GPT-5.6 thinking mode did not map to medium reasoning effort'
        );

        $compatibleHttp = new CapturingClientService();
        $compatible = new LlmClient(
            $compatibleHttp,
            new FakeLlmConfigService('http://ollama.invalid:11434/v1', 'qwen3:8b')
        );
        $compatible->complete([['role' => 'user', 'content' => 'test']], 900, false, 35, false, 'none');
        $compatible->complete([['role' => 'user', 'content' => 'test']], 900, false, 35, false, 'thinking');
        check(
            array_key_exists('think', $compatibleHttp->calls[0]['body'])
                && $compatibleHttp->calls[0]['body']['think'] === false,
            'Compatible no-thinking mode did not send think=false'
        );
        check(
            ($compatibleHttp->calls[1]['body']['think'] ?? null) === true,
            'Compatible thinking mode did not send think=true'
        );
    };

    $tests['SearchSpec normalization keeps 0.8.7 tolerant defaults'] = function (): void {
        $normalizer = new SearchSpecNormalizer();
        $spec = $normalizer->normalize([
            'retrieval_intent' => 'direct_lexical',
            'lexical_sufficiency' => 'high',
            'lexical_query' => 'Erika Beispiel Lebenslauf',
            'term_interpretations' => [[
                'original' => 'Lebenslau',
                'status' => 'probable_typo',
                'alternatives' => ['Lebenslauf'],
            ]],
            'semantic_query' => 'Erika Beispiel Lebenslauf',
            'entities' => ['Erika Beispiel'],
            'concepts' => ['Lebenslauf'],
            'concept_groups' => [[
                'required' => true,
                'terms' => ['Lebenslauf', 'CV', 'Curriculum Vitae'],
            ]],
        ], 'Erika Beispiel Lebenslauf');

        check($spec['retrieval_intent'] === 'direct_lexical', 'Retrieval intent was not retained');
        check($spec['lexical_sufficiency'] === 'high', 'Lexical sufficiency was not retained');
        check($spec['lexical_query'] === 'Erika Beispiel Lebenslauf', 'Lexical query was not retained');
        check(($spec['term_interpretations'][0]['status'] ?? '') === 'probable_typo', 'Term interpretation was not retained');
        check(($spec['term_interpretations'][0]['alternatives'] ?? []) === ['Lebenslauf'], 'Term interpretation alternatives were not retained');
        check($spec['entities'] === ['Erika Beispiel'], 'Entity was not retained');
        check($spec['must'] === [], 'Missing legacy field must did not default to []');
        check($spec['verification_requirements'] === [], 'Missing verification requirements did not default to []');
        check($spec['concept_groups'][0]['required'] === true, 'Required concept group was not retained');
    };

    $tests['sparse valid rewriter JSON does not fall back'] = function (): void {
        $llm = new FakeLlmClient(json_encode([
            'retrieval_intent' => 'direct_lexical',
            'lexical_sufficiency' => 'high',
            'lexical_query' => 'Erika Beispiel Lebenslauf',
            'semantic_query' => 'Erika Beispiel Lebenslauf',
            'entities' => ['Erika Beispiel'],
            'concepts' => ['Lebenslauf'],
            'concept_groups' => [[
                'required' => true,
                'terms' => ['Lebenslauf', 'CV', 'Curriculum Vitae'],
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $rewriter = new QueryRewriteService($llm, new SearchSpecNormalizer(), new FakeConfigService());
        $result = $rewriter->rewrite('Erika Beispiel Lebenslauf');

        check($result['fallback_used'] === false, 'Sparse valid SearchSpec triggered fallback');
        check($result['status'] === 'ok', 'Sparse valid SearchSpec did not report ok');
        check($result['spec']['retrieval_intent'] === 'direct_lexical', 'Routing recommendation missing after rewrite');
        check($result['spec']['lexical_sufficiency'] === 'high', 'Lexical sufficiency missing after rewrite');
        check($result['spec']['lexical_query'] === 'Erika Beispiel Lebenslauf', 'Lexical query missing after rewrite');
        check($result['spec']['entities'] === ['Erika Beispiel'], 'Entity missing after rewrite');
        $serialized = json_encode($llm->calls[0]['messages'], JSON_UNESCAPED_UNICODE);
        check(str_contains((string)$llm->calls[0]['messages'][1]['content'], '"retrieval_languages":["de"]'), 'Retrieval language was not sent to planner');
        check(str_contains((string)$llm->calls[0]['messages'][1]['content'], '"interaction_language":"en"'), 'Interaction language was not sent to planner');
        check(str_contains((string)$llm->calls[0]['messages'][0]['content'], 'answer-slot labels'), 'Lexical expansion guardrails missing from planner prompt');
        check(str_contains((string)$llm->calls[0]['messages'][0]['content'], 'lexical_sufficiency'), 'Lexical sufficiency contract missing from planner prompt');
        check(str_contains((string)$llm->calls[0]['messages'][0]['content'], 'term_interpretations'), 'Term interpretation contract missing from planner prompt');
        check(str_contains((string)$llm->calls[0]['messages'][0]['content'], 'CONSISTENCY'), 'Interpretation consistency contract missing from planner prompt');
        check(str_contains((string)$llm->calls[0]['messages'][0]['content'], 'Erika Beispiel Geburtstag'), 'Birthday interpretation regression example missing from planner prompt');
        check(str_contains((string)$llm->calls[0]['messages'][0]['content'], 'Feier'), 'Birthday celebration contrast example missing from planner prompt');
        check(str_contains((string)$llm->calls[0]['messages'][0]['content'], 'alternative_interpretations'), 'Alternative interpretation UI contract missing from planner prompt');
        check(str_contains((string)$llm->calls[0]['messages'][0]['content'], 'actual search request'), 'Search-content distinction missing from planner prompt');
        check(str_contains((string)$llm->calls[0]['messages'][0]['content'], 'instructions about how the request must be interpreted'), 'User-directive distinction missing from planner prompt');
        check(str_contains((string)$llm->calls[0]['messages'][0]['content'], 'Separate these before building the SearchSpec'), 'Planner does not explicitly separate search content from directives');
        check(!str_contains((string)$llm->calls[0]['messages'][0]['content'], 'query_control'), 'Obsolete query-control contract survived in planner prompt');
        check(str_contains((string)$llm->calls[0]['messages'][0]['content'], 'normalization:'), 'Normalization status missing from planner prompt');
        check(str_contains((string)$llm->calls[0]['messages'][0]['content'], 'preserved:'), 'Preserved status missing from planner prompt');
        check(!str_contains((string)$llm->calls[0]['messages'][0]['content'], 'Grundbauchauszu Beispielstrasse 44'), 'Anchoring typo few-shot survived in planner prompt');
    };

    $tests['planner directives remain LLM-owned without phrase-specific override'] = function () use ($root): void {
        $rewriterSource = file_get_contents($root . '/lib/Service/QueryRewriteService.php');
        check(is_string($rewriterSource), 'Could not read QueryRewriteService.php');
        check(!str_contains($rewriterSource, 'applyExplicitLiteralOverride'), 'Phrase-specific literal override returned to the rewriter');
        check(!str_contains($rewriterSource, 'explicitLiteralOverride'), 'Phrase-specific directive parser returned to the rewriter');
        check(str_contains($rewriterSource, 'actual search request'), 'General search-content/directive distinction is missing');
        check(str_contains($rewriterSource, 'instructions about how the request must be interpreted'), 'General user-directive handling is missing');
    };

    $tests['term interpretations normalize transformations and require original text'] = function (): void {
        $normalizer = new SearchSpecNormalizer();
        $query = 'Grunbuch Beispielgemeinschaft 13. August';
        $spec = $normalizer->normalize([
            'term_interpretations' => [[
                'original' => 'Grunbuch',
                'status' => 'probable_typo',
                'alternatives' => ['Grundbuch'],
            ], [
                'original' => '13. August',
                'status' => 'normalization',
                'alternatives' => ['13.08.'],
            ], [
                'original' => 'Beispielgemeinschaft',
                'status' => 'preserved',
                'alternatives' => ['Arbeits-Gemeinschaft'],
            ], [
                'original' => 'Nicht im Query',
                'status' => 'normalization',
                'alternatives' => ['ignored'],
            ]],
        ], $query);

        check(!array_key_exists('query_control', $spec), 'Obsolete query_control survived normalization');
        check(count($spec['term_interpretations']) === 3, 'Valid transformation metadata was not retained');
        check(($spec['term_interpretations'][0]['status'] ?? '') === 'probable_typo', 'probable_typo status was lost');
        check(($spec['term_interpretations'][1]['status'] ?? '') === 'normalization', 'normalization status was lost');
        check(($spec['term_interpretations'][2]['status'] ?? '') === 'preserved', 'preserved status was lost');
        check(
            ($spec['term_interpretations'][1]['alternatives'] ?? []) === ['13.08.'],
            'Normalization alternative was lost'
        );
    };

    $tests['preserved term interpretation does not auto-correct retrieval'] = function (): void {
        $llm = new FakeLlmClient(json_encode([
            'retrieval_intent' => 'planned_semantic',
            'lexical_sufficiency' => 'medium',
            'lexical_query' => 'Erika Beispiel Gebirtstag',
            'term_interpretations' => [[
                'original' => 'Gebirtstag',
                'status' => 'preserved',
                'alternatives' => ['Geburtstag'],
            ]],
            'semantic_query' => 'Wann wurde Erika Beispiel geboren?',
            'alternative_interpretations' => [],
            'entities' => ['Erika Beispiel'],
            'concepts' => ['Geburtsdatum'],
            'concept_groups' => [[
                'required' => true,
                'terms' => ['Gebirtstag', 'Geburtsdatum', 'geboren', 'geb.'],
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $query = 'Wann hat Erika Beispiel Gebirtstag? Gebirtstag ist kein Tippfehler.';
        $rewriter = new QueryRewriteService($llm, new SearchSpecNormalizer(), new FakeConfigService());
        $result = $rewriter->rewrite($query);
        $spec = $result['spec'];

        check(($spec['term_interpretations'][0]['status'] ?? '') === 'preserved', 'Preserved user spelling was lost');
        check($spec['semantic_query'] === 'Wann wurde Erika Beispiel geboren?', 'Preserved spelling disabled semantic interpretation');
        check($spec['concepts'] === ['Geburtsdatum'], 'Preserved spelling disabled semantic concepts');
        check(
            ($spec['concept_groups'][0]['terms'] ?? []) === ['Gebirtstag', 'Geburtsdatum', 'geboren', 'geb.'],
            'Preserved spelling changed declared concept expansion'
        );
    };

    $tests['alternative interpretations normalize as bounded UI metadata'] = function (): void {
        $spec = (new SearchSpecNormalizer())->normalize([
            'semantic_query' => 'Wann feiert Erika Beispiel Geburtstag?',
            'alternative_interpretations' => [
                ['meaning' => 'Geburtsdatum', 'query' => 'Wann hat Erika Beispiel Geburtstag?'],
                ['meaning' => 'Jahrestag', 'query' => 'Welchen Jahrestag meint Erika Beispiel?'],
                ['meaning' => 'ignored', 'query' => 'Dieser dritte Eintrag wird verworfen'],
                ['meaning' => '', 'query' => 'ungültig'],
            ],
            'entities' => ['Erika Beispiel'],
            'concept_groups' => [[
                'required' => true,
                'terms' => ['Geburtstagsfeier', 'Feier', 'Einladung'],
            ]],
        ], 'Erika Beispiel Geburtstag');

        check(count($spec['alternative_interpretations']) === 2, 'Alternative interpretations are not capped at two');
        check(($spec['alternative_interpretations'][0]['meaning'] ?? '') === 'Geburtsdatum', 'Alternative meaning was lost');
        check(($spec['alternative_interpretations'][0]['query'] ?? '') === 'Wann hat Erika Beispiel Geburtstag?', 'Alternative query was lost');

        $plan = (new RetrievalPlanner(new HardConstraintExtractor()))
            ->planFromSearchSpec($spec, 'Erika Beispiel Geburtstag');
        $rendered = json_encode((new ElasticsearchCompiler())->compile($plan, 'Erika Beispiel Geburtstag'), JSON_UNESCAPED_UNICODE);
        check(!str_contains((string)$rendered, 'Wann hat Erika Beispiel Geburtstag?'), 'UI-only alternative leaked into retrieval');
        check(str_contains((string)$rendered, 'Geburtstagsfeier'), 'Chosen interpretation disappeared from retrieval');
    };

    $tests['direct lexical routing ignores semantic MUST expansion'] = function (): void {
        $spec = (new SearchSpecNormalizer())->normalize([
            'retrieval_intent' => 'direct_lexical',
            'lexical_sufficiency' => 'high',
            'lexical_query' => 'Example Real Estate GmbH',
            'semantic_query' => 'Example Real Estate GmbH',
            'entities' => ['Example Real Estate GmbH'],
            'concepts' => ['Immobilienfirma'],
            'concept_groups' => [[
                'required' => true,
                'terms' => ['Immobilienfirma', 'Real Estate Company'],
            ]],
        ], 'Example Real Estate GmbH');

        $plan = (new RetrievalPlanner(new HardConstraintExtractor()))
            ->planFromSearchSpec($spec, 'Example Real Estate GmbH', false, true);
        $body = (new ElasticsearchCompiler())->compile($plan, 'Example Real Estate GmbH');
        $rendered = json_encode($body['query'], JSON_UNESCAPED_UNICODE);

        check($plan['search_mode'] === 'direct_lexical', 'High/direct recommendation did not select direct lexical mode');
        check(($plan['direct_queries'] ?? []) === ['Example Real Estate GmbH'], 'Direct lexical query changed unexpectedly');
        check(str_contains((string)$rendered, 'Example Real Estate GmbH'), 'Direct lexical query is missing from compiler output');
        check(!str_contains((string)$rendered, 'Immobilienfirma'), 'Semantic concept leaked into direct lexical retrieval');
        check(!str_contains((string)$rendered, 'Real Estate Company'), 'Semantic expansion leaked into direct lexical retrieval');
    };

    $tests['direct lexical routing adds declared typo and normalization alternatives'] = function (): void {
        $spec = (new SearchSpecNormalizer())->normalize([
            'retrieval_intent' => 'direct_lexical',
            'lexical_sufficiency' => 'high',
            'lexical_query' => 'Grunbuch Beispielstrasse 44',
            'term_interpretations' => [[
                'original' => 'Grunbuch',
                'status' => 'probable_typo',
                'alternatives' => ['Grundbuch'],
            ], [
                'original' => 'Beispielstrasse',
                'status' => 'normalization',
                'alternatives' => ['Beispielstraße'],
            ], [
                'original' => '44',
                'status' => 'preserved',
                'alternatives' => ['vierundvierzig'],
            ]],
        ], 'Grunbuch Beispielstrasse 44');

        $plan = (new RetrievalPlanner(new HardConstraintExtractor()))
            ->planFromSearchSpec($spec, 'Grunbuch Beispielstrasse 44', false, true);

        check($plan['search_mode'] === 'direct_lexical', 'Transformation case did not select direct lexical mode');
        check(($plan['direct_queries'] ?? []) === [
            'Grunbuch Beispielstrasse 44',
            'Grundbuch Beispielstraße 44',
        ], 'Direct lexical transformations are not conservative/additive');
    };

    $tests['quoted user term becomes required literal without disabling semantic expansion'] = function (): void {
        $query = 'Wann hat Erika Beispiel "Gebirtstag"?';
        $spec = (new SearchSpecNormalizer())->normalize([
            'retrieval_intent' => 'planned_semantic',
            'lexical_sufficiency' => 'medium',
            'lexical_query' => 'Wann hat Erika Beispiel Gebirtstag',
            'semantic_query' => 'Wann wurde Erika Beispiel geboren?',
            'entities' => ['Erika Beispiel'],
            'concepts' => ['Geburtsdatum'],
            'concept_groups' => [[
                'required' => true,
                'terms' => ['Geburtsdatum', 'geboren', 'geb.'],
            ]],
        ], $query);

        $plan = (new RetrievalPlanner(new HardConstraintExtractor()))
            ->planFromSearchSpec($spec, $query, false, true);

        check(($plan['required_literals'] ?? []) === ['Gebirtstag'], 'Quoted literal was not extracted');
        $rendered = json_encode((new ElasticsearchCompiler())->compile($plan, $query), JSON_UNESCAPED_UNICODE);
        check(str_contains((string)$rendered, 'match_phrase'), 'Quoted literal was not compiled as a phrase');
        check(str_contains((string)$rendered, 'Gebirtstag'), 'Quoted literal is missing from compiled query');
        check(str_contains((string)$rendered, 'Geburtsdatum'), 'Semantic expansion was incorrectly disabled by quoting');
    };

    $tests['quoted literal remains required in fallback mode'] = function (): void {
        $query = 'Wann hat Erika Beispiel "Gebirtstag"?';
        $spec = (new SearchSpecNormalizer())->fallback($query);
        $plan = (new RetrievalPlanner(new HardConstraintExtractor()))
            ->planFromSearchSpec($spec, $query, true, true);

        check(($plan['required_literals'] ?? []) === ['Gebirtstag'], 'Fallback lost quoted literal');
        $body = (new ElasticsearchCompiler())->compile($plan, $query);
        $must = json_encode($body['query']['bool']['must'], JSON_UNESCAPED_UNICODE);
        check(str_contains((string)$must, 'Gebirtstag'), 'Fallback compiler did not require quoted literal');
    };

    $tests['escaped quotes are not treated as required-literal syntax'] = function (): void {
        $query = 'Wann hat Erika Beispiel \\"Gebirtstag\\"?';
        $spec = (new SearchSpecNormalizer())->fallback($query);
        $plan = (new RetrievalPlanner(new HardConstraintExtractor()))
            ->planFromSearchSpec($spec, $query, true, true);

        check(($plan['required_literals'] ?? []) === [], 'Escaped quotes unexpectedly activated literal syntax');
    };

    $tests['invalid JSON uses broad original-query fallback mode'] = function (): void {
        $llm = new FakeLlmClient('not json');
        $rewriter = new QueryRewriteService($llm, new SearchSpecNormalizer(), new FakeConfigService());
        $result = $rewriter->rewrite('Beispielweg 280');

        check($result['fallback_used'] === true, 'Invalid JSON did not trigger fallback');
        check($result['status'] === 'invalid_json', 'Invalid JSON status is wrong');
        check($result['spec']['semantic_query'] === 'Beispielweg 280', 'Fallback lost original query');
        check($result['spec']['lexical_query'] === 'Beispielweg 280', 'Fallback lost original lexical query');
        check($result['spec']['retrieval_intent'] === 'unknown', 'Fallback invented a routing recommendation');
        check($result['spec']['lexical_sufficiency'] === 'unknown', 'Fallback invented lexical sufficiency');
        check($result['spec']['should'] === [], 'Fallback invented a full-query phrase term');
    };

    $tests['planner mirrors 0.8.7 entity concept and safe constraint groups'] = function (): void {
        $normalizer = new SearchSpecNormalizer();
        $spec = $normalizer->normalize([
            'semantic_query' => 'Rechnungen examplehost 2025',
            'entities' => ['examplehost'],
            'concepts' => ['Rechnung'],
            'concept_groups' => [[
                'required' => true,
                'terms' => ['Rechnung', 'Invoice'],
            ]],
        ], 'Rechnungen examplehost 2025');

        $plan = (new RetrievalPlanner(new HardConstraintExtractor()))
            ->planFromSearchSpec($spec, 'Rechnungen examplehost 2025');

        $rendered = json_encode($plan['lexical_groups'], JSON_UNESCAPED_UNICODE);
        check(str_contains((string)$rendered, 'examplehost'), 'Entity group missing');
        check(str_contains((string)$rendered, 'Invoice'), 'Concept alternative missing');
        check(str_contains((string)$rendered, '2025'), 'Safe year constraint missing');
        check($plan['retrieval_arms'] === ['files'], 'Prototype must activate only files arm');
        check(in_array('vector', RetrievalPlanner::ARM_HOOKS, true), 'Vector hook is not reserved');
        check(in_array('graph', RetrievalPlanner::ARM_HOOKS, true), 'Graph hook is not reserved');
    };

    $tests['fallback compiler uses 0.8.7 broad original question clause'] = function (): void {
        $plan = (new RetrievalPlanner(new HardConstraintExtractor()))
            ->planFromSearchSpec((new SearchSpecNormalizer())->fallback('Erika Beispiel Lebenslauf'), 'Erika Beispiel Lebenslauf', true);

        $body = (new ElasticsearchCompiler())->compile($plan, 'Erika Beispiel Lebenslauf');
        $rendered = json_encode($body['query'], JSON_UNESCAPED_UNICODE);

        check(str_contains((string)$rendered, 'multi_match'), 'Broad original-question multi_match is missing');
        check(str_contains((string)$rendered, 'combined^2'), '0.8.7 combined field weighting is missing');
        check(!str_contains((string)$rendered, 'match_phrase_prefix":{"content":"Erika Beispiel Lebenslauf'), 'Fallback became one full-query phrase-prefix');
    };

    $tests['structured Erika query compiles entity AND concept alternatives'] = function (): void {
        $spec = (new SearchSpecNormalizer())->normalize([
            'semantic_query' => 'Erika Beispiel Lebenslauf',
            'entities' => ['Erika Beispiel'],
            'concepts' => ['Lebenslauf'],
            'concept_groups' => [[
                'required' => true,
                'terms' => ['Lebenslauf', 'CV', 'Curriculum Vitae'],
            ]],
        ], 'Erika Beispiel Lebenslauf');

        $plan = (new RetrievalPlanner(new HardConstraintExtractor()))
            ->planFromSearchSpec($spec, 'Erika Beispiel Lebenslauf');
        $body = (new ElasticsearchCompiler())->compile($plan, 'Erika Beispiel Lebenslauf');

        $must = json_encode($body['query']['bool']['must'], JSON_UNESCAPED_UNICODE);
        check(str_contains((string)$must, 'Erika Beispiel'), 'Entity is absent from compiler output');
        check(str_contains((string)$must, 'Curriculum Vitae'), 'Concept alternative is absent');
    };

    $tests['raw LLM Elasticsearch DSL cannot pass SearchSpec boundary'] = function (): void {
        $normalizer = new SearchSpecNormalizer();
        $spec = $normalizer->normalize([
            'semantic_query' => 'safe',
            'query' => ['bool' => ['must' => [['script' => 'danger']]]],
        ], 'safe');

        check(!array_key_exists('query', $spec), 'Unknown raw DSL field survived normalization');

        $plan = (new RetrievalPlanner(new HardConstraintExtractor()))
            ->planFromSearchSpec($spec, 'safe', true);
        $body = (new ElasticsearchCompiler())->compile($plan, 'safe');
        $rendered = json_encode($body, JSON_UNESCAPED_UNICODE);
        check(!str_contains((string)$rendered, 'script'), 'Raw DSL reached compiler output');
    };

    $tests['quoted legacy elastic query keeps Nextcloud token semantics'] = function (): void {
        $spec = (new SearchSpecNormalizer())->normalize([
            'semantic_query' => 'test',
            'elastic_query' => '+"Project Alpha" -"Secret Plan" Rechnung',
        ], 'test');
        $plan = (new RetrievalPlanner(new HardConstraintExtractor()))
            ->planFromSearchSpec($spec, 'test');
        $body = (new ElasticsearchCompiler())->compile($plan, 'test');

        $must = json_encode($body['query']['bool']['must'], JSON_UNESCAPED_UNICODE);
        $excluded = json_encode($body['query']['bool']['must_not'], JSON_UNESCAPED_UNICODE);
        check(str_contains((string)$must, 'Project Alpha'), 'Required quoted legacy term missing');
        check(str_contains((string)$excluded, 'Secret Plan'), 'Excluded quoted legacy term missing');
    };

    $tests['evidence builder respects configured document limit'] = function (): void {
        $hits = [];
        for ($id = 1; $id <= 4; $id++) {
            $hits[] = [
                'authorized' => true,
                'file_id' => $id,
                'name' => 'doc-' . $id . '.pdf',
                'path' => '/doc-' . $id . '.pdf',
                'snippet' => 'authorized content ' . $id,
                'url' => '/f/' . $id,
            ];
        }

        $built = (new EvidenceBuilder())->build($hits, 2);
        check(count($built['documents']) === 2, 'Configured evidence document limit was ignored');
        check(count($built['sources']) === 2, 'Source list exceeded configured evidence document limit');
    };

    $tests['ACL denied candidate disappears from evidence'] = function (): void {
        $built = (new EvidenceBuilder())->build([
            [
                'authorized' => false,
                'file_id' => 1,
                'name' => 'TOP SECRET denied.pdf',
                'path' => '/secret',
                'snippet' => 'classified denied content',
                'url' => '/f/1',
            ],
            [
                'authorized' => true,
                'file_id' => 2,
                'name' => 'allowed.pdf',
                'path' => '/allowed.pdf',
                'snippet' => 'authorized content',
                'url' => '/f/2',
            ],
        ]);

        check(!str_contains($built['json'], 'TOP SECRET'), 'Denied name leaked into evidence');
        check(!str_contains($built['json'], 'classified'), 'Denied snippet leaked into evidence');
        check(count($built['sources']) === 1 && $built['sources'][0]['file_id'] === 2, 'Denied source was retained');
    };

    $tests['live ACL gate removes denied Elasticsearch hit before evidence'] = function (): void {
        $client = new StaticClientService([
            'hits' => ['hits' => [
                [
                    '_id' => 'files:1',
                    '_score' => 10,
                    'highlight' => ['content' => ['classified denied content']],
                ],
                [
                    '_id' => 'files:2',
                    '_score' => 9,
                    'highlight' => ['content' => ['authorized content']],
                ],
            ]],
        ]);

        $plan = (new RetrievalPlanner(new HardConstraintExtractor()))
            ->planFromSearchSpec(
                (new SearchSpecNormalizer())->normalize([
                    'semantic_query' => 'content',
                    'entities' => ['content'],
                ], 'content'),
                'content'
            );

        $search = new ElasticsearchSearch(
            $client,
            new FakeConfigService(),
            new SelectiveAclValidator(),
            new ElasticsearchCompiler()
        );
        $retrieval = $search->search($plan, 'content');
        $evidence = (new EvidenceBuilder())->build($retrieval['hits']);

        check($retrieval['candidate_count'] === 2, 'Expected two ES candidates');
        check($retrieval['allowed_count'] === 1 && $retrieval['denied_count'] === 1, 'ACL counts are wrong');
        check(!str_contains($evidence['json'], 'classified'), 'Denied ES snippet reached evidence');
        check(str_contains($evidence['json'], 'authorized content'), 'Authorized ES snippet is missing');
    };

    $tests['answerer receives authorized evidence and uses 90 second timeout'] = function (): void {
        $evidence = (new EvidenceBuilder())->build([
            ['authorized' => false, 'file_id' => 1, 'name' => 'denied.pdf', 'snippet' => 'secret'],
            ['authorized' => true, 'file_id' => 2, 'name' => 'allowed.pdf', 'path' => '/allowed.pdf', 'snippet' => 'visible'],
        ]);
        $llm = new FakeLlmClient('{"answer_found":false,"answer":"Keine belastbare Antwort [1]","entity_interpretations":[{"query_entity":"Erika","interpreted_as":"Erika Beispiel","citations":[1]}]}');
        $assessment = (new AnswerService($llm))->answerWithAssessment('Frage?', $evidence['json'], [
            'semantic_query' => 'Welche Schule wurde besucht?',
            'entities' => ['Erika'],
            'concept_groups' => [['required' => true, 'terms' => ['Schule', 'Schulbildung']]],
            'verification_requirements' => ['Dokument nennt die besuchte Schule'],
        ]);
        $call = $llm->calls[0];
        $serialized = json_encode($call['messages'], JSON_UNESCAPED_UNICODE);

        check($assessment['answer'] === 'Keine belastbare Antwort [1]', 'Answer was changed');
        check($assessment['answer_found'] === false, 'Negative answer assessment was lost');
        check(($assessment['entity_interpretations'][0]['interpreted_as'] ?? '') === 'Erika Beispiel', 'Evidence-based entity interpretation was lost');
        check(($assessment['entity_interpretations'][0]['citations'] ?? []) === [1], 'Entity interpretation citations were lost');
        check($call['timeout'] === 90, 'Answer timeout is not 90 seconds');
        check($call['json_mode'] === true, 'Answer assessment is not requested as JSON');
        check(str_contains((string)$call['messages'][0]['content'], AnswerService::UNTRUSTED_EVIDENCE_GUARD), 'Evidence guard missing');
        check(str_contains((string)$serialized, 'allowed.pdf'), 'Authorized evidence missing');
        check(!str_contains((string)$serialized, 'denied.pdf'), 'Denied evidence reached answerer');
        check(!str_contains((string)$serialized, 'secret'), 'Denied snippet reached answerer');
        check(str_contains((string)$serialized, 'SEARCH_CONTEXT_JSON'), 'Search context envelope missing');
        check(str_contains((string)$serialized, 'INTERACTION_LANGUAGE: en'), 'Answerer did not receive interaction language');
        check(str_contains((string)$serialized, 'Schulbildung'), 'Search context did not reach answerer');
    };

    $tests['answerer drops entity interpretations not present in search context'] = function (): void {
        $evidence = (new EvidenceBuilder())->build([
            ['authorized' => true, 'file_id' => 2, 'name' => 'rumford.pdf', 'path' => '/rumford.pdf', 'snippet' => 'Example Real Estate GmbH; Max Beispiel Geschäftsführer'],
        ]);
        $llm = new FakeLlmClient('{"answer_found":true,"answer":"Example Real Estate GmbH [1]","entity_interpretations":[{"query_entity":"Example Real Estate GmbH","interpreted_as":"Gesellschaft mit Sitz in München","citations":[1]},{"query_entity":"Max Beispiel","interpreted_as":"Geschäftsführer","citations":[1]}]}');
        $assessment = (new AnswerService($llm))->answerWithAssessment('Example Real Estate GmbH', $evidence['json'], [
            'entities' => ['Example Real Estate GmbH'],
        ]);

        check(count($assessment['entity_interpretations']) === 1, 'Non-query entity interpretation was not removed');
        check(($assessment['entity_interpretations'][0]['query_entity'] ?? '') === 'Example Real Estate GmbH', 'Allowed query entity interpretation was lost');
        $prompt = (string)$llm->calls[0]['messages'][0]['content'];
        check(str_contains($prompt, 'Use hedging'), 'Direct-evidence wording guardrail missing');
        check(str_contains($prompt, 'name similarity'), 'Entity-only anti-speculation guardrail missing');
    };

    $tests['identical model alternative search is suppressed'] = function (): void {
        $service = (new \ReflectionClass(SearchService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(SearchService::class, 'alternativeQueries');
        $method->setAccessible(true);
        $queries = $method->invoke($service, 'Erika Beispiel Geburtstag', [
            'lexical_query' => 'Erika Beispiel Geburtstag',
            'semantic_query' => 'Wann hat Erika Beispiel Geburtstag?',
            'required_literals' => [],
            'alternative_interpretations' => [[
                'meaning' => 'Geburtsdatum',
                'query' => 'Wann hat Erika Beispiel Geburtstag?',
            ], [
                'meaning' => 'Geburtstagsfeier',
                'query' => 'Wann feiert Erika Beispiel Geburtstag?',
            ]],
        ]);

        check($queries === ['Wann feiert Erika Beispiel Geburtstag?'], 'Identical model alternative was not suppressed');
    };

    $tests['quoted preserved term offers declared alternative as clickable search'] = function (): void {
        $service = (new \ReflectionClass(SearchService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(SearchService::class, 'alternativeQueries');
        $method->setAccessible(true);
        $queries = $method->invoke($service, 'Wann hat Erika Beispiel "Gebirtstag"?', [
            'semantic_query' => 'Wann hat Erika Beispiel Geburtstag?',
            'required_literals' => ['Gebirtstag'],
            'term_interpretations' => [[
                'original' => 'Gebirtstag',
                'status' => 'preserved',
                'alternatives' => ['Geburtstag'],
            ]],
            'alternative_interpretations' => [],
        ]);

        check($queries === ['Wann hat Erika Beispiel Geburtstag?'], 'Quoted preserved term did not expose declared alternative');
    };

    $tests['declared typo offers literal original spelling as counter-search'] = function (): void {
        $service = (new \ReflectionClass(SearchService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(SearchService::class, 'alternativeQueries');
        $method->setAccessible(true);
        $queries = $method->invoke($service, 'Wann hat Erika Beispiel Gebirtstag?', [
            'semantic_query' => 'Wann wurde Erika Beispiel geboren?',
            'required_literals' => [],
            'term_interpretations' => [[
                'original' => 'Gebirtstag',
                'status' => 'probable_typo',
                'alternatives' => ['Geburtstag'],
            ]],
            'alternative_interpretations' => [],
        ]);

        check($queries === ['Wann hat Erika Beispiel "Gebirtstag"?'], 'Declared typo did not expose literal original spelling');
    };

    $tests['declared normalization offers literal original spelling as counter-search'] = function (): void {
        $service = (new \ReflectionClass(SearchService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(SearchService::class, 'alternativeQueries');
        $method->setAccessible(true);
        $queries = $method->invoke($service, 'Adresse Beispielgemeinschaft 13. August', [
            'semantic_query' => 'Adresse Beispielgemeinschaft 13. August',
            'required_literals' => [],
            'term_interpretations' => [[
                'original' => '13. August',
                'status' => 'normalization',
                'alternatives' => ['13.08.'],
            ]],
            'alternative_interpretations' => [],
        ]);

        check(
            $queries === ['Adresse Beispielgemeinschaft "13. August"'],
            'Declared normalization did not expose literal original spelling'
        );
    };

    $tests['missing transformation metadata does not invent correction alternatives'] = function (): void {
        $service = (new \ReflectionClass(SearchService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(SearchService::class, 'alternativeQueries');
        $method->setAccessible(true);
        $queries = $method->invoke($service, 'Grundbuch Beispielgemeinschaft 13. Augist', [
            'lexical_query' => 'Grundbuch Beispielgemeinschaft 13. Augist',
            'semantic_query' => 'Grundbuch Beispielgemeinschaft 13. Augist',
            'required_literals' => [],
            'entities' => ['Beispielgemeinschaft'],
            'concept_groups' => [[
                'required' => true,
                'terms' => ['Grundbuchauszug', 'Grundbuch'],
            ]],
            'term_interpretations' => [],
            'alternative_interpretations' => [],
        ]);

        check($queries === [], 'Server invented a correction alternative without term_interpretations');
    };

    $tests['model alternative interpretations remain independent from term transformations'] = function (): void {
        $service = (new \ReflectionClass(SearchService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(SearchService::class, 'alternativeQueries');
        $method->setAccessible(true);
        $queries = $method->invoke($service, 'Erika Beispiel Geburtstag', [
            'semantic_query' => 'Wann wurde Erika Beispiel geboren?',
            'required_literals' => [],
            'term_interpretations' => [],
            'alternative_interpretations' => [[
                'meaning' => 'Geburtstagsfeier',
                'query' => 'Wann feiert Erika Beispiel Geburtstag?',
            ]],
        ]);

        check(
            $queries === ['Wann feiert Erika Beispiel Geburtstag?'],
            'Model-level alternative interpretation was lost'
        );
    };

    $tests['direct lexical strategy shows executed queries instead of unused concepts'] = function (): void {
        $service = (new \ReflectionClass(SearchService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(SearchService::class, 'searchStrategy');
        $method->setAccessible(true);
        $strategy = $method->invoke($service, [
            'entities' => ['Grunbuch Beispielgemeinschaft'],
            'concept_groups' => [[
                'required' => true,
                'terms' => ['Grunbuchauszug'],
            ]],
            'required_literals' => [],
            'search_mode' => 'direct_lexical',
            'direct_queries' => [
                'Grunbuch Beispielgemeinschaft',
                'Grundbuch Beispielgemeinschaft',
            ],
            'verification_requirements' => [],
        ]);

        check(str_contains($strategy, 'Grunbuch Beispielgemeinschaft | Grundbuch Beispielgemeinschaft'), 'Executed direct queries are missing from strategy');
        check(!str_contains($strategy, 'Grunbuchauszug'), 'Unused semantic concept leaked into direct lexical strategy');
    };

    $tests['zero-evidence wording does not expose ACL visibility concepts'] = function () use ($root): void {
        $service = file_get_contents($root . '/lib/Service/SearchService.php');
        check(is_string($service), 'Could not read SearchService.php');
        check(
            str_contains($service, 'The current search interpretation did not find sufficient evidence.'),
            'Neutral zero-evidence wording is missing'
        );
        check(
            !str_contains($service, 'in den für Sie sichtbaren Suchtreffern'),
            'Zero-evidence wording still exposes ACL visibility concepts'
        );
    };

    $tests['negative answer search guidance exposes planner parameters'] = function (): void {
        $service = (new \ReflectionClass(SearchService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(SearchService::class, 'searchGuidance');
        $method->setAccessible(true);
        $guidance = $method->invoke($service, 'Wo ist Erika zur Schule gegangen?', [
            'semantic_query' => 'Welche Schule hat Erika besucht?',
            'alternative_interpretations' => [[
                'meaning' => 'Ausbildungsstätte',
                'query' => 'Welche Ausbildungsstätte hat Erika besucht?',
            ]],
            'term_interpretations' => [[
                'original' => 'Schuhle',
                'status' => 'probable_typo',
                'alternatives' => ['Schule'],
            ]],
            'entities' => ['Erika'],
            'concept_groups' => [[
                'required' => true,
                'terms' => ['Schule', 'Schulbildung', 'Schulbesuch'],
            ]],
            'verification_requirements' => ['Dokument nennt die besuchte Schule'],
        ]);

        check(str_contains($guidance, 'Searched for'), 'Search guidance heading missing');
        check(str_contains($guidance, 'Welche Schule hat Erika besucht?'), 'Semantic query missing from guidance');
        check(!str_contains($guidance, 'Erika + (Schule | Schulbildung | Schulbesuch)'), 'Search terms leaked into normal guidance');
        check(!str_contains($guidance, 'Dokument nennt die besuchte Schule'), 'Verification requirement leaked into normal guidance');
        check(!str_contains($guidance, 'Entitätszuordnung'), 'Debug entity interpretation leaked into normal guidance');
        check(str_contains($guidance, 'Schuhle'), 'Term interpretation hint missing from guidance');
        check(str_contains($guidance, 'Schule'), 'Term interpretation alternative missing from guidance');
        check(!str_contains($guidance, 'Search terms used'), 'Search strategy still appears inline in guidance');
        check(str_contains($guidance, 'Possible alternative search: Welche Ausbildungsstätte hat Erika besucht?'), 'Alternative search text missing from guidance');
        check(!str_contains($guidance, 'Possible alternative search: [Welche Schule hat Erika besucht?]'), 'Chosen semantic query is still exposed as an alternative');
        check(str_contains($guidance, 'Different wording can lead'), 'Query-variation note missing from guidance');
        $variationPos = strpos($guidance, 'Different wording can lead');
        $alternativePos = strpos($guidance, 'Possible alternative search: Welche Ausbildungsstätte hat Erika besucht?');
        check(
            $variationPos !== false && $alternativePos !== false && $variationPos < $alternativePos,
            'Query-variation note must appear before concrete alternative searches'
        );
    };

    $tests['preserved term interpretation is visible in normal search guidance'] = function (): void {
        $service = (new \ReflectionClass(SearchService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(SearchService::class, 'searchGuidance');
        $method->setAccessible(true);
        $guidance = $method->invoke($service, 'Wann hat Erika Beispiel Gebirtstag?', [
            'lexical_query' => 'Wann hat Erika Beispiel Gebirtstag?',
            'semantic_query' => 'Wann wurde Erika Beispiel geboren?',
            'term_interpretations' => [[
                'original' => 'Gebirtstag',
                'status' => 'preserved',
                'alternatives' => ['Geburtstag'],
            ]],
            'entities' => ['Erika Beispiel'],
            'concept_groups' => [[
                'required' => true,
                'terms' => ['Gebirtstag', 'Geburtsdatum', 'geboren', 'geb.'],
            ]],
            'required_literals' => [],
            'alternative_interpretations' => [],
            'verification_requirements' => [],
        ]);

        check(str_contains($guidance, 'preserved in its original form'), 'Preserved-term hint is missing');
        check(str_contains($guidance, 'Geburtstag'), 'Preserved-term alternative is not explained');
    };

    $tests['LLM error is sanitized'] = function (): void {
        $llm = new FakeLlmClient(new \RuntimeException('api-key=secret'));
        $exception = expectException(
            fn() => (new AnswerService($llm))->answer('Frage?', '{"records":[]}'),
            \RuntimeException::class
        );
        check(!str_contains($exception->getMessage(), 'secret'), 'LLM error leaked upstream detail');
        check(
            !str_contains((string)($exception->getPrevious()?->getMessage() ?? ''), 'secret'),
            'Sanitized answer diagnostic leaked upstream detail'
        );
        check(
            ($exception->getPrevious()?->getMessage() ?? '') === 'LLM answer generation failed',
            'Unknown upstream answer error was not reduced to a safe diagnostic'
        );
    };

    $tests['answerer preserves safe rate-limit diagnostic for analysis mode'] = function (): void {
        $llm = new FakeLlmClient(new \RuntimeException('LLM rate limit or usage quota exceeded (HTTP 429)'));
        $exception = expectException(
            fn() => (new AnswerService($llm))->answer('Frage?', '{"records":[]}'),
            \RuntimeException::class
        );

        check(
            $exception->getMessage() === 'The LLM answer could not be generated. Authorized sources remain available.',
            'User-facing answer error changed'
        );
        check(
            ($exception->getPrevious()?->getMessage() ?? '') === 'LLM rate limit or usage quota exceeded (HTTP 429)',
            'Safe provider diagnostic was not preserved'
        );
    };

    $tests['analysis mode result exposes sanitized answer error detail only behind analysis gate'] = function () use ($root): void {
        $source = file_get_contents($root . '/lib/Service/SearchService.php');
        check(is_string($source), 'SearchService source could not be read');
        check(
            str_contains($source, "if (\$analysisEnabled && \$answerError && \$answerErrorDetail !== '')"),
            'Answer diagnostic is not gated by analysis mode'
        );
        check(
            str_contains($source, "\$result['answer_error_detail'] = \$answerErrorDetail;"),
            'Analysis result does not expose answer_error_detail'
        );
    };

    $tests['Elasticsearch error is sanitized'] = function (): void {
        $service = new ElasticsearchSearch(
            new ThrowingClientService(),
            new FakeConfigService(),
            new FakeAclValidator(),
            new ElasticsearchCompiler()
        );
        $plan = (new RetrievalPlanner(new HardConstraintExtractor()))
            ->planFromSearchSpec((new SearchSpecNormalizer())->fallback('test'), 'test', true);

        $exception = expectException(
            fn() => $service->search($plan, 'test'),
            \RuntimeException::class
        );
        check(!str_contains($exception->getMessage(), 'secret'), 'Elasticsearch error leaked upstream detail');
        check($exception->getMessage() === 'Elasticsearch search is temporarily unavailable', 'Unexpected ES error message');
    };

    $tests['lightweight dedup collapses same-directory format variants'] = function (): void {
        $service = (new \ReflectionClass(SearchService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(SearchService::class, 'deduplicateHits');
        $method->setAccessible(true);

        $hits = [
            ['file_id' => 1, 'name' => 'a1.odt', 'path' => '/Docs/a1.odt', 'snippet' => 'Erster Inhalt'],
            ['file_id' => 2, 'name' => 'a1.pdf', 'path' => '/Docs/a1.pdf', 'snippet' => 'Erster Inhalt als PDF'],
            ['file_id' => 3, 'name' => 'a2.pdf', 'path' => '/Docs/a2.pdf', 'snippet' => 'Anderer Inhalt'],
        ];
        $deduped = $method->invoke($service, $hits);

        check(count($deduped) === 2, 'Same-directory extension variants were not deduplicated');
        check(($deduped[0]['file_id'] ?? 0) === 1, 'Dedup did not keep the higher-ranked first variant');
        check(($deduped[1]['file_id'] ?? 0) === 3, 'Non-duplicate hit was removed');
    };

    $tests['analysis UI renders sanitized answer error detail when present'] = function () use ($root): void {
        $script = file_get_contents($root . '/js/search.js');
        check(is_string($script), 'search.js could not be read');
        check(
            str_contains($script, 'if (result.answer_error_detail)'),
            'Analysis UI does not gate rendering of answer_error_detail'
        );
        check(
            str_contains($script, 'analysis.answer_error_detail = String(result.answer_error_detail);'),
            'Analysis UI drops backend answer_error_detail'
        );
    };

    $tests['sources card is hidden whenever no documents are available'] = function () use ($root): void {
        $script = file_get_contents($root . '/js/search.js');
        check(is_string($script), 'search.js could not be read');
        check(
            str_contains($script, "show('sunaqfind-sources-card', (sources || []).length > 0)"),
            'Empty source lists do not hide the complete sources card'
        );
        check(
            str_contains($script, "renderSources([])"),
            'Search or document-analysis flows do not explicitly clear stale sources'
        );
    };

    $tests['source path and snippet use explicit collapsed disclosure control'] = function () use ($root): void {
        $script = file_get_contents($root . '/js/search.js');
        $css = file_get_contents($root . '/css/probe.css');
        $service = file_get_contents($root . '/lib/Service/SearchService.php');
        check(is_string($script) && is_string($css) && is_string($service), 'Source detail files could not be read');
        check(str_contains($script, "createDisclosure(t('sunaqfind', 'Details'), 'sunaqfind-source-details-box')"), 'Source details do not use explicit disclosure control');
        check(str_contains($script, "panel.hidden = true"), 'Source details are not collapsed initially');
        check(str_contains($script, "button.setAttribute('aria-expanded', 'false')"), 'Source disclosure state is not initialized');
        check(str_contains($css, '.sunaqfind-disclosure-panel[hidden]'), 'Hidden disclosure panels lack an explicit CSS guard');
        check(str_contains($css, 'display: none !important;'), 'Hidden disclosure panels can be overridden by external CSS');
        check(str_contains($service, 'unset($source[\'snippet\'], $source[\'path\'])'), 'Disabled source details still expose snippet or path');
    };

    $tests['search strategy is rendered in the same explicit collapsed disclosure pattern'] = function () use ($root): void {
        $template = file_get_contents($root . '/templates/main.php');
        $script = file_get_contents($root . '/js/search.js');
        check(is_string($template) && is_string($script), 'Search strategy disclosure files could not be read');
        check(str_contains($template, 'id="sunaqfind-strategy-toggle"'), 'Search strategy disclosure toggle missing');
        check(str_contains($template, 'aria-expanded="false"'), 'Search strategy disclosure is not initially collapsed');
        check(str_contains($template, 'id="sunaqfind-strategy-panel"'), 'Search strategy panel missing');
        check(str_contains($script, "renderSearchStrategy(result.search_strategy || '')"), 'Search strategy response is not rendered separately');
        check(str_contains($script, "renderSearchStrategy('')"), 'Search strategy disclosure is not reset between searches');
    };

    $tests['answer renderer supports safe Markdown tables'] = function () use ($root): void {
        $script = file_get_contents($root . '/js/search.js');
        $css = file_get_contents($root . '/css/probe.css');
        check(is_string($script) && is_string($css), 'Markdown table renderer files could not be read');
        check(str_contains($script, 'function normalizeCollapsedMarkdownTables(markdown)'), 'Collapsed Markdown table repair is missing');
        check(str_contains($script, "return line.replace(/\\|\\s+\\|/g, '|\\n|');"), 'Collapsed table rows are not normalized to physical lines');
        check(str_contains($script, 'function isTableSeparator(line)'), 'Markdown table separator detection missing');
        check(str_contains($script, "table.className = 'sunaqfind-markdown-table'"), 'Markdown tables are not rendered as table elements');
        check(str_contains($script, 'appendInline(th, header)'), 'Markdown table headers bypass safe inline rendering');
        check(str_contains($script, "appendInline(td, cells[column] || '')"), 'Markdown table cells bypass safe inline rendering');
        check(str_contains($css, '.sunaqfind-markdown-table-wrap'), 'Markdown table overflow styling missing');
        check(str_contains($css, '.sunaqfind-markdown-table th'), 'Markdown table cell styling missing');
        check(!str_contains($script, 'innerHTML ='), 'Markdown table support introduced raw HTML rendering');
    };

    $tests['search UI renders only bounded internal alternative search links'] = function () use ($root): void {
        $script = file_get_contents($root . '/js/search.js');
        check(is_string($script), 'Could not read search.js');
        check(str_contains($script, 'Possible alternative search: '), 'Alternative search list marker missing from renderer');
        check(str_contains($script, "alternativeText.className = 'sunaqfind-alternative-query'"), 'Alternative query is not rendered as selectable text');
        check(str_contains($script, 'alternativeText.textContent = alternativeQuery'), 'Alternative query text is not visible outside the action button');
        check(str_contains($script, "alternativeButton.type = 'button'"), 'Alternative search is not rendered as an explicit action');
        check(str_contains($script, "alternativeButton.className = 'sunaqfind-alternative-search'"), 'Alternative search action lacks a dedicated class');
        check(str_contains($script, "alternativeButton.textContent = t('sunaqfind', 'Search')"), 'Alternative search action label is not decoupled from query text');
        check(str_contains($script, 'runSearch();'), 'Alternative search action does not launch a search');
        check(!str_contains($script, 'innerHTML ='), 'Search renderer must not enable raw HTML injection');
    };

    $tests['alternative search action is visually distinct'] = function () use ($root): void {
        $css = file_get_contents($root . '/css/probe.css');
        check(is_string($css), 'Could not read probe.css');
        check(str_contains($css, '.sunaqfind-alternative-search'), 'Alternative search action style missing');
        check(str_contains($css, 'cursor: pointer'), 'Alternative search action does not look interactive');
    };

    $tests['page template provides Nextcloud app-content scroll container'] = function () use ($root): void {
        $template = file_get_contents($root . '/templates/main.php');
        check(is_string($template), 'Could not read page template');
        check(substr_count($template, 'id="app-content"') === 1, 'Page must open exactly one #app-content scroll container');

        $containerPos = strpos($template, 'id="app-content"');
        $pagePos = strpos($template, 'id="sunaqfind-probe"');
        check($containerPos !== false && $pagePos !== false && $containerPos < $pagePos, 'Page content is not inside #app-content');
        check(str_contains($template, "</main>\n</div>"), '#app-content does not close around the page content');
    };

    $failed = 0;
    foreach ($tests as $name => $test) {
        try {
            $test();
            fwrite(STDOUT, "PASS  {$name}\n");
        } catch (\Throwable $e) {
            $failed++;
            fwrite(STDERR, "FAIL  {$name}: {$e->getMessage()}\n");
        }
    }

    fwrite(STDOUT, sprintf("\n%d tests, %d failed\n", count($tests), $failed));
    exit($failed === 0 ? 0 : 1);
}
