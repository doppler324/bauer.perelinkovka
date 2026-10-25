<?php
/**
 * ui.php — общая оболочка админки модуля: вкладки и их содержимое.
 * Одинаково работает в тестовой админке (JSON) и в Битриксе; окружение задаёт Bauer\Perelinkovka\Env.
 */

use Bauer\Perelinkovka\Env;

const BP_TEXT_TABS = ['anchors', 'anchors_mass', 'anchor_settings', 'synonyms'];
const BP_ALL_TABS = ['blocks', 'list', 'stats', 'anchors', 'anchors_mass', 'anchor_settings', 'synonyms'];

function bp_tab(?string $tab): string
{
    return in_array($tab, BP_ALL_TABS, true) ? $tab : 'blocks';
}

function bp_section_titles(string $tab): array
{
    $titles = [
        'blocks' => 'Блоки и правила', 'list' => 'Список связей', 'stats' => 'Статистика',
        'anchors' => 'Анкоры в текстах', 'anchors_mass' => 'Массовая расстановка',
        'anchor_settings' => 'Настройки анкоров', 'synonyms' => 'Синонимы',
    ];
    $section = in_array($tab, BP_TEXT_TABS, true) ? 'Перелинковка внутри текстов' : 'Перелинковка блоков';
    return [$section, $titles[$tab] ?? ''];
}

/** Выводит оба меню (разделы и вкладки раздела). */
function bp_render_menus(string $tab, string $settingsUrl = ''): void
{
    $isText = in_array($tab, BP_TEXT_TABS, true);
    $a = fn(string $t, string $label) => '<a href="?tab=' . $t . '" class="' . ($tab === $t ? 'active' : '') . '">' . $label . '</a>';
    ?>
    <div class="bx-menu" style="background:#2c3e50;border-bottom:1px solid #1f2d3a;">
        <a href="?tab=blocks" class="<?= !$isText ? 'active' : '' ?>">🧩 Перелинковка блоков</a>
        <a href="?tab=anchors" class="<?= $isText ? 'active' : '' ?>">✍️ Перелинковка внутри текстов</a>
    </div>
    <div class="bx-menu">
        <?php if ($isText): ?>
            <?= $a('anchors', '🔗 Анкоры и синонимы') ?>
            <?= $a('anchors_mass', '📚 Массовая расстановка') ?>
            <?= $a('synonyms', '📖 Синонимы') ?>
            <?= $a('anchor_settings', '⚙️ Настройки') ?>
        <?php else: ?>
            <?= $a('blocks', '🧩 Блоки и правила') ?>
            <?= $a('list', '📋 Список связей') ?>
            <?= $a('stats', '📊 Статистика') ?>
            <?php if ($settingsUrl !== ''): ?><a href="<?= htmlspecialchars($settingsUrl, ENT_QUOTES) ?>" data-noajax>⚙️ Настройки модуля</a><?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
}

/** Подключает файл вкладки; переменные, которые ждут вкладки, готовятся здесь. */
function bp_render_tab(string $tab): void
{
    $dataProvider = Env::provider();
    $iblocks = $dataProvider->getIBlocks();
    require_once __DIR__ . '/tabs/labels.php';
    require __DIR__ . '/tabs/' . ($tab === 'list' ? 'links' : $tab) . '_tab.php';
}
