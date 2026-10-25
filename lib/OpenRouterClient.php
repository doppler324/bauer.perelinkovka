<?php

namespace Bauer\Perelinkovka;

/**
 * Class OpenRouterClient
 * 
 * Клиент для OpenRouter API (OpenAI-совместимый).
 * Поддерживает embedding и chat completion.
 * 
 * API-ключ читает из константы OPENROUTER_API_KEY или параметра 'api_key'.
 */
class OpenRouterClient implements AiClientInterface
{
    private string $apiKey;
    private string $baseUrl = 'https://openrouter.ai/api/v1';
    private string $defaultEmbeddingModel = 'openai/text-embedding-3-small';
    private string $defaultChatModel = 'deepseek/deepseek-chat';
    private int $timeout = 5;
    private string $proxy = '';

    public function __construct(array $config = [])
    {
        $this->apiKey = $config['api_key']
            ?? (defined('OPENROUTER_API_KEY') ? OPENROUTER_API_KEY : '');
        $this->baseUrl = rtrim($config['base_url'] ?? $this->baseUrl, '/');
        $this->defaultEmbeddingModel = $config['embedding_model'] ?? $this->defaultEmbeddingModel;
        $this->defaultChatModel = $config['chat_model'] ?? $this->defaultChatModel;
        $this->timeout = $config['timeout'] ?? $this->timeout;
        $this->proxy = trim((string)($config['proxy'] ?? ''));
    }

    public function isAvailable(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Проверяет, что API-ключ рабочий (делает тестовый запрос моделей).
     * @return bool true если ключ валиден
     */
    public function validate(): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        $ch = curl_init($this->baseUrl . '/models');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiKey,
                'HTTP-Referer: https://github.com/bauer/perelinkovka',
                'X-Title: Bauer Perelinkovka',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ] + $this->proxyOptions());

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200;
    }

    /** Настройки curl для запросов через прокси (http://host:port, socks5://user:pass@host:port); пусто — напрямую. */
    private function proxyOptions(): array
    {
        return $this->proxy === '' ? [] : [CURLOPT_PROXY => $this->proxy];
    }

    /**
     * Отправляет HTTP-запрос к API
     * @param string $endpoint
     * @param array $data
     * @return array
     * @throws \RuntimeException
     */
    private function request(string $endpoint, array $data): array
    {
        $url = $this->baseUrl . $endpoint;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_USERAGENT     => 'BauerPerelinkovka/1.0',
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'HTTP-Referer: https://github.com/bauer/perelinkovka',
                'X-Title: Bauer Perelinkovka',
            ],
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ] + $this->proxyOptions());

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \RuntimeException('OpenRouter API request failed: ' . $error);
        }

        $result = json_decode((string)$response, true);

        if ($httpCode !== 200) {
            $errorMsg = $result['error']['message'] ?? '';
            // детали провайдера (например, причина блокировки) лежат в error.metadata
            $meta = $result['error']['metadata'] ?? null;
            if ($meta) {
                $errorMsg .= ' [' . mb_substr(json_encode($meta, JSON_UNESCAPED_UNICODE), 0, 300) . ']';
            }
            if ($errorMsg === '') {
                $errorMsg = mb_substr(trim(strip_tags((string)$response)), 0, 300);
            }
            $model = $data['model'] ?? '';
            throw new \RuntimeException('OpenRouter API error: HTTP ' . $httpCode . ($errorMsg !== '' ? ' — ' . $errorMsg : '') . ($model !== '' ? ' (модель ' . $model . ')' : ''));
        }

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function getEmbedding(string $text): array
    {
        $result = $this->request('/embeddings', [
            'model' => $this->defaultEmbeddingModel,
            'input' => $text,
        ]);

        return $result['data'][0]['embedding'] ?? [];
    }

    /**
     * @inheritDoc
     */
    public function getEmbeddings(array $texts): array
    {
        $result = $this->request('/embeddings', [
            'model' => $this->defaultEmbeddingModel,
            'input' => $texts,
        ]);

        $embeddings = [];
        foreach ($result['data'] ?? [] as $item) {
            $embeddings[$item['index']] = $item['embedding'] ?? [];
        }
        return $embeddings;
    }

    /**
     * @inheritDoc
     */
    public function chat(array $messages, array $options = []): array
    {
        $payload = [
            'model'    => $options['model'] ?? $this->defaultChatModel,
            'messages' => $messages,
        ];

        if (isset($options['temperature'])) {
            $payload['temperature'] = $options['temperature'];
        }
        if (isset($options['max_tokens'])) {
            $payload['max_tokens'] = $options['max_tokens'];
        }

        $result = $this->request('/chat/completions', $payload);

        return [
            'content'      => $result['choices'][0]['message']['content'] ?? '',
            'model'        => $result['model'] ?? '',
            'usage'        => $result['usage'] ?? [],
            'finish_reason' => $result['choices'][0]['finish_reason'] ?? '',
        ];
    }
}
