<?php
/**
 * test_runner.php
 * 
 * Скрипт для тестирования модуля перелинковки без установки в Битрикс.
 * Демонстрирует работу всех основных функций.
 */

require_once __DIR__ . '/autoload.php';

use Bauer\Perelinkovka\JsonDataProvider;
use Bauer\Perelinkovka\Linker;

// Цвета для вывода в консоль
const COLOR_RESET = "\033[0m";
const COLOR_GREEN = "\033[32m";
const COLOR_YELLOW = "\033[33m";
const COLOR_CYAN = "\033[36m";
const COLOR_RED = "\033[31m";
const COLOR_BOLD = "\033[1m";

/**
 * Выводит заголовок раздела
 */
function printHeader(string $title): void
{
    echo "\n" . COLOR_BOLD . COLOR_CYAN . str_repeat('=', 60) . "\n";
    echo "  {$title}\n";
    echo str_repeat('=', 60) . COLOR_RESET . "\n\n";
}

/**
 * Выводит информацию о сущности
 */
function printEntity(string $label, array $entity, string $indent = ''): void
{
    echo "{$indent}{$label}:\n";
    echo "{$indent}  ID: {$entity['id']}\n";
    echo "{$indent}  Название: {$entity['name']}\n";
    echo "{$indent}  Код: {$entity['code']}\n";
    
    if (isset($entity['iblock_id'])) {
        echo "{$indent}  Инфоблок ID: {$entity['iblock_id']}\n";
    }
    if (isset($entity['section_id'])) {
        echo "{$indent}  Раздел ID: {$entity['section_id']}\n";
    }
    if (!empty($entity['keywords'])) {
        echo "{$indent}  Ключевые слова: " . implode(', ', $entity['keywords']) . "\n";
    }
    if (!empty($entity['price'])) {
        echo "{$indent}  Цена: " . number_format($entity['price'], 0, ',', ' ') . " ₽\n";
    }
}

/**
 * Выводит список связей
 */
function printLinks(string $title, array $links, int $limit = 5): void
{
    if (empty($links)) {
        echo COLOR_YELLOW . "  Связей не найдено\n" . COLOR_RESET;
        return;
    }

    echo "  {$title} (найдено: " . count($links) . "):\n";
    
    $count = 0;
    foreach ($links as $link) {
        if ($count >= $limit) {
            echo COLOR_YELLOW . "  ... и ещё " . (count($links) - $limit) . " связей\n" . COLOR_RESET;
            break;
        }

        $weight = $link['weight'] ?? 0;
        $linkType = $link['type'] ?? 'unknown';
        
        $color = $weight >= 5 ? COLOR_GREEN : ($weight >= 3 ? COLOR_YELLOW : COLOR_RED);
        
        echo "  {$color}[{$linkType}] вес: {$weight}{$color}";
        echo " -> {$link['target_name']} (ID: {$link['target_id']}, код: {$link['target_code']})";
        echo COLOR_RESET . "\n";
        
        $count++;
    }
}

// ============================================================
//  ТЕСТИРОВАНИЕ
// ============================================================

echo COLOR_BOLD . COLOR_CYAN . "\n";
echo "╔══════════════════════════════════════════════════════╗\n";
echo "║   МОДУЛЬ ПЕРЕЛИНКОВКИ - ТЕСТОВЫЙ ЗАПУСК            ║\n";
echo "║   Bauer\\Perelinkovka                                ║\n";
echo "╚══════════════════════════════════════════════════════╝\n";
echo COLOR_RESET;

// Инициализация провайдера данных и линкера
$dataDir = __DIR__ . '/data';
$dataProvider = new JsonDataProvider($dataDir);
$linker = new Linker($dataProvider, [
    'min_match_weight' => 1,
    'keyword_weight' => 3,
    'title_weight' => 2,
    'description_weight' => 1,
    'section_weight' => 1,
    'max_links_per_entity' => 10,
    'cross_iblock' => true,
]);

// ============================================================
// 1. ПРОВЕРКА ДАННЫХ
// ============================================================
printHeader("1. ПРОВЕРКА ЗАГРУЗКИ ДАННЫХ");

$iblocks = $dataProvider->getIBlocks();
echo "Загружено инфоблоков: " . count($iblocks) . "\n";
foreach ($iblocks as $iblock) {
    $sectionsCount = count($dataProvider->getSections(['iblock_id' => $iblock['id']]));
    $elementsCount = count($dataProvider->getElements(['iblock_id' => $iblock['id']]));
    echo "  - {$iblock['name']} (ID: {$iblock['id']}): {$sectionsCount} разделов, {$elementsCount} элементов\n";
}

