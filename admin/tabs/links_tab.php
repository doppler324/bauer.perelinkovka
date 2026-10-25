<?php
/**
 * links_tab.php
 *
 * Вкладка «Список связей»: все связи, ручное добавление и массовое добавление по правилам блока.
 * Блоки создаются на вкладке «Блоки и правила». Подключается из admin_preview.php.
 */

require_once __DIR__ . '/labels.php';

use Bauer\Perelinkovka\Linker;
use Bauer\Perelinkovka\KeywordMatchingStrategy;
use Bauer\Perelinkovka\TfIdfStrategy;

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$message = null;
$messageType = 'info';

$makeLinker = function (array $block) use ($dataProvider): Linker {
    $l = new Linker($dataProvider);
    $st = [];
    $en = $block['strategies'] ?? ['keyword'];
    if (in_array('keyword', $en, true)) $st[] = new KeywordMatchingStrategy(['weight' => 1.0]);
    if (in_array('tfidf', $en, true)) $st[] = new TfIdfStrategy($dataProvider, ['weight' => 1.0]);
    if ($st) $l->setStrategies($st);
    return $l;
};

$blocks = $dataProvider->getBlocks();
$blockByCode = [];
foreach ($blocks as $b) $blockByCode[$b['code']] = $b;
$entityName = function (string $type, int $id) use ($dataProvider): string {
    $e = $type === 'section' ? $dataProvider->getSection($id) : $dataProvider->getElement($id);
    return $e['name'] ?? ('#' . $id);
};
$linkKey = fn($c, $st, $si, $tt, $ti) => $c . '|' . $st . '_' . $si . '|' . $tt . '_' . $ti;
$existingKeys = function () use ($dataProvider, $linkKey) {
    $m = [];
    foreach ($dataProvider->getAllLinks() as $l) {
        $m[$linkKey((string)($l['block_code'] ?? ''), $l['source_type'], (int)$l['source_id'], $l['target_type'], (int)$l['target_id'])] = true;
    }
    return $m;
};

$action = $_POST['action'] ?? '';
$massPreview = null;
$massBlock = $blockByCode[$_POST['mass_block'] ?? $_GET['mass_block'] ?? ''] ?? null;

