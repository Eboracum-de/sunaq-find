<?php
declare(strict_types=1);

namespace OCA\SunaqFind\Controller;

use OCA\SunaqFind\Service\ConfigService;
use OCA\SunaqFind\Service\ElasticsearchSearch;
use OCA\SunaqFind\Service\LlmClient;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IL10N;

final class AdminController extends Controller {
    private $config;
    private $elasticsearch;
    private $llm;
    private $l10n;
    public function __construct(
        string $appName,
        IRequest $request,
        ConfigService $config,
        ElasticsearchSearch $elasticsearch,
        LlmClient $llm,
        ?IL10N $l10n = null
    ) {
        parent::__construct($appName, $request);
        $this->config = $config;
        $this->elasticsearch = $elasticsearch;
        $this->llm = $llm;
        $this->l10n = $l10n;
    }

    private function t(string $text, array $parameters = []): string {
        if ($this->l10n !== null) {
            return $this->l10n->t($text, $parameters);
        }
        return $parameters === [] ? $text : vsprintf($text, $parameters);
    }

    public function save(): DataResponse {
        $params = $this->request->getParams();

        $string = static function (array $values, string $key, string $default = ''): string {
            return trim((string)($values[$key] ?? $default));
        };
        $boolean = static function (array $values, string $key, bool $default = false): bool {
            if (!array_key_exists($key, $values)) {
                return $default;
            }
            return filter_var($values[$key], FILTER_VALIDATE_BOOLEAN);
        };
        $integer = static function (array $values, string $key, int $default): int {
            $raw = trim((string)($values[$key] ?? $default));
            return preg_match('/^[0-9]+$/', $raw) === 1 ? (int)$raw : -1;
        };

        $elasticUrl = $string($params, 'elasticUrl');
        $llmUrl = $string($params, 'llmUrl');
        $searchDocumentLimit = $integer($params, 'searchDocumentLimit', 15);
        $llmDocumentLimit = $integer($params, 'llmDocumentLimit', 12);

        if ($searchDocumentLimit < 1 || $searchDocumentLimit > 20) {
            return new DataResponse(['ok' => false, 'error' => $this->t('Maximum Elasticsearch hits must be between 1 and 20.')], 400);
        }
        if ($llmDocumentLimit < 1 || $llmDocumentLimit > 20) {
            return new DataResponse(['ok' => false, 'error' => $this->t('Maximum documents for AI evaluation must be between 1 and 20.')], 400);
        }
        if ($llmDocumentLimit > $searchDocumentLimit) {
            return new DataResponse(['ok' => false, 'error' => $this->t('Maximum documents for AI evaluation cannot exceed maximum Elasticsearch hits.')], 400);
        }
        foreach (['elasticUrl' => $elasticUrl, 'llmUrl' => $llmUrl] as $name => $url) {
            if ($url === '') {
                continue;
            }
            if (filter_var($url, FILTER_VALIDATE_URL) === false) {
                return new DataResponse(['ok' => false, 'error' => $this->t('%s is not a valid URL', [$name])], 400);
            }
            $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
            if (!in_array($scheme, ['http', 'https'], true)) {
                return new DataResponse(['ok' => false, 'error' => $this->t('%s must use http or https', [$name])], 400);
            }
        }

        $this->config->save([
            'elastic_url' => $elasticUrl,
            'elastic_index' => $string($params, 'elasticIndex'),
            'elastic_username' => $string($params, 'elasticUsername'),
            'elastic_password' => $string($params, 'elasticPassword'),
            'clear_elastic_password' => $boolean($params, 'clearElasticPassword'),
            'llm_url' => $llmUrl,
            'llm_model' => $string($params, 'llmModel'),
            'llm_api_key' => $string($params, 'llmApiKey'),
            'clear_llm_api_key' => $boolean($params, 'clearLlmApiKey'),
            'retrieval_languages' => $string($params, 'retrievalLanguages', 'de'),
            'acl_prefilter_enabled' => $boolean($params, 'aclPrefilterEnabled', true),
            'search_document_limit' => $searchDocumentLimit,
            'llm_document_limit' => $llmDocumentLimit,
            'allow_private_network' => $boolean($params, 'allowPrivateNetwork', true),
            'allow_self_signed' => $boolean($params, 'allowSelfSigned'),
            'analysis_mode' => $boolean($params, 'analysisMode'),
            'direct_lexical_routing' => $boolean($params, 'directLexicalRouting'),
            'show_document_excerpts' => $boolean($params, 'showDocumentExcerpts', true),
            'show_search_strategy' => $boolean($params, 'showSearchStrategy', true),
        ]);

        return new DataResponse(['ok' => true, 'config' => $this->config->publicStatus()]);
    }

    public function testElastic(): DataResponse {
        try {
            return new DataResponse([
                'ok' => true,
                'result' => $this->elasticsearch->testConnection(),
            ]);
        } catch (\Throwable $e) {
            return new DataResponse([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 502);
        }
    }

    public function testLlm(): DataResponse {
        try {
            return new DataResponse([
                'ok' => true,
                'result' => $this->llm->testConnection(),
            ]);
        } catch (\Throwable $e) {
            return new DataResponse([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 502);
        }
    }

}
