<?php
/**
 * stats_tab.php
 *
 * Вкладка «Статистика»: сколько ссылок получает и ставит каждая страница.
 * Подключается из admin_preview.php (использует $dataProvider, $iblocks).
 */

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$blocks = $dataProvider->getBlocks();
$blockByCode = [];
foreach ($blocks as $b) $blockByCode[$b['code']] = $b;

$fBlock = (string)($_GET['filter_block'] ?? '');
$fShow = (string)($_GET['show'] ?? 'all');          // all | zero | top
$sort = (string)($_GET['sort'] ?? 'in_asc');        // in_asc | in_desc | name
$fType = (string)($_GET['filter_type'] ?? '');       // '' | element | section

$iblockName = function (int $id) use ($iblocks): string {
    foreach ($iblocks as $ib) if ((int)$ib['id'] === $id) return $ib['name'];
    return '#' . $id;
};

// Активные связи (с учётом блока)
$links = array_filter($dataProvider->getAllLinks(), function ($l) use ($fBlock) {
    if (empty($l['active'])) return false;
    if ($fBlock === '') return true;
    return ($l['block_code'] ?? '') === ($fBlock === '-' ? '' : $fBlock);
});

// Считаем входящие и исходящие
$in = []; $out = []; $inByBlock = [];
foreach ($links as $l) {
    $tk = $l['target_type'] . '_' . (int)$l['target_id'];
    $sk = $l['source_type'] . '_' . (int)$l['source_id'];
    $in[$tk] = ($in[$tk] ?? 0) + 1;
    $out[$sk] = ($out[$sk] ?? 0) + 1;
    $bc = (string)($l['block_code'] ?? '');
    $inByBlock[$tk][$bc] = ($inByBlock[$tk][$bc] ?? 0) + 1;
}

// Какие страницы считаем «целями»: цели выбранного блока, иначе все активные
$pages = [];
$addPage = function (string $type, array $e) use (&$pages, $in, $out) {
    $k = $type . '_' . (int)$e['id'];
    $pages[$k] = ['key' => $k, 'type' => $type, 'id' => (int)$e['id'], 'name' => $e['name'], 'iblock' => (int)$e['iblock_id'],
        'in' => $in[$k] ?? 0, 'out' => $out[$k] ?? 0];
};
$block = $blockByCode[$fBlock] ?? null;
$tTypes = $block ? [$block['target_type'] ?? 'element'] : ['element', 'section'];
$tIblocks = $block ? array_map('intval', $block['target_iblock_ids'] ?? []) : [];
if (in_array('element', $tTypes, true)) {
    foreach ($dataProvider->getElements(['active' => true]) as $e) {
        if (!$tIblocks || in_array((int)$e['iblock_id'], $tIblocks, true)) $addPage('element', $e);
    }
}
if (in_array('section', $tTypes, true)) {
    foreach ($dataProvider->getSections() as $s) {
        if (!$tIblocks || in_array((int)$s['iblock_id'], $tIblocks, true)) $addPage('section', $s);
    }
}
if ($fType !== '') $pages = array_filter($pages, fn($p) => $p['type'] === $fType);

// Сводка
$counts = array_column($pages, 'in');
$n = count($counts);
$total = array_sum($counts);
$zero = count(array_filter($counts, fn($c) => $c === 0));
$max = $n ? max($counts) : 0;
$min = $n ? min($counts) : 0;
$avg = $n ? $total / $n : 0;
// Коэффициент Джини: 0 — ссылки распределены поровну, 1 — все ссылки на одной странице
$gini = 0.0;
if ($n > 1 && $total > 0) {
    sort($counts);
    $cum = 0;
    foreach ($counts as $i => $c) $cum += ($i + 1) * $c;
    $gini = (2 * $cum) / ($n * $total) - ($n + 1) / $n;
}
$fairness = max(0, min(100, (int)round((1 - $gini) * 100)));

