<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();
/**
 * Шаблон «Карточки». Чтобы подогнать под дизайн сайта:
 * скопируйте шаблон в /local/templates/<ваш шаблон>/components/bauer/perelinkovka.links/<имя>/
 * и правьте разметку здесь и цвета/размеры в style.css (переменные --pl-*).
 * @var array $arResult
 * @var array $arParams
 */
if (empty($arResult['ITEMS'])) return;
$typeLabels = ['strong' => 'сильная', 'medium' => 'средняя', 'weak' => 'слабая', 'manual' => 'ручная',
    'related' => 'связанная', 'accessory' => 'аксессуар', 'section' => 'раздел'];
?>
<section class="pl-block" style="--pl-columns: <?= (int)$arParams['COLUMNS'] ?>">
    <?php if ($arParams['TITLE'] !== ''): ?>
        <h2 class="pl-block__title"><?= htmlspecialcharsbx($arParams['TITLE']) ?></h2>
    <?php endif ?>
    <ul class="pl-block__list">
    <?php foreach ($arResult['ITEMS'] as $item): ?>
        <li class="pl-card">
            <a class="pl-card__link" href="<?= htmlspecialcharsbx($item['url'] ?? '#') ?>">
                <?php if ($arParams['SHOW_PICTURE']): ?>
                    <span class="pl-card__media">
                        <?php if (!empty($item['picture'])): ?>
                            <img class="pl-card__img" src="<?= htmlspecialcharsbx($item['picture']) ?>" alt="<?= htmlspecialcharsbx($item['name']) ?>" loading="lazy">
                        <?php else: ?>
                            <span class="pl-card__noimg"></span>
                        <?php endif ?>
                    </span>
                <?php endif ?>
                <span class="pl-card__name"><?= htmlspecialcharsbx($item['name']) ?></span>
                <?php if ($arParams['SHOW_PREVIEW'] && !empty($item['preview'])): ?>
                    <span class="pl-card__text"><?= htmlspecialcharsbx(mb_strimwidth($item['preview'], 0, 120, '…')) ?></span>
                <?php endif ?>
                <?php if ($arParams['SHOW_TYPE'] && !empty($item['type'])): ?>
                    <span class="pl-card__badge"><?= htmlspecialcharsbx($typeLabels[preg_replace('/^auto_/', '', $item['type'])] ?? $item['type']) ?></span>
                <?php endif ?>
            </a>
        </li>
    <?php endforeach ?>
    </ul>
</section>
