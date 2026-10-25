<?php
/**
 * anchor_settings_tab.php — настройки расстановки анкоров (?tab=anchor_settings).
 * Сохраняются в data/anchor_settings.json. Ожидает $dataDir.
 */

use Bauer\Perelinkovka\AnchorEngine;

$anchorSettings = \Bauer\Perelinkovka\Env::anchorOptions();

$anchorSettingsSaved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_anchor_settings'])) {
    $list = fn($s) => array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/u', (string)$s))));
    $anchorSettings = [
        'use_name'              => !empty($_POST['use_name']),
        'use_parts'             => !empty($_POST['use_parts']),
        'use_keywords'          => !empty($_POST['use_keywords']),
        'use_synonyms'          => !empty($_POST['use_synonyms']),
        'max_part_words'        => max(1, min(5, (int)($_POST['max_part_words'] ?? 3))),
        'min_word_len'          => max(1, min(10, (int)($_POST['min_word_len'] ?? 4))),
        'max_links_per_article' => max(0, (int)($_POST['max_links_per_article'] ?? 0)),
        'one_link_per_block'    => !empty($_POST['one_link_per_block']),
        'target_elements'       => !empty($_POST['target_elements']),
        'target_sections'       => !empty($_POST['target_sections']),
        'target_iblock_ids'     => array_values(array_map('intval', (array)($_POST['target_iblock_ids'] ?? []))),
        'skip_tags'             => array_map('strtolower', $list($_POST['skip_tags'] ?? '')),
        'stop_words'            => array_map('mb_strtolower', $list($_POST['stop_words'] ?? '')),
    ];
    \Bauer\Perelinkovka\Env::saveAnchorOptions($anchorSettings);
    $anchorSettingsSaved = true;
}

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$chk = fn($k) => !empty($anchorSettings[$k]) ? 'checked' : '';
?>
<h1 class="bx-page-title">Настройки: анкоры в тексте</h1>

<?php if ($anchorSettingsSaved): ?>
    <div class="bx-notification bx-notification-success">✅ Настройки сохранены</div>
<?php endif; ?>

<form method="POST" action="?tab=anchor_settings">
    <input type="hidden" name="save_anchor_settings" value="1">

    <div class="bx-settings-section">
        <h3>Откуда брать фразы для анкоров</h3>
        <div class="bx-settings-row"><label>Название цели</label><input type="checkbox" name="use_name" value="1" <?= $chk('use_name') ?>></div>
        <div class="bx-settings-row"><label>Части названия</label><input type="checkbox" name="use_parts" value="1" <?= $chk('use_parts') ?>>
            <span class="hint">последовательности слов из названия</span></div>
        <div class="bx-settings-row"><label>Ключевые слова (keywords)</label><input type="checkbox" name="use_keywords" value="1" <?= $chk('use_keywords') ?>></div>
        <div class="bx-settings-row"><label>Синонимы (synonyms.json)</label><input type="checkbox" name="use_synonyms" value="1" <?= $chk('use_synonyms') ?>></div>
        <div class="bx-settings-row"><label>Макс. слов в части названия</label><input type="number" name="max_part_words" min="1" max="5" value="<?= (int)$anchorSettings['max_part_words'] ?>"></div>
        <div class="bx-settings-row"><label>Мин. длина одного слова</label><input type="number" name="min_word_len" min="1" max="10" value="<?= (int)$anchorSettings['min_word_len'] ?>">
            <span class="hint">однословные фразы короче пропускаются</span></div>
        <div class="bx-settings-row"><label>Стоп-слова</label><input type="text" name="stop_words" value="<?= $h(implode(', ', $anchorSettings['stop_words'])) ?>">
            <span class="hint">через запятую, не входят в части названия</span></div>
    </div>

    <div class="bx-settings-section">
        <h3>Лимиты и исключения</h3>
        <div class="bx-settings-row"><label>Одна ссылка на абзац</label><input type="checkbox" name="one_link_per_block" value="1" <?= $chk('one_link_per_block') ?>></div>
        <div class="bx-settings-row"><label>Макс. ссылок на статью</label><input type="number" name="max_links_per_article" min="0" value="<?= (int)$anchorSettings['max_links_per_article'] ?>">
            <span class="hint">0 — без ограничения; одна ссылка на каждую цель действует всегда</span></div>
        <div class="bx-settings-row"><label>Не ставить ссылки внутри тегов</label><input type="text" name="skip_tags" value="<?= $h(implode(', ', $anchorSettings['skip_tags'])) ?>">
            <span class="hint">тег a пропускается всегда</span></div>
    </div>

    <div class="bx-settings-section">
        <h3>Цели</h3>
        <div class="bx-settings-row"><label>Ссылаться на элементы</label><input type="checkbox" name="target_elements" value="1" <?= $chk('target_elements') ?>></div>
        <div class="bx-settings-row"><label>Ссылаться на разделы</label><input type="checkbox" name="target_sections" value="1" <?= $chk('target_sections') ?>></div>
        <div class="bx-settings-row"><label>Инфоблоки-цели</label><div>
        <?php $selIb = array_map('intval', (array)($anchorSettings['target_iblock_ids'] ?? [])); foreach (\Bauer\Perelinkovka\Env::provider()->getIBlocks() as $ib): ?>
            <label style="display:block;"><input type="checkbox" name="target_iblock_ids[]" value="<?= (int)$ib['id'] ?>" <?= in_array((int)$ib['id'], $selIb, true) ? 'checked' : '' ?>> <?= $h($ib['name'] ?? $ib['id']) ?></label>
        <?php endforeach; ?></div><span class="hint">ничего не выбрано — все инфоблоки</span></div>
        <div class="bx-settings-row"><span class="hint" style="margin-left:0;">Адреса ссылок берутся из настроек инфоблока (DETAIL_PAGE_URL / SECTION_PAGE_URL).</span></div>
    </div>

    <button type="submit" class="bx-btn bx-btn-primary">Сохранить</button>
    <a href="?tab=anchors" class="bx-btn">К расстановке анкоров</a>
</form>
