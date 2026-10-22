<?php
declare(strict_types=1);

namespace OCA\SunaqFind\Service;

use OCP\Http\Client\IClientService;

/**
 * OpenAI-compatible chat-completions adapter.
 *
 * Request translation intentionally mirrors the relevant SunaQ v0.8.7-rc1
 * OpenAICompatibleBackend behavior instead of defining app-specific model rules.
 */
class LlmClient {
    private $clientService;
    private $config;
    public function __construct(
        IClientService $clientService,
        ConfigService $config
    ) {
        $this->clientService = $clientService;
        $this->config = $config;
    }

    public function testConnection(): array {
        $cfg = $this->config->getLlmConfig();
        if ($cfg['url'] === '' || $cfg['model'] === '') {
            throw new \RuntimeException('LLM endpoint/model is not configured');
        }

        $this->complete(
            [['role' => 'user', 'content' => 'Reply with exactly OK.']],
            64,
            false,
            60,
            false,
            'none'
        );

        return [
            'ok' => true,
            'model' => $cfg['model'],
        ];
    }

    public function complete(
        array $messages,
        int $maxTokens = 1200,
        bool $jsonMode = false,
        int $timeout = 35,
        ?bool $think = null,
        ?string $reasoningMode = null
    ): string {
        $cfg = $this->config->getLlmConfig();
        if ($cfg['url'] === '' || $cfg['model'] === '') {
            throw new \RuntimeException('LLM endpoint/model is not configured');
        }

        $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/json'];
        if ($cfg['api_key'] !== '') {
            $headers['Authorization'] = 'Bearer ' . $cfg['api_key'];
        }

        $body = [
            'model' => $cfg['model'],
            'messages' => $messages,
            'stream' => false,
        ];

        $boundedTokens = max(64, min(2000, $maxTokens));
        $nativeGpt56 = $this->isNativeOpenAi($cfg['url']) && $this->isGpt56($cfg['model']);

        $reasoningMode = $this->normalizeReasoningMode($reasoningMode);

        if ($nativeGpt56) {
            $body['max_completion_tokens'] = $boundedTokens;
            if ($reasoningMode === 'none' || ($reasoningMode === 'current' && $think === false)) {
                $body['reasoning_effort'] = 'none';
            } elseif ($reasoningMode === 'thinking') {
                $body['reasoning_effort'] = 'medium';
            }
        } else {
            $body['temperature'] = 0;
            $body['max_tokens'] = $boundedTokens;

            // "think" is supported by Ollama for reasoning-capable models, but it
            // is not part of the OpenAI chat-completions contract. Sending it to
            // arbitrary compatible providers (for example Gemini) can make an
            // otherwise valid request fail with HTTP 400.
            if ($this->isOllama($cfg['url'])) {
                if ($reasoningMode === 'none') {
                    $body['think'] = false;
                } elseif ($reasoningMode === 'thinking') {
                    $body['think'] = true;
                }
            }
        }

        $attempts = [];
        if ($jsonMode) {
            $withFormat = $body;
            $withFormat['response_format'] = ['type' => 'json_object'];
            $attempts[] = $withFormat;
            // Same compatibility degradation as SunaQ 0.8.7: prompt-only JSON
            // if the compatible provider rejects response_format itself.
            $attempts[] = $body;
        } else {
            $attempts[] = $body;
        }

        $client = $this->clientService->newClient();
        $decoded = null;
        $lastError = null;

        foreach ($attempts as $index => $attemptBody) {
            try {
                $response = $client->post($this->chatEndpoint($cfg['url']), [
                    'headers' => $headers,
                    'body' => json_encode(
                        $attemptBody,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                    ),
                    'timeout' => max(5, min(120, $timeout)),
                    'connect_timeout' => 5,
                    'verify' => !$cfg['allow_self_signed'],
                    'nextcloud' => ['allow_local_address' => (bool)$cfg['allow_private_network']],
                ]);

                $status = method_exists($response, 'getStatusCode')
                    ? (int)$response->getStatusCode()
                    : 200;

                if ($status >= 400) {
                    if (
                        $index + 1 < count($attempts)
                        && in_array($status, [400, 404, 415, 422], true)
                    ) {
                        continue;
                    }
                    throw new \RuntimeException('LLM HTTP ' . $status);
                }

                $decoded = json_decode((string)$response->getBody(), true, 32, JSON_THROW_ON_ERROR);
                break;
            } catch (\Throwable $e) {
                $lastError = $e;
                $status = $this->statusFromThrowable($e);
                if (
                    $index + 1 < count($attempts)
                    && $status !== null
                    && in_array($status, [400, 404, 415, 422], true)
                ) {
                    continue;
                }
                break;
            }
        }

        if (!is_array($decoded)) {
            $status = $lastError ? $this->statusFromThrowable($lastError) : null;
            if ($lastError !== null) {
                $message = mb_strtolower($lastError->getMessage(), 'UTF-8');
                if (strpos($message, 'timed out') !== false || strpos($message, 'timeout') !== false) {
                    throw new \RuntimeException('The configured LLM endpoint timed out');
                }
            }
            if ($status !== null) {
                throw new \RuntimeException($this->statusMessage($status));
            }
            throw new \RuntimeException('The configured LLM endpoint is unavailable');
        }

        $content = $this->contentToText($decoded['choices'][0]['message']['content'] ?? null);
        if ($content === '') {
            throw new \RuntimeException('The configured LLM endpoint returned no usable content');
        }

        return mb_substr($content, 0, 20000, 'UTF-8');
    }

