<?php
/**
 * Enhanced AI service wrapper with retry, fallback providers and
 * provider-specific request/response handling.
 */

class AI_Service_Enhanced
{
    /** @var array<string,array<string,mixed>> */
    private $providers = [];
    private int $maxRetries = 3;
    private int $baseDelay = 1000; // milliseconds
    private int $maxDelay = 32000; // milliseconds

    /**
     * @param array<string,array<string,mixed>> $providerConfig Optional runtime configuration
     */
    public function __construct(array $providerConfig = [])
    {
        $defaultProviders = [
            'primary' => [
                'type' => 'claude',
                'api_key' => getenv('CLAUDE_API_KEY') ?: '',
                'endpoint' => 'https://api.anthropic.com/v1/messages',
                'model' => 'claude-3-sonnet-20240229',
            ],
            'fallback' => [
                'type' => 'qwen',
                'api_key' => getenv('QWEN_API_KEY') ?: '',
                'endpoint' => 'https://dashscope.aliyuncs.com/api/v1/services/aigc/text-generation/generation',
                'model' => 'qwen-plus',
            ],
            'backup' => [
                'type' => 'mock',
                'model' => 'mock-strategy-analyst',
            ],
        ];

        if (!empty($providerConfig)) {
            $this->providers = $providerConfig;
        } else {
            $this->providers = $defaultProviders;
        }
    }

    /**
     * Call AI provider with retry/backoff support.
     *
     * @param string $prompt
     * @param array<string,mixed> $options
     *
     * @throws Exception
     */
    public function callWithRetry(string $prompt, array $options = []): array
    {
        $lastError = null;

        foreach ($this->providers as $providerId => $provider) {
            $attempt = 0;

            while ($attempt < $this->maxRetries) {
                try {
                    if ($attempt > 0) {
                        $delay = $this->calculateDelay($attempt);
                        usleep($delay * 1000);
                        $this->logRetry($providerId, $attempt, $delay);
                    }

                    $result = $this->executeApiCall($provider, $prompt, $options);
                    if ($result !== false) {
                        $this->logSuccess($providerId, $attempt);
                        return $result;
                    }
                } catch (Throwable $e) {
                    $lastError = $e;
                    if (!$this->handleSpecificError($e, $attempt)) {
                        break; // switch to next provider
                    }
                }

                $attempt++;
            }
        }

        $message = $lastError ? $lastError->getMessage() : 'Unknown error';
        throw new Exception('All AI providers failed. Last error: ' . $message);
    }

    private function calculateDelay(int $attempt): int
    {
        $delay = (int) min(
            $this->baseDelay * pow(2, $attempt - 1) + rand(0, 1000),
            $this->maxDelay
        );

        return max($delay, $this->baseDelay);
    }

    private function handleSpecificError(Throwable $exception, int $attempt): bool
    {
        $code = (int) ($exception->getCode() ?: 0);
        $message = $exception->getMessage();

        if ($code === 429) {
            $retryAfter = $this->extractRetryAfter($exception);
            if ($retryAfter > 0) {
                sleep($retryAfter);
                return true;
            }
        }

        if ($code === 503) {
            return $attempt < ($this->maxRetries - 1);
        }

        if (stripos($message, 'timeout') !== false) {
            return $attempt < 2;
        }

        if ($code >= 400 && $code < 500) {
            return false;
        }

        return $attempt < ($this->maxRetries - 1);
    }

    /**
     * @param array<string,mixed> $provider
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function executeApiCall(array $provider, string $prompt, array $options): array
    {
        if (($provider['type'] ?? '') === 'mock') {
            return $this->generateMockResponse($prompt, $options);
        }

        if (empty($provider['api_key'])) {
            // Transparently fallback to mock provider
            return $this->generateMockResponse($prompt, $options);
        }

        $headers = $this->buildHeaders($provider);
        $body = $this->buildRequestBody($provider, $prompt, $options);

        $responseHeaders = [];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $provider['endpoint'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_TIMEOUT => $options['timeout'] ?? 45,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$responseHeaders) {
                $len = strlen($header);
                $headerParts = explode(':', $header, 2);
                if (count($headerParts) === 2) {
                    $responseHeaders[strtolower(trim($headerParts[0]))] = trim($headerParts[1]);
                }
                return $len;
            },
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception('CURL error: ' . $error, $httpCode ?: 0);
        }

        curl_close($ch);

        if ($httpCode !== 200) {
            $exception = new Exception('HTTP error: ' . $httpCode . ' - ' . $response, $httpCode);
            if (!empty($responseHeaders['retry-after'])) {
                $exception->retryAfter = (int) $responseHeaders['retry-after'];
            }
            throw $exception;
        }

        return $this->parseResponse($provider, $response);
    }

    /**
     * @param array<string,mixed> $provider
     * @return array<int,string>
     */
    private function buildHeaders(array $provider): array
    {
        $headers = ['Content-Type: application/json'];
        $type = $provider['type'] ?? 'generic';

        switch ($type) {
            case 'claude':
                $headers[] = 'x-api-key: ' . $provider['api_key'];
                $headers[] = 'anthropic-version: 2023-06-01';
                break;
            case 'qwen':
                $headers[] = 'Authorization: Bearer ' . $provider['api_key'];
                break;
            default:
                $headers[] = 'Authorization: Bearer ' . ($provider['api_key'] ?? '');
        }

        if (!empty($provider['extra_headers']) && is_array($provider['extra_headers'])) {
            $headers = array_merge($headers, $provider['extra_headers']);
        }

        return $headers;
    }

