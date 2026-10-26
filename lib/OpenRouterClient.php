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
        $this->proxy = self::normalizeProxy((string)($config['proxy'] ?? ''));
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

    /**
     * Адрес прокси в формате curl. Понимает и популярную запись host:port:user:pass (считается HTTP-прокси),
     * готовые адреса с протоколом (http://, https://, socks5://) оставляет как есть.
     */
    public static function normalizeProxy(string $proxy): string
    {
        $proxy = trim($proxy);
        if ($proxy !== '' && !str_contains($proxy, '://') && preg_match('/^([^:\/@\s]+):(\d{1,5}):([^:\s]+):(\S+)$/', $proxy, $m)) {
            return 'http://' . rawurlencode($m[3]) . ':' . rawurlencode($m[4]) . '@' . $m[1] . ':' . $m[2];
        }
        return $proxy;
    }

    /** Настройки curl для запросов через прокси (http://host:port, socks5://user:pass@host:port); пусто — напрямую. */
    /** Описание прокси для диагностики: без логина и пароля. */
    public function proxyLabel(): string
    {
        if ($this->proxy === '') {
            return 'прокси НЕ задан, запрос идёт напрямую';
        }
        $p = parse_url($this->proxy);
        return 'через прокси ' . ($p['scheme'] ?? 'http') . '://' . ($p['host'] ?? '?') . (isset($p['port']) ? ':' . $p['port'] : '');
    }

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
            throw new \RuntimeException('OpenRouter API error: HTTP ' . $httpCode . ($errorMsg !== '' ? ' — ' . $errorMsg : '') . ($model !== '' ? ' (модель ' . $model . ')' : '') . '; ' . $this->proxyLabel());
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

    /**
     * Генерирует картинку по описанию. Сначала через Image API (/images, нужен моделям только для картинок,
     * например qwen/qwen-image-*), если такого адреса для модели нет, то через chat/completions (Gemini и т. п.).
     * @return array{data: string, mime: string} бинарные данные картинки и mime-тип
     * @throws \RuntimeException
     */
    public function generateImage(string $prompt, array $options = []): array
    {
        $model = $options['model'] ?? 'google/gemini-2.5-flash-image';
        $previousTimeout = $this->timeout;
        $this->timeout = $options['timeout'] ?? 120;
        try {
            try {
                $result = $this->request('/images', array_filter([
                    'model'        => $model,
                    'prompt'       => $prompt,
                    'aspect_ratio' => $options['aspect_ratio'] ?? '16:9',
                    'resolution'   => $options['resolution'] ?? '1K',
                ]));
                $b64 = $result['data'][0]['b64_json'] ?? '';
                $mime = $result['data'][0]['media_type'] ?? 'image/png';
            } catch (\RuntimeException $e) {
                // модель не поддерживает Image API: пробуем chat/completions
                if (strpos($e->getMessage(), 'HTTP 404') === false && strpos($e->getMessage(), 'HTTP 405') === false) {
                    throw $e;
                }
                $result = $this->request('/chat/completions', [
                    'model'      => $model,
                    'messages'   => [['role' => 'user', 'content' => $prompt]],
                    'modalities' => $this->imageOnlyModel($model) ? ['image'] : ['image', 'text'],
                ]);
                $url = $result['choices'][0]['message']['images'][0]['image_url']['url'] ?? '';
                if (!preg_match('~^data:(image/[a-z0-9.+-]+);base64,(.+)$~is', $url, $m)) {
                    throw new \RuntimeException('OpenRouter не вернул картинку');
                }
                $mime = $m[1];
                $b64 = $m[2];
            }
        } finally {
            $this->timeout = $previousTimeout;
        }

        $data = base64_decode((string)$b64, true);
        if ($data === false || $data === '') {
            throw new \RuntimeException('OpenRouter не вернул картинку');
        }
        return ['data' => $data, 'mime' => strtolower((string)$mime)];
    }

    /** Модели, которые умеют только рисовать (без текстового ответа): им нужен modalities = ['image']. */
    private function imageOnlyModel(string $model): bool
    {
        return (bool)preg_match('~^(black-forest-labs|sourceful|bytedance-seed)/~', $model);
    }
}
