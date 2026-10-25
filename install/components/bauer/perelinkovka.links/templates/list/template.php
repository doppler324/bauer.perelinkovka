<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();
/**
 * Шаблон «Список ссылок» (простой, без картинок). Стили: style.css рядом.
 * @var array $arResult
 * @var array $arParams
 */
if (empty($arResult['ITEMS'])) return;
?>
<section class="pl-list">
    <?php if ($arParams['TITLE'] !== ''): ?>
        <h3 class="pl-list__title"><?= htmlspecialcharsbx($arParams['TITLE']) ?></h3>
    <?php endif ?>
    <ul class="pl-list__items">
    <?php foreach ($arResult['ITEMS'] as $item): ?>
        <li class="pl-list__item"><a href="<?= htmlspecialcharsbx($item['url'] ?? '#') ?>"><?= htmlspecialcharsbx($item['name']) ?></a></li>
    <?php endforeach ?>
    </ul>
</section>