// Фильтр показа и сортировка
if ($fShow === 'zero') $pages = array_filter($pages, fn($p) => $p['in'] === 0);
$pages = array_values($pages);
usort($pages, function ($a, $b) use ($sort) {
    return match ($sort) {
        'in_desc' => [$b['in'], $a['name']] <=> [$a['in'], $b['name']],
        'name' => strcmp($a['name'], $b['name']),
        default => [$a['in'], $a['name']] <=> [$b['in'], $b['name']],
    };
});
if ($fShow === 'top') $pages = array_slice(array_reverse($pages), 0, 10) ?: [];
$perPage = 30;
$page = max(1, (int)($_GET['page'] ?? 1));
$pagesTotal = max(1, (int)ceil(count($pages) / $perPage));
$view = array_slice($pages, ($page - 1) * $perPage, $perPage);
$qs = http_build_query(['tab' => 'stats', 'filter_block' => $fBlock, 'show' => $fShow, 'sort' => $sort, 'filter_type' => $fType]);

// Распределение: сколько страниц получили N ссылок
$hist = [];
foreach (array_column(array_values($pages ?: []), 'in') as $c) {}
$all = []; foreach ($counts as $c) $all[$c] = ($all[$c] ?? 0) + 1; ksort($all);
$histMax = $all ? max($all) : 1;
?>
<h1 class="bx-page-title">Статистика ссылок</h1>
<p style="color:#666;max-width:860px;">Сколько ссылок получает каждая страница. Так видно, какие страницы «забирают» все ссылки, а какие остаются без них.
Считаются только включённые связи.</p>

<form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:12px 0 18px;">
    <input type="hidden" name="tab" value="stats">
    <select name="filter_block" onchange="this.form.submit()">
        <option value="">Все блоки</option>
        <?php foreach ($blocks as $b): ?><option value="<?= $h($b['code']) ?>" <?= $fBlock === $b['code'] ? 'selected' : '' ?>><?= $h($b['name']) ?></option><?php endforeach; ?>
        <option value="-" <?= $fBlock === '-' ? 'selected' : '' ?>>Без блока (старые)</option>
    </select>
    <select name="filter_type" onchange="this.form.submit()">
        <option value="">Элементы и разделы</option>
        <option value="element" <?= $fType === 'element' ? 'selected' : '' ?>>Только элементы</option>
        <option value="section" <?= $fType === 'section' ? 'selected' : '' ?>>Только разделы</option>
    </select>
    <select name="show" onchange="this.form.submit()">
        <option value="all" <?= $fShow === 'all' ? 'selected' : '' ?>>Все страницы</option>
        <option value="zero" <?= $fShow === 'zero' ? 'selected' : '' ?>>Без входящих ссылок</option>
        <option value="top" <?= $fShow === 'top' ? 'selected' : '' ?>>Топ-10 по ссылкам</option>
    </select>
    <select name="sort" onchange="this.form.submit()">
        <option value="in_asc" <?= $sort === 'in_asc' ? 'selected' : '' ?>>Меньше ссылок сверху</option>
        <option value="in_desc" <?= $sort === 'in_desc' ? 'selected' : '' ?>>Больше ссылок сверху</option>
        <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>По названию</option>
    </select>
</form>