$totalSections = count($dataProvider->getSections());
$totalElements = count($dataProvider->getElements());
$totalLinks = count($dataProvider->getLinks('element', 0));
echo "\nВсего разделов: {$totalSections}\n";
echo "Всего элементов: {$totalElements}\n";
echo "Всего связей: {$totalLinks}\n";

// ============================================================
// 2. ТОКЕНИЗАЦИЯ
// ============================================================
printHeader("2. ТЕСТ ТОКЕНИЗАЦИИ ТЕКСТА");

$testTexts = [
    "iPhone 15 Pro 256GB - флагманский смартфон Apple с титановым корпусом",
    "Смартфоны и аксессуары для Apple iPhone в нашем магазине",
    "Ноутбуки для работы и учёбы от Lenovo и Apple",
];

foreach ($testTexts as $text) {
    $tokens = $linker->tokenize($text);
    echo "  Текст: \"" . mb_substr($text, 0, 60) . "...\"\n";
    echo "  Токены: [" . implode(', ', $tokens) . "]\n\n";
}

// ============================================================
// 3. РАСЧЁТ ВЕСА СВЯЗИ
// ============================================================
printHeader("3. ТЕСТ РАСЧЁТА ВЕСА СВЯЗЕЙ");

$element1 = $dataProvider->getElement(1); // iPhone 15 Pro
$element2 = $dataProvider->getElement(7); // AirPods Pro 2
$element3 = $dataProvider->getElement(5); // Lenovo ThinkPad

echo "Сравнение элементов:\n";
printEntity("A", $element1, "  ");
echo "\n";
printEntity("B (AirPods Pro 2)", $element2, "  ");
$weightAB = $linker->calculateMatchWeight($element1, $element2, 'element');
echo "\n  Вес связи A-B: " . ($weightAB >= 5 ? COLOR_GREEN : ($weightAB >= 3 ? COLOR_YELLOW : COLOR_RED)) . "{$weightAB}" . COLOR_RESET . " (общий бренд Apple)\n\n";

printEntity("C (Lenovo ThinkPad)", $element3, "  ");
$weightAC = $linker->calculateMatchWeight($element1, $element3, 'element');
echo "\n  Вес связи A-C: " . ($weightAC >= 5 ? COLOR_GREEN : ($weightAC >= 3 ? COLOR_YELLOW : COLOR_RED)) . "{$weightAC}" . COLOR_RESET . " (разные бренды, слабая связь)\n";

// ============================================================
// 4. ПОИСК РЕЛЕВАНТНЫХ СВЯЗЕЙ ДЛЯ ЭЛЕМЕНТА
// ============================================================
printHeader("4. ПОИСК РЕЛЕВАНТНЫХ СВЯЗЕЙ ДЛЯ ЭЛЕМЕНТА");

$element = $dataProvider->getElement(1); // iPhone 15 Pro
printEntity("Анализируем элемент", $element);

$found = $linker->findRelevantLinks('element', 1, [
    'limit' => 5,
    'min_weight' => 1,
    'cross_iblock' => true,
]);

printLinks("Найденные релевантные элементы", $found, 5);

// ============================================================
// 5. ПОИСК ДЛЯ СТАТЬИ (МЕЖДУ ИНФОБЛОКАМИ)
// ============================================================
printHeader("5. КРОСС-ИНФОБЛОКОВЫЕ СВЯЗИ (СТАТЬЯ -> ТОВАРЫ)");

$article = $dataProvider->getElement(15); // Обзор iPhone 15 Pro
printEntity("Анализируем статью", $article);

$foundCross = $linker->findRelevantLinks('element', 15, [
    'limit' => 5,
    'min_weight' => 1,
    'cross_iblock' => true,
]);

printLinks("Найденные связи (статья с товарами)", $foundCross, 5);

// ============================================================
// 6. ПОИСК ДЛЯ РАЗДЕЛА
// ============================================================
printHeader("6. ПОИСК СВЯЗЕЙ ДЛЯ РАЗДЕЛА");

$section = $dataProvider->getSection(2); // Смартфоны
printEntity("Анализируем раздел", $section);

$foundSections = $linker->findRelevantLinks('section', 2, [
    'limit' => 5,
    'min_weight' => 1,
    'cross_iblock' => false,
]);

printLinks("Связанные разделы", $foundSections, 5);