// ---------- Ручное добавление ----------
if ($action === 'add_manual') {
    $block = $blockByCode[$_POST['block_code'] ?? ''] ?? null;
    [$st, $si] = array_pad(explode(':', (string)($_POST['source'] ?? '')), 2, '');
    [$tt, $ti] = array_pad(explode(':', (string)($_POST['target'] ?? '')), 2, '');
    $si = (int)$si; $ti = (int)$ti;
    $src = $st === 'section' ? $dataProvider->getSection($si) : $dataProvider->getElement($si);
    $tgt = $tt === 'section' ? $dataProvider->getSection($ti) : $dataProvider->getElement($ti);
    if (!$block || !$src || !$tgt) {
        $message = 'Выберите блок, откуда и куда.'; $messageType = 'error';
    } elseif ($st === $tt && $si === $ti) {
        $message = 'Страница не может ссылаться на саму себя.'; $messageType = 'error';
    } elseif (isset($existingKeys()[$linkKey($block['code'], $st, $si, $tt, $ti)])) {
        $message = 'Такая связь в этом блоке уже есть.'; $messageType = 'warning';
    } else {
        $ok = $dataProvider->saveLink([
            'block_code' => $block['code'], 'source_type' => $st, 'source_id' => $si, 'target_type' => $tt, 'target_id' => $ti,
            'source_iblock_id' => (int)$src['iblock_id'], 'target_iblock_id' => (int)$tgt['iblock_id'],
            'type' => 'manual', 'strategy' => 'manual', 'score' => 1.0,
            'sort' => (int)($_POST['sort'] ?? 100), 'active' => true,
        ]);
        $message = $ok ? 'Связь добавлена.' : 'Не удалось сохранить связь.'; $messageType = $ok ? 'success' : 'error';
    }
}
// ---------- Массово: предпросмотр / сохранение ----------
elseif ($action === 'mass_preview' && $massBlock) {
    $massPreview = $makeLinker($massBlock)->autoLinkBlock($massBlock, ['save' => false]);
} elseif ($action === 'mass_show' && $massBlock) {
    // результат подбора по частям (прогресс-бар), показываем как обычный предпросмотр
    $pre = json_decode((string)($_POST['links_json'] ?? ''), true);
    $massPreview = ['links' => is_array($pre) ? $pre : [], 'skipped' => (int)($_POST['skipped'] ?? 0), 'created' => 0, 'errors' => []];
} elseif ($action === 'mass_save' && $massBlock) {
    $exist = $existingKeys(); $n = 0; $skip = 0;
    foreach ((array)($_POST['pick'] ?? []) as $raw) {
        $c = json_decode((string)$raw, true);
        if (!is_array($c)) continue;
        $k = $linkKey($massBlock['code'], $c['source_type'], (int)$c['source_id'], $c['target_type'], (int)$c['target_id']);
        if (isset($exist[$k])) { $skip++; continue; }
        $exist[$k] = true;
        $dataProvider->saveLink([
            'block_code' => $massBlock['code'], 'source_type' => $c['source_type'], 'source_id' => (int)$c['source_id'],
            'target_type' => $c['target_type'], 'target_id' => (int)$c['target_id'],
            'source_iblock_id' => (int)$massBlock['source_iblock_id'], 'target_iblock_id' => (int)$c['iblock_id'],
            'type' => $c['type'], 'strategy' => $c['strategy'], 'score' => round((float)$c['weight'], 4),
            'sort' => (int)round($c['weight'] * 100), 'active' => true,
        ]);
        $n++;
    }
    $message = "Сохранено связей: {$n}, пропущено как дубли: {$skip}."; $messageType = 'success';
} elseif ($action === 'mass_all' && $massBlock) {
    $r = $makeLinker($massBlock)->autoLinkBlock($massBlock);
    $message = "Блок «{$massBlock['name']}»: создано {$r['created']}, уже были {$r['skipped']}, ошибок " . count($r['errors']) . '.';
    $messageType = $r['errors'] ? 'error' : 'success';
}
// ---------- Действия над существующими ----------
elseif ($action === 'row' || $action === 'bulk') {
    $ids = $action === 'row' ? [(int)($_POST['id'] ?? 0)] : array_map('intval', (array)($_POST['ids'] ?? []));
    $do = $action === 'row' ? ($_POST['do'] ?? '') : ($_POST['bulk_do'] ?? '');
    $done = 0; $fail = 0;
    foreach ($ids as $id) {
        $ok = match ($do) {
            'delete' => $dataProvider->deleteLink($id),
            'deactivate' => $dataProvider->updateLink($id, ['active' => false]),
            'activate' => $dataProvider->updateLink($id, ['active' => true]),
            default => false,
        };
        $ok ? $done++ : $fail++;
    }
    $message = "Готово: {$done}" . ($fail ? ", не удалось: {$fail} (эталонные связи из links.json не меняются)" : '') . '.';
    $messageType = $fail ? 'warning' : 'success';
}

// Сообщение после массового подбора с прогресс-баром (?mass_done=создано_пропущено_ошибок_остановлено)
if ($message === null && isset($_GET['mass_done']) && $massBlock) {
    [$mc, $ms, $me, $mstop] = array_map('intval', array_pad(explode('_', (string)$_GET['mass_done']), 4, '0'));
    $message = "Блок «{$massBlock['name']}»: создано {$mc}, уже были {$ms}, ошибок {$me}." . ($mstop ? ' Подбор остановлен вручную.' : '');
    $messageType = $me ? 'error' : 'success';
}

