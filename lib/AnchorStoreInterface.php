<?php

namespace Bauer\Perelinkovka;

/**
 * Хранилище расставленных в текстах ссылок.
 * Запись статьи: ['text' => html с ссылками, 'links' => [...], 'updated' => дата].
 */
interface AnchorStoreInterface
{
    public function get(int $articleId): ?array;

    public function save(int $articleId, string $html, array $links): bool;

    /** Возвращает исходный текст статьи и убирает запись. */
    public function reset(int $articleId): bool;
}
