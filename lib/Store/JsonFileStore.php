<?php

namespace Bauer\Perelinkovka\Store;

/** Хранилище в JSON-файлах каталога: имя "synonyms" - файл synonyms.json. */
class JsonFileStore implements StoreInterface
{
    private string $dir;

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/\\');
    }

    private function file(string $name): string
    {
        return $this->dir . '/' . preg_replace('/[^a-z0-9_.-]/i', '', $name) . '.json';
    }

    public function read(string $name): ?array
    {
        $file = $this->file($name);
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string)file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    public function write(string $name, array $data): bool
    {
        return file_put_contents(
            $this->file($name),
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        ) !== false;
    }
}
