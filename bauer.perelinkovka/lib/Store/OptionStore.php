<?php

namespace Bauer\Perelinkovka\Store;

/** Хранилище в опциях модуля Битрикс (b_option). Для небольших данных: настройки, синонимы. */
class OptionStore implements StoreInterface
{
    private const MODULE = 'bauer.perelinkovka';

    public function read(string $name): ?array
    {
        $raw = \Bitrix\Main\Config\Option::get(self::MODULE, 'store_' . $name, '');
        if ($raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    public function write(string $name, array $data): bool
    {
        \Bitrix\Main\Config\Option::set(
            self::MODULE,
            'store_' . $name,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        return true;
    }
}
