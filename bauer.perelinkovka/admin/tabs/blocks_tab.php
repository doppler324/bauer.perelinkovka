<?php
/**
 * blocks_tab.php
 *
 * Вкладка «Блоки и правила»: создание и изменение блоков. Связи (вручную и массово) — на вкладке «Список связей».
 * Раньше здесь было три шага:
 *  1. создать/изменить блок (название, откуда, куда, лимит, порог);
 *  2. посмотреть, что блок найдёт для выбранной страницы (без сохранения);
 *  3. пересчитать блок и сохранить связи, увидеть готовый вывод.
 * Подключается из admin_preview.php, использует его $dataProvider и $iblocks.
 */

use Bauer\Perelinkovka\Linker;
use Bauer\Perelinkovka\LinksComponent;
use Bauer\Perelinkovka\KeywordMatchingStrategy;
use Bauer\Perelinkovka\TfIdfStrategy;

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$message = null;
$messageType = 'info';

/** Linker с теми стратегиями, что включены в блоке. */
$makeLinker = function (array $block) use ($dataProvider): Linker {
    $linker = new Linker($dataProvider);
    $strategies = [];
    $enabled = $block['strategies'] ?? ['keyword'];
    if (in_array('keyword', $enabled, true)) {
        $strategies[] = new KeywordMatchingStrategy(['weight' => 1.0]);
    }
    if (in_array('tfidf', $enabled, true)) {
        $strategies[] = new TfIdfStrategy($dataProvider, ['weight' => 1.0]);
    }
    if ($strategies) {
        $linker->setStrategies($strategies);
    }
    return $linker;
};

// ---------- Действия ----------
$action = $_POST['action'] ?? '';
$code = trim((string)($_POST['code'] ?? $_GET['block'] ?? ''));

if ($action === 'save_block') {
    $ok = $dataProvider->saveBlock([
        'code' => $code,
        'name' => trim((string)($_POST['name'] ?? '')) ?: $code,
        'active' => isset($_POST['active']),
        'sort' => (int)($_POST['sort'] ?? 100),
        'source_type' => ($_POST['source_type'] ?? '') === 'section' ? 'section' : 'element',
        'source_iblock_id' => (int)($_POST['source_iblock_id'] ?? 0),
        'target_type' => ($_POST['target_type'] ?? '') === 'section' ? 'section' : 'element',
        'target_iblock_ids' => array_map('intval', (array)($_POST['target_iblock_ids'] ?? [])),
        'max_links' => max(1, (int)($_POST['max_links'] ?? 5)),
        'min_score' => max(0.0, min(1.0, (float)($_POST['min_score'] ?? 0.15))),
        'balance' => max(0.0, min(1.0, (float)($_POST['balance'] ?? 0.5))),
        'strategies' => array_values((array)($_POST['strategies'] ?? ['keyword'])),
    ]);
    $message = $ok ? "Блок «{$code}» сохранён. Теперь проверьте его на шаге 2." : 'Не удалось сохранить: код только латиницей, цифры и «_».';
    $messageType = $ok ? 'success' : 'error';
} elseif ($action === 'delete_block') {
    $dataProvider->deleteBlock($code);
    $message = "Блок «{$code}» удалён (уже сохранённые связи остаются в списке связей).";
    $code = '';
}

$blocks = $dataProvider->getBlocks();
$selected = $code !== '' ? $dataProvider->getBlock($code) : ($blocks[0] ?? null);
$editBlock = isset($_GET['edit']) ? $dataProvider->getBlock((string)$_GET['edit']) : null;
$iblockName = function (int $id) use ($iblocks): string {
    foreach ($iblocks as $ib) {
        if ((int)$ib['id'] === $id) {
            return $ib['name'];
        }
    }
    return '#' . $id;
};
$typeName = fn($t) => $t === 'section' ? 'разделы' : 'элементы';
?>
<h1 class="bx-page-title">Блоки перелинковки</h1>
<p style="color:#666;max-width:820px;">
    Здесь создаются блоки. Связи для них добавляются на вкладке «Список связей» вручную или массово.<br>
    Блок — это набор правил под одно место на сайте (например «Похожие товары» или «Что почитать»).
    Вы сами называете блок и задаёте: <b>откуда</b> берём страницу, <b>куда</b> ссылаемся, сколько ссылок
    и насколько они должны быть релевантными. В компоненте на сайте выбирается нужный блок.
</p>

<?php if ($message): ?>
    <div class="bx-notification bx-notification-<?= $h($messageType) ?>"><?= $h($message) ?></div>
<?php endif; ?>


<div style="display:flex;justify-content:space-between;align-items:center;margin-top:24px;">
    <h2 style="margin:0;">Ваши блоки</h2>
    <button type="button" class="bx-btn bx-btn-primary" onclick="toggleBlockForm(true)">➕ Добавить блок</button>
