<?php
declare(strict_types=1);

namespace OCA\SunaqFind\Service;

use OCA\SunaqFind\AppInfo\Application;
use OCP\IConfig;
use OCP\Security\ICrypto;

class ConfigService {
    private $config;
    private $crypto;
    public function __construct(
        IConfig $config,
        ICrypto $crypto
    ) {
        $this->config = $config;
        $this->crypto = $crypto;
    }

    public function save(array $values): void {
        $previousElasticUrl = trim($this->config->getAppValue(Application::APP_ID, 'elastic_url', ''));
        $previousLlmUrl = trim($this->config->getAppValue(Application::APP_ID, 'llm_url', ''));

        foreach (['elastic_url','elastic_index','elastic_username','llm_url','llm_model'] as $key) {
            if (array_key_exists($key, $values)) {
                $this->config->setAppValue(Application::APP_ID, $key, trim((string)$values[$key]));
            }
        }
        if (array_key_exists('retrieval_languages', $values)) {
            $languages = $this->normalizeRetrievalLanguages((string)$values['retrieval_languages']);
            $this->config->setAppValue(Application::APP_ID, 'retrieval_languages', implode(',', $languages));
        }
        foreach (['search_document_limit', 'llm_document_limit'] as $key) {
            if (array_key_exists($key, $values)) {
                $value = max(1, min(20, (int)$values[$key]));
                $this->config->setAppValue(Application::APP_ID, $key, (string)$value);
            }
        }
        foreach ([
            'allow_private_network',
            'allow_self_signed',
            'analysis_mode',
            'acl_prefilter_enabled',
            'direct_lexical_routing',
            'show_document_excerpts',
            'show_search_strategy',
        ] as $key) {
            if (array_key_exists($key, $values)) {
                $this->config->setAppValue(
                    Application::APP_ID,
                    $key,
                    filter_var($values[$key], FILTER_VALIDATE_BOOLEAN) ? '1' : '0'
                );
            }
        }

        $elasticPassword = trim((string)($values['elastic_password'] ?? ''));
        $llmApiKey = trim((string)($values['llm_api_key'] ?? ''));
        $elasticUrlChanged = array_key_exists('elastic_url', $values)
            && trim((string)$values['elastic_url']) !== $previousElasticUrl;
        $llmUrlChanged = array_key_exists('llm_url', $values)
            && trim((string)$values['llm_url']) !== $previousLlmUrl;

        $this->saveSecret(
            'elastic_password',
            $elasticPassword,
            !empty($values['clear_elastic_password']) || ($elasticUrlChanged && $elasticPassword === '')
        );
        $this->saveSecret(
            'llm_api_key',
            $llmApiKey,
            !empty($values['clear_llm_api_key']) || ($llmUrlChanged && $llmApiKey === '')
        );
    }

    public function getElasticConfig(): array {
        $rawUrl = trim($this->config->getAppValue(Application::APP_ID, 'elastic_url', ''));
        $index = trim($this->config->getAppValue(Application::APP_ID, 'elastic_index', ''));
        $username = trim($this->config->getAppValue(Application::APP_ID, 'elastic_username', ''));
        $password = $this->getSecret('elastic_password');
        $source = 'manual';

        if ($rawUrl === '') {
            $source = 'fulltextsearch_elasticsearch';
            $rawHosts = $this->externalAppString('fulltextsearch_elasticsearch', 'elastic_host');
            $parts = array_values(array_filter(array_map('trim', explode(',', $rawHosts))));
            $rawUrl = $parts[0] ?? '';

            if ($index === '') {
                $index = $this->externalAppString('fulltextsearch_elasticsearch', 'elastic_index');
            }

            if ($rawUrl !== '') {
                $parsed = parse_url($rawUrl);
                if (is_array($parsed)) {
                    if ($username === '' && isset($parsed['user'])) {
                        $username = rawurldecode((string)$parsed['user']);
                    }
                    if ($password === '' && isset($parsed['pass'])) {
                        $password = rawurldecode((string)$parsed['pass']);
                    }
                    $rawUrl = $this->withoutUserInfo($parsed);
                }
            }
        }

        if ($rawUrl === '' || $index === '') {
            $source = 'missing';
        }

        return [
            'url' => rtrim($rawUrl, '/'),
            'index' => $index,
            'username' => $username,
            'password' => $password,
            'source' => $source,
            'allow_private_network' => $this->boolValue('allow_private_network', true),
            'allow_self_signed' => $this->boolValue('allow_self_signed'),
        ];
    }

