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
        'use_embeddings'        => !empty($_POST['use_embeddings']),
        'embedding_model'       => trim((string)($_POST['embedding_model'] ?? '')) ?: 'openai/text-embedding-3-small',
        'top_targets'           => max(5, min(500, (int)($_POST['top_targets'] ?? 60))),
        'min_cosine'            => max(0, min(1, (float)str_replace(',', '.', (string)($_POST['min_cosine'] ?? 0.3)))),
        'min_target_overlap'    => max(0, min(1, (float)str_replace(',', '.', (string)($_POST['min_target_overlap'] ?? 0.5)))),
        'max_word_df'           => max(0, min(1, (float)str_replace(',', '.', (string)($_POST['max_word_df'] ?? 0.02)))),
        'min_part_words'        => max(1, min(5, (int)($_POST['min_part_words'] ?? 2))),
        'max_part_targets'      => max(0, (int)($_POST['max_part_targets'] ?? 3)),
        'min_word_len'          => max(1, min(10, (int)($_POST['min_word_len'] ?? 4))),
        'max_links_per_article' => max(0, (int)($_POST['max_links_per_article'] ?? 0)),
        'one_link_per_block'    => !empty($_POST['one_link_per_block']),
        'unique_anchors'        => !empty($_POST['unique_anchors']),
        'skip_first_block'      => !empty($_POST['skip_first_block']),
        'words_per_link'        => max(0, (int)($_POST['words_per_link'] ?? 0)),
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
        <div class="bx-settings-row"><label>Мин. слов в части названия</label><input type="number" name="min_part_words" min="1" max="5" value="<?= (int)($anchorSettings['min_part_words'] ?? 2) ?>">
            <span class="hint">2 — однословные части («работа», «новая») не используются как анкоры</span></div>
        <div class="bx-settings-row"><label>Макс. целей с одинаковой частью названия</label><input type="number" name="max_part_targets" min="0" value="<?= (int)($anchorSettings['max_part_targets'] ?? 3) ?>">
            <span class="hint">если фраза есть в названиях более чем стольких страниц, она неоднозначна и пропускается; 0 — не проверять</span></div>
        <div class="bx-settings-row"><label>Тематическая связь статьи и цели</label><input type="text" name="min_target_overlap" value="<?= $h($anchorSettings['min_target_overlap'] ?? 0.5) ?>" size="5">
            <span class="hint">0…1: какая доля значимых слов названия цели должна быть в тексте статьи, чтобы брать части названия (не менее 2 слов); 0 — не проверять</span></div>
        <div class="bx-settings-row"><label>Доля названий для «общего» слова</label><input type="text" name="max_word_df" value="<?= $h($anchorSettings['max_word_df'] ?? 0.02) ?>" size="5">
            <span class="hint">0…1: в анкоре из части названия минимум два значимых слова, и каждое из них должно быть не более чем в такой доле названий целей; 0.02 — слова из 2% названий и чаще считаются общими; 0 — не проверять</span></div>
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
        <div class="bx-settings-row"><label>Не повторять одинаковый анкор</label><input type="checkbox" name="unique_anchors" value="1" <?= $chk('unique_anchors') ?>>
            <span class="hint">один и тот же текст ссылки не используется дважды в статье</span></div>
        <div class="bx-settings-row"><label>Не ставить ссылку в первом абзаце</label><input type="checkbox" name="skip_first_block" value="1" <?= $chk('skip_first_block') ?>></div>
        <div class="bx-settings-row"><label>Не чаще одной ссылки на N слов</label><input type="number" name="words_per_link" min="0" value="<?= (int)($anchorSettings['words_per_link'] ?? 0) ?>">
            <span class="hint">0 — без ограничения; например, 150 — не больше одной ссылки на 150 слов текста</span></div>
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

    <div class="bx-settings-section">
        <h3>Смысловой подбор (эмбеддинги)</h3>
        <div class="bx-settings-row"><label>Использовать эмбеддинги</label><input type="checkbox" name="use_embeddings" value="1" <?= $chk('use_embeddings') ?>>
            <span class="hint">берутся цели, ближайшие по смыслу к статье, а ссылка ставится в самый близкий по смыслу абзац; без включения работает поиск по словам</span></div>
        <div class="bx-settings-row"><label>Модель эмбеддингов</label><input type="text" name="embedding_model" value="<?= $h($anchorSettings['embedding_model'] ?? 'openai/text-embedding-3-small') ?>" size="36">
            <span class="hint">OpenRouter; при смене модели векторы целей нужно рассчитать заново</span></div>
        <div class="bx-settings-row"><label>Сколько целей брать для статьи</label><input type="number" name="top_targets" min="5" max="500" value="<?= (int)($anchorSettings['top_targets'] ?? 60) ?>"></div>
        <div class="bx-settings-row"><label>Мин. близость абзаца и цели</label><input type="text" name="min_cosine" value="<?= $h($anchorSettings['min_cosine'] ?? 0.3) ?>" size="5">
            <span class="hint">0…1; в предпросмотре у каждой ссылки показана близость, подберите порог по ней (обычно 0.25–0.45)</span></div>
    </div>

    <button type="submit" class="bx-btn bx-btn-primary">Сохранить</button>
    <a href="?tab=anchors" class="bx-btn">К расстановке анкоров</a>
</form>

<?php
$embKey = \Bauer\Perelinkovka\Env::apiKey() !== '';
$embService = \Bauer\Perelinkovka\Env::semantic(null, true);
?>
<form method="post" id="embIndexForm" class="bx-settings-section" style="margin-top:16px;" action="?tab=anchor_settings">
    <h3>Векторы целей</h3>
    <?php if (!empty($_GET['emb_done'])): [$embNew, $embAll] = array_pad(explode('_', (string)$_GET['emb_done']), 2, 0); ?>
        <div class="bx-notification bx-notification-success">✅ Расчёт завершён: новых векторов — <?= (int)$embNew ?> из <?= (int)$embAll ?> целей</div>
    <?php endif; ?>
    <p class="hint" style="margin:0 0 10px;">Ключ OpenRouter: <?= $embKey ? '<b>задан</b>' : '<b style="color:#b91c1c;">не задан</b> — укажите его в настройках модуля (Настройки продукта → Модули → Перелинковка)' ?>.
        Модель для расчёта (сохранённая): <b><?= $h($embService ? $embService->model() : ($anchorSettings['embedding_model'] ?? 'openai/text-embedding-3-small')) ?></b>.
        Рассчитано векторов: <b><?= $embService ? (int)$embService->indexedCount() : 0 ?></b> (при смене модели цели пересчитываются заново). Пересчитываются только новые и изменённые цели; перед запуском показывается оценка стоимости.</p>
    <button type="button" id="embIndexBtn" data-model="<?= $h($embService ? $embService->model() : '') ?>" class="bx-btn bx-btn-primary" <?= $embKey ? '' : 'disabled' ?>
        onclick="if (window.bpRunEmbIndex) { bpRunEmbIndex(); } else { alert('Файл progress.js устарел: выполните update.sh и обновите страницу (Ctrl+F5).'); }">Рассчитать векторы целей</button>
</form>