</div>
<div class="bx-table-wrap">
<table class="bx-table">
    <thead><tr><th>Название</th><th>Код</th><th>Откуда</th><th>Куда</th><th>Лимит</th><th>Порог</th><th>Связей</th><th></th></tr></thead>
    <tbody>
    <?php if (!$blocks): ?>
        <tr><td colspan="8" style="color:#888;">Блоков пока нет — создайте первый ниже.</td></tr>
    <?php endif; ?>
    <?php foreach ($blocks as $b): ?>
        <tr<?= $selected && $selected['code'] === $b['code'] ? ' class="bx-row-selected"' : '' ?>>
            <td><b><?= $h($b['name']) ?></b><?= empty($b['active']) ? ' <span class="pill pill-off">выключен</span>' : '' ?></td>
            <td><code><?= $h($b['code']) ?></code></td>
            <td><?= $h($typeName($b['source_type'])) ?> · <?= $h($iblockName((int)$b['source_iblock_id'])) ?></td>
            <td><?= $h($typeName($b['target_type'])) ?> · <?= $h(implode(', ', array_map($iblockName, array_map('intval', $b['target_iblock_ids'] ?? [])))) ?></td>
            <td><?= (int)$b['max_links'] ?></td>
            <td><?= $h($b['min_score']) ?></td>
            <td><?= count($dataProvider->getAllLinks(['block_code' => $b['code']])) ?></td>
            <td class="actions">
                <a class="bx-btn bx-btn-sm bx-btn-primary" href="?tab=list&filter_block=<?= $h($b['code']) ?>">Связи блока →</a>
                <a class="bx-btn bx-btn-sm" href="?tab=blocks&edit=<?= $h($b['code']) ?>#form">Изменить</a>
                <form method="post" style="display:inline" onsubmit="return confirm('Удалить блок?')">
                    <input type="hidden" name="action" value="delete_block"><input type="hidden" name="code" value="<?= $h($b['code']) ?>">
                    <button class="bx-btn bx-btn-sm bx-btn-danger">Удалить</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php $e = $editBlock ?: ['code' => '', 'name' => '', 'active' => true, 'sort' => 100, 'source_type' => 'element',
    'source_iblock_id' => (int)($iblocks[0]['id'] ?? 0), 'target_type' => 'element', 'target_iblock_ids' => [],
    'max_links' => 5, 'min_score' => 0.15, 'balance' => 0.5, 'strategies' => ['keyword']]; ?>