    private function statusMessage(int $status): string {
        switch ($status) {
            case 400:
            case 415:
            case 422:
                return 'LLM request was rejected by the configured provider (HTTP ' . $status . ')';
            case 401:
                return 'LLM authentication failed (HTTP 401)';
            case 402:
                return 'LLM usage requires billing or credits (HTTP 402)';
            case 403:
                return 'LLM access was denied (HTTP 403)';
            case 404:
                return 'LLM endpoint or model was not found (HTTP 404)';
            case 408:
                return 'The configured LLM endpoint timed out (HTTP 408)';
            case 429:
                return 'LLM rate limit or usage quota exceeded (HTTP 429)';
            default:
                return $status >= 500
                    ? 'LLM provider is temporarily unavailable (HTTP ' . $status . ')'
                    : 'The configured LLM endpoint is unavailable (HTTP ' . $status . ')';
        }
    }

    private function normalizeReasoningMode(?string $mode): string {
        $mode = strtolower(trim((string)($mode ?? 'current')));
        return in_array($mode, ['current', 'none', 'default', 'thinking'], true)
            ? $mode
            : 'current';
    }

    private function isNativeOpenAi(string $baseUrl): bool {
        $host = mb_strtolower((string)(parse_url($baseUrl, PHP_URL_HOST) ?? ''), 'UTF-8');
        return $host === 'api.openai.com';
    }

    private function isGpt56(string $model): bool {
        return strpos(mb_strtolower(trim($model), 'UTF-8'), 'gpt-5.6') === 0;
    }

    private function isOllama(string $baseUrl): bool {
        $host = mb_strtolower((string)(parse_url($baseUrl, PHP_URL_HOST) ?? ''), 'UTF-8');
        $port = parse_url($baseUrl, PHP_URL_PORT);

        return $port === 11434 || strpos($host, 'ollama') !== false;
    }

    private function contentToText($content): string {
        if (is_string($content)) {
            return trim($content);
        }

        if (!is_array($content)) {
            return trim((string)($content ?? ''));
        }

        $parts = [];
        foreach ($content as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = (string)($item['type'] ?? '');
            if (!in_array($type, ['text', 'output_text'], true)) {
                continue;
            }
            $text = $item['text'] ?? null;
            if (is_string($text) && $text !== '') {
                $parts[] = $text;
            }
        }
        return trim(implode('', $parts));
    }

    private function statusFromThrowable(\Throwable $error): ?int {
        if (method_exists($error, 'getResponse')) {
            try {
                $response = $error->getResponse();
                if (is_object($response) && method_exists($response, 'getStatusCode')) {
                    return (int)$response->getStatusCode();
                }
            } catch (\Throwable $ignored) {
            }
        }

        $code = (int)$error->getCode();
        if ($code >= 400 && $code <= 599) {
            return $code;
        }

        if (preg_match('/\\b([45][0-9]{2})\\b/', $error->getMessage(), $match) === 1) {
            return (int)$match[1];
        }

        return null;
    }

    private function chatEndpoint(string $base): string {
        $base = rtrim($base, '/');
        if (preg_match('~/chat/completions$~', $base)) {
            return $base;
        }
        $path = (string)(parse_url($base, PHP_URL_PATH) ?? '');
        return ($path === '' || $path === '/')
            ? $base . '/v1/chat/completions'
            : $base . '/chat/completions';
    }
}