<style>
    .st-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 14px; margin-bottom: 20px; max-width: 1100px; }
    .st-card { background: #fff; border: 1px solid #e5e9f0; border-radius: 12px; padding: 14px 16px; box-shadow: 0 1px 2px rgba(16,24,40,.05); }
    .st-card .v { font-size: 26px; font-weight: 700; }
    .st-card .l { color: #6b7280; font-size: 12px; margin-top: 2px; }
    .st-bar { background: #eef2ff; border-radius: 999px; height: 10px; width: 100%; min-width: 120px; overflow: hidden; }
    .st-bar > i { display: block; height: 100%; background: #4f46e5; border-radius: 999px; }
    .st-bar.zero > i { background: #dc2626; }
    .st-hist { display: flex; align-items: flex-end; gap: 6px; height: 90px; margin: 6px 0 4px; }
    .st-hist div { flex: 1; background: #c7d2fe; border-radius: 6px 6px 0 0; position: relative; min-width: 18px; }
    .st-hist span { position: absolute; top: -16px; left: 0; right: 0; text-align: center; font-size: 11px; color: #6b7280; }
    .st-hist-x { display: flex; gap: 6px; font-size: 11px; color: #6b7280; }
    .st-hist-x div { flex: 1; text-align: center; min-width: 18px; }
</style>

<div class="st-cards">
    <div class="st-card"><div class="v"><?= (int)$total ?></div><div class="l">всего входящих ссылок</div></div>
    <div class="st-card"><div class="v"><?= $n ?></div><div class="l">страниц в выборке</div></div>
    <div class="st-card"><div class="v" style="color:<?= $zero ? '#dc2626' : '#16a34a' ?>"><?= $zero ?></div><div class="l">без единой ссылки</div></div>
    <div class="st-card"><div class="v"><?= $min ?> / <?= number_format($avg, 1) ?> / <?= $max ?></div><div class="l">мин / среднее / макс на страницу</div></div>
    <div class="st-card"><div class="v"><?= $fairness ?>%</div><div class="l">равномерность распределения (100% — поровну)</div></div>
</div>

<?php if ($all): ?>
<div class="bx-table-wrap" style="padding:14px 18px;max-width:1100px;margin-bottom:20px;">
    <b>Сколько страниц получили столько ссылок</b>
    <div class="st-hist" style="margin-top:22px;">
        <?php foreach ($all as $cnt => $pagesCnt): ?>
            <div style="height:<?= max(4, (int)round($pagesCnt / $histMax * 80)) ?>px;<?= $cnt === 0 ? 'background:#fecaca;' : '' ?>"><span><?= $pagesCnt ?></span></div>
        <?php endforeach; ?>
    </div>
    <div class="st-hist-x"><?php foreach ($all as $cnt => $_): ?><div><?= $cnt ?></div><?php endforeach; ?></div>
    <div style="color:#6b7280;font-size:12px;margin-top:4px;">По горизонтали — число входящих ссылок, над столбцом — число страниц.</div>
</div>
<?php endif; ?>

<div class="bx-table-wrap">
<table class="bx-table">
    <thead><tr><th>Страница</th><th>Инфоблок</th><th style="width:30%">Входящие</th><th>Исходящие</th><th>По блокам</th></tr></thead>
    <tbody>
    <?php if (!$view): ?><tr><td colspan="5" style="color:#888;">Нет данных. Добавьте связи на вкладке «Список связей».</td></tr><?php endif; ?>
    <?php foreach ($view as $p): ?>
        <tr>
            <td><span class="pill"><?= $p['type'] === 'section' ? 'раздел' : 'элемент' ?></span> <?= $h($p['name']) ?></td>
            <td><?= $h($iblockName($p['iblock'])) ?></td>
            <td>
                <div style="display:flex;align-items:center;gap:10px;">
                    <b style="min-width:24px;"><?= $p['in'] ?></b>
                    <div class="st-bar<?= $p['in'] === 0 ? ' zero' : '' ?>"><i style="width:<?= $max ? (int)round($p['in'] / $max * 100) : 0 ?>%"></i></div>
                </div>
            </td>
            <td><?= $p['out'] ?></td>
            <td style="color:#6b7280;font-size:12px;">
                <?php foreach ($inByBlock[$p['key']] ?? [] as $bc => $cnt): ?>
                    <span class="pill"><?= $h($bc !== '' ? ($blockByCode[$bc]['name'] ?? $bc) : 'без блока') ?>: <?= $cnt ?></span>
                <?php endforeach; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php if ($pagesTotal > 1): ?>
    <div style="margin-top:10px;">Страницы:
    <?php for ($i = 1; $i <= $pagesTotal; $i++): ?>
        <a class="bx-btn bx-btn-sm<?= $i === $page ? ' bx-btn-primary' : '' ?>" href="?<?= $h($qs) ?>&page=<?= $i ?>"><?= $i ?></a>
    <?php endfor; ?></div>
<?php endif; ?>
