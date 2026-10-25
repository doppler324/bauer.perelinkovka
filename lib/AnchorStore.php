<?php

namespace Bauer\Perelinkovka;

/**
 * Class AnchorStore
 *
 * Хранилище применённых текстов статей (data/anchors.json):
 * [article_id => ['text' => html, 'links' => [...], 'updated' => дата]].
 */
class AnchorStore implements AnchorStoreInterface
{
    private string $file;
    private array $data;

    public function __construct(string $file)
    {
        $this->file = $file;
        $loaded = is_file($file) ? json_decode(file_get_contents($file), true) : null;
        $this->data = is_array($loaded) ? $loaded : [];
    }

    public function get(int $articleId): ?array
    {
        $row = $this->data[$articleId] ?? null;
        return is_array($row) && isset($row['text']) ? $row : null;
    }

    public function save(int $articleId, string $html, array $links): bool
    {
        $this->data[$articleId] = [
            'text'    => $html,
            'links'   => $links,
            'updated' => date('Y-m-d H:i:s'),
        ];
        return $this->flush();
    }

    public function reset(int $articleId): bool
    {
        unset($this->data[$articleId]);
        return $this->flush();
    }

    private function flush(): bool
    {
        return file_put_contents(
            $this->file,
            json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ) !== false;
    }
}