    /**
     * @param array<string,mixed> $provider
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function buildRequestBody(array $provider, string $prompt, array $options): array
    {
        $model = $options['model'] ?? ($provider['model'] ?? '');
        $system = $options['system'] ?? ($options['system_prompt'] ?? '');
        $messages = $options['messages'] ?? [
            ['role' => 'user', 'content' => $prompt],
        ];

        if ($system) {
            array_unshift($messages, ['role' => 'system', 'content' => $system]);
        }

        $type = $provider['type'] ?? 'generic';

        switch ($type) {
            case 'claude':
                return [
                    'model' => $model ?: 'claude-3-sonnet-20240229',
                    'max_tokens' => $options['max_tokens'] ?? 1500,
                    'temperature' => $options['temperature'] ?? 0.7,
                    'messages' => array_map(function ($message) {
                        return [
                            'role' => $message['role'],
                            'content' => [[
                                'type' => 'text',
                                'text' => $message['content'],
                            ]],
                        ];
                    }, $messages),
                ];
            case 'qwen':
                return [
                    'model' => $model ?: 'qwen-plus',
                    'input' => [
                        'messages' => array_map(function ($message) {
                            return [
                                'role' => $message['role'],
                                'content' => $message['content'],
                            ];
                        }, $messages),
                    ],
                    'parameters' => [
                        'temperature' => $options['temperature'] ?? 0.7,
                        'top_p' => $options['top_p'] ?? 0.9,
                    ],
                ];
            default:
                return [
                    'model' => $model ?: 'gpt-4o-mini',
                    'messages' => $messages,
                    'max_tokens' => $options['max_tokens'] ?? 1500,
                    'temperature' => $options['temperature'] ?? 0.7,
                ];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function parseResponse(array $provider, string $response): array
    {
        $type = $provider['type'] ?? 'generic';
        $data = json_decode($response, true);

        if (!is_array($data)) {
            throw new Exception('Invalid JSON response from AI provider');
        }

        switch ($type) {
            case 'claude':
                $content = $data['content'][0]['text'] ?? '';
                return [
                    'content' => $content,
                    'provider' => 'claude',
                    'raw' => $data,
                ];
            case 'qwen':
                $content = $data['output']['text'] ?? ($data['output']['choices'][0]['message']['content'] ?? '');
                return [
                    'content' => $content,
                    'provider' => 'qwen',
                    'raw' => $data,
                ];
            default:
                $content = $data['choices'][0]['message']['content'] ?? ($data['content'] ?? '');
                return [
                    'content' => $content,
                    'provider' => $type,
                    'raw' => $data,
                ];
        }
    }

    private function extractRetryAfter(Throwable $exception): int
    {
        if (isset($exception->retryAfter)) {
            return (int) $exception->retryAfter;
        }

        return 0;
    }

    private function logRetry(string $providerId, int $attempt, int $delay): void
    {
        error_log(sprintf('[AI_Service_Enhanced] Provider %s retry #%d, delay %dms', $providerId, $attempt, $delay));
    }

    private function logSuccess(string $providerId, int $attempt): void
    {
        error_log(sprintf('[AI_Service_Enhanced] Provider %s succeeded after %d attempt(s)', $providerId, $attempt + 1));
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function generateMockResponse(string $prompt, array $options): array
    {
        $hash = substr(md5($prompt), 0, 8);
        $content = "【模拟AI响应】\n";
        $content .= "根据提供的商业构想（摘要ID: {$hash}），系统生成了战略分析内容。";
        $content .= "\n\n";
        $content .= $this->buildMockStrategy($prompt);

        return [
            'content' => $content,
            'provider' => 'mock',
            'raw' => ['mock' => true],
        ];
    }

    private function buildMockStrategy(string $prompt): string
    {
        $sections = [
            '市场洞察' => '从目标区域和用户需求角度出发，建立差异化定位，并建议组合线上线下渠道以快速获得市场验证。',
            '竞争态势' => '采用SWOT视角列举关键对手，针对其优势提出可落地的防御与突围方案。',
            '商业模式' => '构建订阅制与增值服务双轮驱动的收益结构，并设计分阶段的成本控制要点。',
            '增长策略' => '以MVP试点、合作生态与社区裂变三步走，形成低成本高效率的用户增长路径。',
        ];

        $lines = [];
        foreach ($sections as $title => $text) {
            $lines[] = "### {$title}\n{$text}";
        }

        return implode("\n\n", $lines);
    }
}
