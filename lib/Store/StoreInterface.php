<?php

namespace Bauer\Perelinkovka\Store;

/**
 * Небольшое хранилище настроек: именованные массивы.
 * В тестовой админке это JSON-файлы, в Битриксе - опции модуля.
 */
interface StoreInterface
{
    /** @return array|null null, если ничего не сохранено */
    public function read(string $name): ?array;

    public function write(string $name, array $data): bool;
}
