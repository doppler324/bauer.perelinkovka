<?php
/**
 * Автозагрузчик классов для тестирования модуля без Битрикс.
 * Соответствует неймспейсу Bauer\Perelinkovka
 * 
 * @param string $class - полное имя класса с неймспейсом
 */
spl_autoload_register(function (string $class) {
    // Базовый неймспейс модуля
    $prefix = 'Bauer\\Perelinkovka\\';
    $baseDir = __DIR__ . '/../lib/';

    // Проверяем, относится ли класс к нашему неймспейсу
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    // Убираем префикс и формируем путь
    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});