<style>
    .blk-form { padding: 20px 24px; max-width: 860px; }
    .blk-form fieldset { border: 1px solid #e3e7ea; border-radius: 6px; margin: 0 0 16px; padding: 12px 16px 14px; }
    .blk-form legend { font-weight: 600; color: #2a5885; padding: 0 8px; }
    .blk-row { display: grid; grid-template-columns: 220px 1fr; gap: 6px 16px; align-items: start; margin-bottom: 10px; }
    .blk-row > label.blk-label { padding-top: 6px; color: #333; }
    .blk-row .blk-hint { grid-column: 2; color: #888; font-size: 12px; margin-top: -4px; }
    .blk-form input[type=text], .blk-form input[type=number], .blk-form select { padding: 6px 8px; border: 1px solid #c0c7cf; border-radius: 3px; font-size: 13px; }
    .blk-form input[type=text] { width: 100%; box-sizing: border-box; }
    .blk-form input[type=number] { width: 90px; }
    .blk-inline { display: flex; flex-wrap: wrap; gap: 8px 18px; align-items: center; padding-top: 4px; }
    .blk-inline label { white-space: nowrap; }
    .blk-actions { display: flex; gap: 8px; }
</style>
<?php $showForm = $editBlock !== null || isset($_GET['new']) || ($action === 'save_block' && $messageType === 'error'); ?>
<div id="blkFormWrap" style="<?= $showForm ? '' : 'display:none;' ?>margin-top:16px;">
<h3 id="form" style="margin:0 0 10px;"><?= $editBlock ? 'Изменить блок «' . $h($editBlock['name']) . '»' : 'Новый блок' ?></h3>
<form method="post" class="bx-table-wrap blk-form">
    <input type="hidden" name="action" value="save_block">

    <fieldset>
        <legend>1. Название</legend>
        <div class="blk-row">
            <label class="blk-label">Название блока</label>
            <input type="text" name="name" value="<?= $h($e['name']) ?>" placeholder="Например: Похожие товары" required>
        </div>
        <div class="blk-row">
            <label class="blk-label">Код (латиница)</label>
            <input type="text" name="code" value="<?= $h($e['code']) ?>" placeholder="similar_products" pattern="[A-Za-z0-9_]+" required <?= $editBlock ? 'readonly' : '' ?>>
            <div class="blk-hint">По коду блок выбирается в параметрах компонента.</div>
        </div>
    </fieldset>

    <fieldset>
        <legend>2. Откуда и куда</legend>
        <div class="blk-row">
            <label class="blk-label">Страницы, где показывается блок</label>
            <div class="blk-inline">
                <select name="source_type"><option value="element" <?= $e['source_type'] === 'element' ? 'selected' : '' ?>>элементы</option><option value="section" <?= $e['source_type'] === 'section' ? 'selected' : '' ?>>разделы</option></select>
                <span>инфоблока</span>
                <select name="source_iblock_id"><?php foreach ($iblocks as $ib): ?><option value="<?= (int)$ib['id'] ?>" <?= (int)$e['source_iblock_id'] === (int)$ib['id'] ? 'selected' : '' ?>><?= $h($ib['name']) ?></option><?php endforeach; ?></select>
            </div>
        </div>
        <div class="blk-row">
            <label class="blk-label">На что ссылаемся</label>
            <div class="blk-inline">
                <select name="target_type"><option value="element" <?= $e['target_type'] === 'element' ? 'selected' : '' ?>>элементы</option><option value="section" <?= $e['target_type'] === 'section' ? 'selected' : '' ?>>разделы</option></select>
                <span>инфоблока</span>
                <select name="target_iblock_ids[]"><?php $tIds = array_map('intval', $e['target_iblock_ids']); foreach ($iblocks as $ib): ?><option value="<?= (int)$ib['id'] ?>" <?= in_array((int)$ib['id'], $tIds, true) ? 'selected' : '' ?>><?= $h($ib['name']) ?></option><?php endforeach; ?></select>
            </div>
        </div>
    </fieldset>

    <fieldset>
        <legend>3. Сколько и насколько точно</legend>
        <div class="blk-row">
            <label class="blk-label">Сколько ссылок показывать</label>
            <input type="number" name="max_links" min="1" value="<?= (int)$e['max_links'] ?>">
            <div class="blk-hint">Столько ссылок создаётся для каждой страницы и столько же показывается на сайте. В компоненте отдельного лимита нет: нужно другое число — создайте ещё один блок.</div>
        </div>
        <div class="blk-row">
            <label class="blk-label">Порог релевантности (0–1)</label>
            <input type="number" step="0.05" min="0" max="1" name="min_score" value="<?= $h($e['min_score']) ?>">
            <div class="blk-hint">Выше значение — строже отбор, ссылок будет меньше.</div>
        </div>
        <div class="blk-row">
            <label class="blk-label">Равномерность (0–1)</label>
            <input type="number" step="0.1" min="0" max="1" name="balance" value="<?= $h($e['balance'] ?? 0.5) ?>">
            <div class="blk-hint">0 — не учитывать. Чем выше, тем сильнее приоритет у страниц, на которые ссылаются реже (при равной релевантности).</div>
        </div>
        <div class="blk-row">
            <label class="blk-label">Как искать</label>
            <div class="blk-inline">
                <label><input type="checkbox" name="strategies[]" value="keyword" <?= in_array('keyword', $e['strategies'], true) ? 'checked' : '' ?>> по ключевым словам и названию</label>
                <label><input type="checkbox" name="strategies[]" value="tfidf" <?= in_array('tfidf', $e['strategies'], true) ? 'checked' : '' ?>> по тексту (TF-IDF)</label>
            </div>
        </div>
    </fieldset>

    <fieldset>
        <legend>4. Прочее</legend>
        <div class="blk-row">
            <label class="blk-label">Блок включён</label>
            <div class="blk-inline"><label><input type="checkbox" name="active" value="1" <?= !empty($e['active']) ? 'checked' : '' ?>> да</label></div>
        </div>
        <div class="blk-row">
            <label class="blk-label">Сортировка</label>
            <input type="number" name="sort" value="<?= (int)$e['sort'] ?>">
        </div>
    </fieldset>

    <div class="blk-actions">
        <button class="bx-btn bx-btn-primary">Сохранить блок</button>
        <a class="bx-btn" href="?tab=blocks" onclick="<?= $editBlock ? '' : 'toggleBlockForm(false);return false;' ?>">Отмена</a>
    </div>
</form>
</div>
<script>
function toggleBlockForm(show) {
    var w = document.getElementById('blkFormWrap');
    w.style.display = show ? '' : 'none';
    if (show) { w.scrollIntoView({behavior: 'smooth', block: 'start'}); var i = w.querySelector('input[name=name]'); if (i) i.focus(); }
}
</script>