    public function getLlmConfig(): array {
        return [
            'url' => rtrim(trim($this->config->getAppValue(Application::APP_ID, 'llm_url', '')), '/'),
            'model' => trim($this->config->getAppValue(Application::APP_ID, 'llm_model', '')),
            'api_key' => $this->getSecret('llm_api_key'),
            'allow_private_network' => $this->boolValue('allow_private_network', true),
            'allow_self_signed' => $this->boolValue('allow_self_signed'),
        ];
    }

    public function getRetrievalLanguages(): array {
        return $this->normalizeRetrievalLanguages(
            $this->config->getAppValue(Application::APP_ID, 'retrieval_languages', 'de')
        );
    }

    public function isAnalysisMode(): bool {
        return $this->boolValue('analysis_mode');
    }

    public function isAclPrefilterEnabled(): bool {
        return $this->boolValue('acl_prefilter_enabled', true);
    }

    public function getSearchDocumentLimit(): int {
        return $this->intValue('search_document_limit', 15, 1, 20);
    }

    public function getLlmDocumentLimit(): int {
        return min(
            $this->getSearchDocumentLimit(),
            $this->intValue('llm_document_limit', 12, 1, 20)
        );
    }

    public function isDirectLexicalRoutingEnabled(): bool {
        return $this->boolValue('direct_lexical_routing');
    }

    public function showDocumentExcerpts(): bool {
        return $this->boolValue('show_document_excerpts', true);
    }

    public function showSearchStrategy(): bool {
        return $this->boolValue('show_search_strategy', true);
    }


    public function publicStatus(): array {
        $elastic = $this->getElasticConfig();
        $llm = $this->getLlmConfig();
        return [
            'elastic' => [
                'configured' => $elastic['url'] !== '' && $elastic['index'] !== '',
                'source' => $elastic['source'],
                'url' => $this->sanitizeUrl($elastic['url']),
                'index' => $elastic['index'],
                'has_credentials' => $elastic['username'] !== '' || $elastic['password'] !== '',
            ],
            'llm' => [
                'configured' => $llm['url'] !== '' && $llm['model'] !== '',
                'url' => $this->sanitizeUrl($llm['url']),
                'model' => $llm['model'],
                'has_api_key' => $llm['api_key'] !== '',
            ],
            'allow_private_network' => $elastic['allow_private_network'],
            'allow_self_signed' => $elastic['allow_self_signed'],
            'analysis_mode' => $this->isAnalysisMode(),
            'acl_prefilter_enabled' => $this->isAclPrefilterEnabled(),
            'search_document_limit' => $this->getSearchDocumentLimit(),
            'llm_document_limit' => $this->getLlmDocumentLimit(),
            'direct_lexical_routing' => $this->isDirectLexicalRoutingEnabled(),
            'show_document_excerpts' => $this->showDocumentExcerpts(),
            'show_search_strategy' => $this->showSearchStrategy(),
            'retrieval_languages' => $this->getRetrievalLanguages(),
        ];
    }