// ---------- Данные для таблицы ----------
$fBlock = $_GET['filter_block'] ?? '';
$fActive = $_GET['filter_active'] ?? '';
$fSearch = trim((string)($_GET['search'] ?? ''));
$links = $dataProvider->getAllLinks();
$links = array_values(array_filter($links, function ($l) use ($fBlock, $fActive, $fSearch, $entityName) {
    if ($fBlock !== '' && ($l['block_code'] ?? '') !== ($fBlock === '-' ? '' : $fBlock)) return false;
    if ($fActive !== '' && (int)!empty($l['active']) !== (int)$fActive) return false;
    if ($fSearch !== '' && mb_stripos($entityName($l['source_type'], (int)$l['source_id']) . ' ' . $entityName($l['target_type'], (int)$l['target_id']), $fSearch) === false) return false;
    return true;
}));
usort($links, fn($a, $b) => (int)$b['id'] <=> (int)$a['id']);
$perPage = 25;
$page = max(1, (int)($_GET['page'] ?? 1));
$pages = max(1, (int)ceil(count($links) / $perPage));
$pageLinks = array_slice($links, ($page - 1) * $perPage, $perPage);
$qs = http_build_query(['tab' => 'list', 'filter_block' => $fBlock, 'filter_active' => $fActive, 'search' => $fSearch]);

// Список страниц для ручного выбора
$opts = function (array $block = null) use ($dataProvider) {
    $out = [];
    foreach ($dataProvider->getElements(['active' => true]) as $e) $out[] = ['element:' . $e['id'], '[эл] ' . $e['name'], (int)$e['iblock_id'], 'element'];
    foreach ($dataProvider->getSections() as $s) $out[] = ['section:' . $s['id'], '[раздел] ' . $s['name'], (int)$s['iblock_id'], 'section'];
    return $out;
};
$allOpts = $opts();
?>
<h1 class="bx-page-title">Связи</h1>
<p style="color:#666;max-width:860px;">Здесь все связи между страницами. Можно добавить связь <b>вручную</b> или <b>массово</b> — по правилам блока
(блоки создаются на вкладке «Блоки и правила»). Ручные связи показываются в блоке выше автоматических.</p>

<?php if ($message): ?><div class="bx-notification bx-notification-<?= $h($messageType) ?>"><?= $h($message) ?></div><?php endif; ?>

