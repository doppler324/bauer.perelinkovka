<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

global $USER;
if (!is_object($USER) || !$USER->IsAdmin()) {
    return false;
}

$lang = '&lang=' . LANGUAGE_ID;
$page = 'bauer_perelinkovka.php?tab=';

return [
    'parent_menu' => 'global_menu_services',
    'section' => 'bauer_perelinkovka',
    'sort' => 500,
    'text' => 'Перелинковка',
    'title' => 'Перелинковка блоков и текстов',
    'icon' => 'iblock_menu_icon',
    'page_icon' => 'iblock_page_icon',
    'items_id' => 'menu_bauer_perelinkovka',
    'items' => [
        [
            'text' => 'Перелинковка блоков',
            'url' => $page . 'blocks' . $lang,
            'more_url' => [$page . 'list', $page . 'stats'],
            'title' => 'Блоки с правилами, связи и статистика',
        ],
        [
            'text' => 'Перелинковка внутри текстов',
            'url' => $page . 'anchors' . $lang,
            'more_url' => [$page . 'anchors_mass', $page . 'anchor_settings', $page . 'synonyms'],
            'title' => 'Ссылки-анкоры в тексте статей',
        ],
    ],
];