    public function adminValues(): array {
        return [
            'elastic_url' => $this->config->getAppValue(Application::APP_ID, 'elastic_url', ''),
            'elastic_index' => $this->config->getAppValue(Application::APP_ID, 'elastic_index', ''),
            'elastic_username' => $this->config->getAppValue(Application::APP_ID, 'elastic_username', ''),
            'has_elastic_password' => $this->config->getAppValue(Application::APP_ID, 'elastic_password_encrypted', '') !== '',
            'llm_url' => $this->config->getAppValue(Application::APP_ID, 'llm_url', ''),
            'llm_model' => $this->config->getAppValue(Application::APP_ID, 'llm_model', ''),
            'has_llm_api_key' => $this->config->getAppValue(Application::APP_ID, 'llm_api_key_encrypted', '') !== '',
            'allow_private_network' => $this->boolValue('allow_private_network', true),
            'allow_self_signed' => $this->boolValue('allow_self_signed'),
            'analysis_mode' => $this->isAnalysisMode(),
            'acl_prefilter_enabled' => $this->isAclPrefilterEnabled(),
            'search_document_limit' => $this->getSearchDocumentLimit(),
            'llm_document_limit' => $this->getLlmDocumentLimit(),
            'direct_lexical_routing' => $this->isDirectLexicalRoutingEnabled(),
            'show_document_excerpts' => $this->showDocumentExcerpts(),
            'show_search_strategy' => $this->showSearchStrategy(),
            'retrieval_languages' => implode(',', $this->getRetrievalLanguages()),
            'detected_elastic' => $this->publicStatus()['elastic'],
        ];
    }

    private function normalizeRetrievalLanguages(string $raw): array {
        $parts = preg_split('/[\\s,;]+/u', trim($raw)) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = strtolower(trim((string)$part));
            if ($part === '' || preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/', $part) !== 1) {
                continue;
            }
            if (!in_array($part, $out, true)) {
                $out[] = $part;
            }
            if (count($out) >= 8) {
                break;
            }
        }
        return $out !== [] ? $out : ['de'];
    }

    private function externalAppString(string $app, string $key): string {
        // Legacy Nextcloud exposes cross-app configuration through IConfig.
        // Keep that path first for NC23 and other older releases.
        $value = trim((string)$this->config->getAppValue($app, $key, ''));
        if ($value !== '') {
            return $value;
        }

        // Since NC29, apps can register typed/lazy app-config values. Current
        // fulltextsearch_elasticsearch marks elastic_host and elastic_index as
        // lazy, so IConfig::getAppValue() can legitimately return an empty
        // value. Resolve the newer public IAppConfig only when the host
        // Nextcloud provides it, and explicitly include lazy values.
        if (class_exists('\\OCP\\Server') && interface_exists('\\OCP\\IAppConfig')) {
            try {
                $appConfig = \OCP\Server::get(\OCP\IAppConfig::class);
                if (method_exists($appConfig, 'getValueString')) {
                    return trim((string)$appConfig->getValueString($app, $key, '', true));
                }
            } catch (\Throwable $e) {
                // Fall through to the legacy empty value.
            }
        }

        return '';
    }

    private function saveSecret(string $key, string $value, bool $clear = false): void {
        $value = trim($value);
        if ($value !== '') {
            $this->config->setAppValue(Application::APP_ID, $key . '_encrypted', $this->crypto->encrypt($value));
            return;
        }
        if ($clear) {
            $this->config->deleteAppValue(Application::APP_ID, $key . '_encrypted');
        }
    }

    private function getSecret(string $key): string {
        $encrypted = $this->config->getAppValue(Application::APP_ID, $key . '_encrypted', '');
        if ($encrypted === '') {
            return '';
        }
        try {
            return (string)$this->crypto->decrypt($encrypted);
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function boolValue(string $key, bool $default = false): bool {
        return in_array(
            strtolower($this->config->getAppValue(Application::APP_ID, $key, $default ? '1' : '0')),
            ['1','true','yes','on'],
            true
        );
    }

    private function intValue(string $key, int $default, int $min, int $max): int {
        $raw = trim($this->config->getAppValue(Application::APP_ID, $key, (string)$default));
        if (preg_match('/^[0-9]+$/', $raw) !== 1) {
            return $default;
        }
        return max($min, min($max, (int)$raw));
    }

    private function sanitizeUrl(string $url): string {
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        return is_array($parts) ? $this->withoutUserInfo($parts) : '';
    }

    private function withoutUserInfo(array $parts): string {
        $scheme = isset($parts['scheme']) ? (string)$parts['scheme'] : '';
        $host = isset($parts['host']) ? (string)$parts['host'] : '';
        if ($scheme === '' || $host === '') {
            return '';
        }
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        $path = isset($parts['path']) ? (string)$parts['path'] : '';
        return $scheme . '://' . $host . $port . $path;
    }
}