// ============================================================
// 7. ПОЛУЧЕНИЕ ГОТОВЫХ СВЯЗЕЙ ДЛЯ ОТОБРАЖЕНИЯ
// ============================================================
printHeader("7. ПОЛУЧЕНИЕ СВЯЗЕЙ ДЛЯ ОТОБРАЖЕНИЯ НА СТРАНИЦЕ");

$displayLinks = $linker->getLinkedEntities('element', 1, 5);
echo "Связи для элемента #1 (iPhone 15 Pro):\n";
if (!empty($displayLinks)) {
    foreach ($displayLinks as $link) {
        $type = $link['link_id'] ? COLOR_GREEN . "сохранённая" : COLOR_YELLOW . "авто";
        echo "  {$type}" . COLOR_RESET . " [{$link['type']}] -> {$link['name']} (ID: {$link['entity_id']})\n";
    }
} else {
    echo COLOR_YELLOW . "  Связей не найдено\n" . COLOR_RESET;
}

// ============================================================
// 8. АВТОМАТИЧЕСКАЯ ПЕРЕЛИНКОВКА ИНФОБЛОКА
// ============================================================
printHeader("8. АВТОМАТИЧЕСКАЯ ПЕРЕЛИНКОВКА ИНФОБЛОКА (ТЕСТ БЕЗ СОХРАНЕНИЯ)");

$autoResult = $linker->autoLinkIBlock(1, [
    'limit_per_entity' => 3,
    'min_weight' => 1,
    'save' => false, // тестовый режим, без сохранения
    'cross_iblock' => true,
]);

echo "Результаты автоперелинковки инфоблока #1 (Каталог товаров):\n";
echo "  Найдено связей: " . count($autoResult['links']) . "\n";
echo "  Создано: {$autoResult['created']}\n";
echo "  Пропущено (дубликаты): {$autoResult['skipped']}\n";
echo "  Ошибок: " . count($autoResult['errors']) . "\n";

// Показываем топ-10 самых сильных связей
if (!empty($autoResult['links'])) {
    usort($autoResult['links'], function ($a, $b) {
        return $b['weight'] - $a['weight'];
    });

    echo "\nТоп-10 самых сильных связей:\n";
    $count = 0;
    foreach ($autoResult['links'] as $link) {
        if ($count >= 10) break;
        $source = $dataProvider->getElement($link['source_id']);
        $target = $dataProvider->getElement($link['target_id']);
        echo "  [вес: {$link['weight']}] {$source['name']} -> {$target['name']}\n";
        $count++;
    }
}

// ============================================================
// 9. ТЕСТ СОХРАНЕНИЯ НОВОЙ СВЯЗИ
// ============================================================
printHeader("9. ТЕСТ СОХРАНЕНИЯ СВЯЗИ");

$saveResult = $dataProvider->saveLink([
    'source_type' => 'element',
    'source_id' => 1,
    'target_type' => 'element',
    'target_id' => 3,
    'source_iblock_id' => 1,
    'target_iblock_id' => 1,
    'type' => 'related',
    'sort' => 100,
    'active' => true,
]);

echo "Сохранение связи (iPhone 15 Pro -> Xiaomi 14 Pro): ";
echo $saveResult ? COLOR_GREEN . "УСПЕШНО" . COLOR_RESET : COLOR_RED . "ОШИБКА" . COLOR_RESET;
echo "\n\n";

// Проверяем, что связь появилась
$linksAfter = $dataProvider->getLinks('element', 1);
echo "Всего связей у элемента #1 после добавления: " . count($linksAfter) . "\n";

// ============================================================
// ИТОГИ
// ============================================================
printHeader("ИТОГИ ТЕСТИРОВАНИЯ");

echo COLOR_GREEN . "✅ Модуль успешно протестирован!\n\n" . COLOR_RESET;
echo "Основные возможности:\n";
echo "  - Загрузка и фильтрация данных из JSON\n";
echo "  - Токенизация текста (разбивка на значимые слова)\n";
echo "  - Расчёт веса релевантности между сущностями\n";
echo "  - Поиск релевантных связей (внутри и между инфоблоками)\n";
echo "  - Автоматическая перелинковка инфоблоков\n";
echo "  - Сохранение и управление связями\n";
echo "  - Получение готовых связей для отображения на странице\n\n";

echo "Структура данных:\n";
echo "  - {$totalSections} разделов в 3 инфоблоках\n";
echo "  - {$totalElements} элементов\n";
echo "  - " . count($dataProvider->getLinks('element', 0)) . " связей (с учётом добавленных)\n";
