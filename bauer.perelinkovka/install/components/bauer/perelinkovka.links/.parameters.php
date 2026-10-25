<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

\Bitrix\Main\Loader::includeModule('bauer.perelinkovka');

// Список блоков берётся из настроек модуля - блоки добавляются со своими названиями
$blocks = [];
foreach ((new \Bauer\Perelinkovka\BitrixDataProvider())->getBlocks(['active' => true]) as $b) {
    $blocks[$b['code']] = $b['name'] . ' [' . $b['code'] . ']';
}

$arComponentParameters = [
    'PARAMETERS' => [
        'BLOCK_CODE' => [
            'PARENT' => 'BASE',
            'NAME' => 'Блок перелинковки',
            'TYPE' => 'LIST',
            'VALUES' => $blocks,
            'ADDITIONAL_VALUES' => 'N',
        ],
        'ENTITY_TYPE' => ['PARENT' => 'BASE', 'NAME' => 'Тип сущности', 'TYPE' => 'LIST',
            'VALUES' => ['element' => 'Элемент', 'section' => 'Раздел'], 'DEFAULT' => 'element'],
        'ENTITY_ID' => ['PARENT' => 'BASE', 'NAME' => 'ID сущности', 'TYPE' => 'STRING', 'DEFAULT' => '={$arResult["ID"]}'],
        'TITLE' => ['PARENT' => 'VISUAL', 'NAME' => 'Заголовок блока', 'TYPE' => 'STRING', 'DEFAULT' => 'Похожие товары'],
        'COLUMNS' => ['PARENT' => 'VISUAL', 'NAME' => 'Колонок в ряду', 'TYPE' => 'STRING', 'DEFAULT' => '4'],
        'SHOW_PICTURE' => ['PARENT' => 'VISUAL', 'NAME' => 'Показывать картинку', 'TYPE' => 'CHECKBOX', 'DEFAULT' => 'Y'],
        'SHOW_PREVIEW' => ['PARENT' => 'VISUAL', 'NAME' => 'Показывать краткое описание', 'TYPE' => 'CHECKBOX', 'DEFAULT' => 'N'],
        'SHOW_TYPE' => ['PARENT' => 'VISUAL', 'NAME' => 'Показывать тип связи', 'TYPE' => 'CHECKBOX', 'DEFAULT' => 'N'],
        'CACHE_TIME' => ['DEFAULT' => 3600],
    ],
];