<style>
    .lnk-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; max-width: 1100px; margin-bottom: 20px; }
    @media (max-width: 900px) { .lnk-grid { grid-template-columns: 1fr; } }
    .lnk-card { background: #fff; border: 1px solid #dce1e6; border-radius: 6px; padding: 16px 18px; }
    .lnk-card h3 { margin: 0 0 4px; font-size: 15px; }
    .lnk-card .sub { color: #888; font-size: 12px; margin-bottom: 12px; }
    .lnk-row { display: grid; grid-template-columns: 110px 1fr; gap: 8px 12px; align-items: center; margin-bottom: 8px; }
    .lnk-row select, .lnk-row input[type=number] { padding: 6px 8px; border: 1px solid #c0c7cf; border-radius: 3px; width: 100%; box-sizing: border-box; }
    .lnk-actions { display: flex; gap: 8px; margin-top: 10px; flex-wrap: wrap; }
</style>

<div class="lnk-grid">
    <div class="lnk-card">
        <h3>✍️ Добавить вручную</h3>
        <div class="sub">Одна связь между двумя страницами в выбранном блоке.</div>
        <form method="post">
            <input type="hidden" name="action" value="add_manual">
            <div class="lnk-row"><label>Блок</label>
                <select name="block_code" required><option value="">— выберите —</option>
                    <?php foreach ($blocks as $b): ?><option value="<?= $h($b['code']) ?>"><?= $h($b['name']) ?></option><?php endforeach; ?></select></div>
            <div class="lnk-row"><label>Откуда</label>
                <select name="source" required><option value="">— страница —</option>
                    <?php foreach ($allOpts as $o): ?><option value="<?= $h($o[0]) ?>"><?= $h($o[1]) ?></option><?php endforeach; ?></select></div>
            <div class="lnk-row"><label>Куда</label>
                <select name="target" required><option value="">— страница —</option>
                    <?php foreach ($allOpts as $o): ?><option value="<?= $h($o[0]) ?>"><?= $h($o[1]) ?></option><?php endforeach; ?></select></div>
            <div class="lnk-row"><label>Порядок</label><input type="number" name="sort" value="100"></div>
            <div class="lnk-actions"><button class="bx-btn bx-btn-primary">Добавить связь</button></div>
        </form>
    </div>

    <div class="lnk-card">
        <h3>⚡ Добавить массово по блоку</h3>
        <div class="sub">Система подберёт связи по правилам блока для всех его страниц. Можно сначала посмотреть и выбрать нужные.</div>
        <form method="post" id="massForm">
            <div class="lnk-row"><label>Блок</label>
                <select name="mass_block" required><option value="">— выберите —</option>
                    <?php foreach ($blocks as $b): ?><option value="<?= $h($b['code']) ?>" <?= $massBlock && $massBlock['code'] === $b['code'] ? 'selected' : '' ?>><?= $h($b['name']) ?></option><?php endforeach; ?></select></div>
            <div class="lnk-actions">
                <button name="action" value="mass_preview" class="bx-btn bx-btn-primary">Подобрать и показать</button>
                <button name="action" value="mass_all" class="bx-btn bx-btn-success" onclick="return confirm('Подобрать и сразу сохранить все связи блока?')">Подобрать и сохранить всё</button>
            </div>
        </form>
    </div>
</div>

<?php if ($massPreview !== null): ?>
    <h3>Найдено новых связей: <?= count($massPreview['links']) ?> (уже существуют: <?= (int)$massPreview['skipped'] ?>)</h3>
    <?php if ($massPreview['links']): ?>
    <form method="post">
        <input type="hidden" name="action" value="mass_save"><input type="hidden" name="mass_block" value="<?= $h($massBlock['code']) ?>">
        <div class="bx-table-wrap"><table class="bx-table">
            <thead><tr><th><input type="checkbox" checked onclick="document.querySelectorAll('.mp').forEach(c=>c.checked=this.checked)"></th><th>Откуда</th><th>Куда</th><th>Оценка</th><th>Тип</th><th>Как найдено</th></tr></thead>
            <tbody>
            <?php foreach ($massPreview['links'] as $c): ?>
                <tr><td><input class="mp" type="checkbox" checked name="pick[]" value="<?= $h(json_encode($c, JSON_UNESCAPED_UNICODE)) ?>"></td>
                    <td><?= $h($entityName($c['source_type'], (int)$c['source_id'])) ?></td>
                    <td><?= $h($c['target_name']) ?></td><td><b><?= number_format($c['weight'], 2) ?></b></td>
                    <td><span class="pill pill-<?= $h(type_css($c['type'])) ?>"><?= $h(ru_type($c['type'])) ?></span></td><td><?= $h(ru_strategy($c['strategy'])) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <div class="lnk-actions" style="margin-bottom:20px;"><button class="bx-btn bx-btn-success">Сохранить выбранные</button></div>
    </form>
    <?php endif; ?>
<?php endif; ?>

<h2>Все связи (<?= count($links) ?>)</h2>
<form method="get" style="margin-bottom:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <input type="hidden" name="tab" value="list">
    <select name="filter_block" onchange="this.form.submit()">
        <option value="">Все блоки</option>
        <?php foreach ($blocks as $b): ?><option value="<?= $h($b['code']) ?>" <?= $fBlock === $b['code'] ? 'selected' : '' ?>><?= $h($b['name']) ?></option><?php endforeach; ?>
        <option value="-" <?= $fBlock === '-' ? 'selected' : '' ?>>Без блока (старые)</option>
    </select>
    <select name="filter_active" onchange="this.form.submit()">
        <option value="">Любая активность</option><option value="1" <?= $fActive === '1' ? 'selected' : '' ?>>Активные</option><option value="0" <?= $fActive === '0' ? 'selected' : '' ?>>Выключенные</option>
    </select>
    <input type="text" name="search" value="<?= $h($fSearch) ?>" placeholder="Поиск по названию" style="padding:6px 8px;border:1px solid #c0c7cf;border-radius:3px;">
    <button class="bx-btn">Найти</button> <a class="bx-btn" href="?tab=list">Сбросить</a>
</form>

<form method="post">
    <input type="hidden" name="action" value="bulk">
    <div class="bx-table-wrap">
    <div class="bx-table-toolbar"><div class="left">
        С выбранными: <select name="bulk_do"><option value="deactivate">Выключить</option><option value="activate">Включить</option><option value="delete">Удалить</option></select>
        <button class="bx-btn bx-btn-sm" onclick="return confirm('Применить к выбранным связям?')">Применить</button></div></div>
    <table class="bx-table">
        <thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.lc').forEach(c=>c.checked=this.checked)"></th>
            <th>ID</th><th>Блок</th><th>Откуда</th><th>Куда</th><th>Тип</th><th>Как найдена</th><th>Оценка</th><th>Порядок</th><th>Статус</th><th></th></tr></thead>
        <tbody>
        <?php if (!$pageLinks): ?><tr><td colspan="11" style="color:#888;">Связей нет.</td></tr><?php endif; ?>
        <?php foreach ($pageLinks as $l): $bc = $l['block_code'] ?? ''; ?>
            <tr>
                <td><input class="lc" type="checkbox" name="ids[]" value="<?= (int)$l['id'] ?>"></td>
                <td style="color:#888;"><?= (int)$l['id'] ?></td>
                <td><?= $bc !== '' ? $h($blockByCode[$bc]['name'] ?? $bc) : '<span style="color:#aaa">—</span>' ?></td>
                <td><?= $h($entityName($l['source_type'], (int)$l['source_id'])) ?></td>
                <td><?= $h($entityName($l['target_type'], (int)$l['target_id'])) ?></td>
                <td><span class="pill pill-<?= $h(type_css($l['type'] ?? '')) ?>"><?= $h(ru_type($l['type'] ?? '')) ?></span></td>
                <td><?= $h(ru_strategy($l['strategy'] ?? 'auto')) ?></td>
                <td><?= number_format((float)($l['score'] ?? 0), 2) ?></td>
                <td><?= (int)($l['sort'] ?? 0) ?></td>
                <td><span class="pill <?= !empty($l['active']) ? 'pill-on' : 'pill-off' ?>"><?= !empty($l['active']) ? 'включена' : 'выключена' ?></span></td>
                <td class="actions">
                    <button name="action" value="row" class="bx-btn bx-btn-sm" formaction="?<?= $h($qs) ?>" onclick="setRow(this, <?= (int)$l['id'] ?>, '<?= !empty($l['active']) ? 'deactivate' : 'activate' ?>')"><?= !empty($l['active']) ? 'Выключить' : 'Включить' ?></button>
                    <button name="action" value="row" class="bx-btn bx-btn-sm bx-btn-danger" formaction="?<?= $h($qs) ?>" onclick="if(!confirm('Удалить связь?'))return false;setRow(this, <?= (int)$l['id'] ?>, 'delete')">Удалить</button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <input type="hidden" name="id" id="rowId"><input type="hidden" name="do" id="rowDo">
</form>
<script>
function setRow(btn, id, what) { document.getElementById('rowId').value = id; document.getElementById('rowDo').value = what; }
</script>
<?php if ($pages > 1): ?>
    <div style="margin-top:10px;">Страницы:
    <?php for ($i = 1; $i <= $pages; $i++): ?>
        <a class="bx-btn bx-btn-sm<?= $i === $page ? ' bx-btn-primary' : '' ?>" href="?<?= $h($qs) ?>&page=<?= $i ?>"><?= $i ?></a>
    <?php endfor; ?></div>
<?php endif; ?>
