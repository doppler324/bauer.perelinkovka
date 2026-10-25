<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

use Bauer\Perelinkovka\BitrixDataProvider;
use Bauer\Perelinkovka\Linker;

if (!\Bitrix\Main\Loader::includeModule('bauer.perelinkovka')) {
    return;
}

$arParams['ENTITY_TYPE'] = $arParams['ENTITY_TYPE'] === 'section' ? 'section' : 'element';
$arParams['ENTITY_ID'] = (int)$arParams['ENTITY_ID'];
$arParams['BLOCK_CODE'] = (string)$arParams['BLOCK_CODE'];
$arParams['CACHE_TIME'] = (int)($arParams['CACHE_TIME'] ?? 3600);
$arParams['TITLE'] = (string)($arParams['TITLE'] ?? '');
$arParams['SHOW_PICTURE'] = ($arParams['SHOW_PICTURE'] ?? 'Y') !== 'N';
$arParams['SHOW_PREVIEW'] = ($arParams['SHOW_PREVIEW'] ?? 'N') === 'Y';
$arParams['SHOW_TYPE'] = ($arParams['SHOW_TYPE'] ?? 'N') === 'Y';
$arParams['COLUMNS'] = max(1, min(6, (int)($arParams['COLUMNS'] ?? 4)));

if ($this->startResultCache($arParams['CACHE_TIME'], [$arParams['BLOCK_CODE'], $arParams['ENTITY_TYPE'], $arParams['ENTITY_ID'], $arParams['SHOW_PICTURE'], $arParams['SHOW_PREVIEW']])) {
    // На странице только чтение готовых связей блока; расчёт делает пересчёт блока в админке/агент
    $linker = new Linker(new BitrixDataProvider());
    $arResult['ITEMS'] = $linker->getLinkedEntities(
        $arParams['ENTITY_TYPE'], $arParams['ENTITY_ID'], 5, $arParams['BLOCK_CODE'] ?: null
    );
    // URL берём из шаблона адреса инфоблока (DETAIL_PAGE_URL / SECTION_PAGE_URL)
    \Bitrix\Main\Loader::includeModule('iblock');
    foreach ($arResult['ITEMS'] as &$item) {
        $res = $item['entity_type'] === 'section'
            ? \CIBlockSection::GetByID($item['entity_id'])->GetNext()
            : \CIBlockElement::GetByID($item['entity_id'])->GetNext();
        $item['url'] = $res ? ($res['DETAIL_PAGE_URL'] ?? $res['SECTION_PAGE_URL'] ?? '#') : '#';
        $item['name'] = $item['name'] ?? ($res['NAME'] ?? '');
        $item['picture'] = '';
        $pictureId = (int)($res['PREVIEW_PICTURE'] ?? $res['PICTURE'] ?? 0);
        if ($arParams['SHOW_PICTURE'] && $pictureId) {
            $file = \CFile::ResizeImageGet($pictureId, ['width' => 400, 'height' => 400], BX_RESIZE_IMAGE_PROPORTIONAL);
            $item['picture'] = $file['src'] ?? '';
        }
        $item['preview'] = $arParams['SHOW_PREVIEW'] ? trim(strip_tags((string)($res['PREVIEW_TEXT'] ?? $res['DESCRIPTION'] ?? ''))) : '';
    }
    unset($item);
    if (defined('BX_COMP_MANAGED_CACHE')) { global $CACHE_MANAGER; $CACHE_MANAGER->StartTagCache($this->getCachePath()); $CACHE_MANAGER->RegisterTag('bauer_perelinkovka'); $CACHE_MANAGER->EndTagCache(); }
    $this->includeComponentTemplate();
}
