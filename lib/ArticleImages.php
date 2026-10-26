<?php

namespace Bauer\Perelinkovka;

/**
 * Генерация картинок для статей.
 * Находит в тексте блоки вида «[Изображение: описание]», генерирует по описанию картинку
 * через OpenRouter, сохраняет файл и подставляет в текст тег <img>.
 */
class ArticleImages
{
    /** Прежнее значение стиля по умолчанию: сохранённое такое же считается незаданным. */
    public const OLD_DEFAULT_STYLE = 'Реалистичная фотография, естественный свет, без текста, надписей и логотипов на изображении.';

    /** Общий стиль картинок по умолчанию (добавляется к описанию). */
    public const DEFAULT_STYLE = 'Style: simple, clean, schematic illustration-like photo, not photorealistic. Flat even lighting, plain light neutral background, minimal details, only the objects needed to show the idea, clear composition. '
        . 'Strictly no text of any kind: no letters, words, numbers, captions, labels, signs, watermarks or logos anywhere in the image.';

    private const BLOCK_REGEX = '/\[Изображение:\s*(.+?)\s*\]/us';

    private OpenRouterClient $client;
    private string $saveDir;
    private string $urlPrefix;
    private array $options;

    /**
     * @param string $saveDir   папка на диске, куда сохраняются файлы
     * @param string $urlPrefix адрес этой папки на сайте (например /upload/article_images)
     * @param array  $options   model, style (приписывается к описанию)
     */
    public function __construct(OpenRouterClient $client, string $saveDir, string $urlPrefix, array $options = [])
    {
        $this->client = $client;
        $this->saveDir = rtrim($saveDir, '/\\');
        $this->urlPrefix = rtrim($urlPrefix, '/');
        $this->options = $options;
    }

    /** @return string[] описания всех блоков «[Изображение: ...]» в тексте */
    public static function findBlocks(string $text): array
    {
        preg_match_all(self::BLOCK_REGEX, $text, $m);
        return $m[1];
    }

    /**
     * Заменяет блоки на картинки. Блок, для которого генерация не удалась, остаётся в тексте как есть.
     * @param int|null $limit максимум генераций за вызов (null — без ограничения)
     * @return array{text: string, done: int, errors: string[]}
     */
    public function process(string $text, ?int $limit = null): array
    {
        $done = 0;
        $errors = [];

        $result = preg_replace_callback(self::BLOCK_REGEX, function (array $m) use (&$done, &$errors, $limit) {
            if ($limit !== null && $done >= $limit) {
                return $m[0];
            }
            try {
                $url = $this->generate($m[1]);
            } catch (\Throwable $e) {
                $errors[] = $m[1] . ': ' . $e->getMessage();
                return $m[0];
            }
            $done++;
            $alt = htmlspecialchars($m[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            return '<img src="' . $url . '" alt="' . $alt . '" loading="lazy">';
        }, $text);

        return ['text' => $result ?? $text, 'done' => $done, 'errors' => $errors];
    }

    /** Генерирует и сохраняет картинку, возвращает её адрес на сайте. Одинаковое описание = один файл. */
    public function generate(string $description): string
    {
        $name = substr(sha1($description), 0, 16);
        foreach (glob($this->saveDir . '/' . $name . '.*') ?: [] as $existing) {
            return $this->urlPrefix . '/' . basename($existing);
        }

        $prompt = trim('Image for an article. Scene to show (description in Russian): ' . $description . "\n\n" . ($this->options['style'] ?? self::DEFAULT_STYLE));
        $img = $this->client->generateImage($prompt, ['model' => $this->options['model'] ?? 'google/gemini-2.5-flash-image']);

        $ext = ['image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'][$img['mime']] ?? 'png';
        if (!is_dir($this->saveDir) && !mkdir($this->saveDir, 0775, true) && !is_dir($this->saveDir)) {
            throw new \RuntimeException('Не удалось создать папку ' . $this->saveDir);
        }
        $file = $name . '.' . $ext;
        if (file_put_contents($this->saveDir . '/' . $file, $img['data']) === false) {
            throw new \RuntimeException('Не удалось сохранить файл ' . $file);
        }
        return $this->urlPrefix . '/' . $file;
    }
}
