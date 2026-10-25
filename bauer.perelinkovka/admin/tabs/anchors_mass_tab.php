<?php
/**
 * anchors_mass_tab.php — вкладка «Массовая расстановка» (подключается из admin_preview.php по ?tab=anchors_mass).
 * Подбирает ссылки внутри текстов сразу для статьи, раздела или всех статей инфоблока.
 * Цели с меньшим числом входящих ссылок идут первыми, поэтому ссылки распределяются равномернее.
 * Ожидает переменные: $dataProvider, $dataDir.
 */

use Bauer\Perelinkovka\MassAnchors;

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$iblocksAll = $dataProvider->getIBlocks();
$sectionsAll = $dataProvider->getSections();

// Статьи — элементы с полем text
$allArticles = array_values(array_filter($dataProvider->getElements(), fn($e) => !empty($e['text'])));
$articleIblockIds = array_values(array_unique(array_map(fn($e) => (int)$e['iblock_id'], $allArticles)));
$articleIblocks = array_values(array_filter($iblocksAll, fn($ib) => in_array((int)$ib['id'], $articleIblockIds, true)));
$iblockId = (int)($_REQUEST['m_iblock'] ?? 0);
if (!in_array($iblockId, $articleIblockIds, true)) {
    $iblockId = $articleIblockIds[0] ?? 0;
}
$articles = array_values(array_filter($allArticles, fn($e) => (int)$e['iblock_id'] === $iblockId));
$articleById = [];
foreach ($articles as $a) {
    $articleById[(int)$a['id']] = $a;
}

// Разделы: полный путь и потомки
$secById = [];
foreach ($sectionsAll as $sec) {
    $secById[(int)$sec['id']] = $sec;
}
$secPath = function (int $id) use ($secById): string {
    $parts = [];
    for ($i = 0; $id && isset($secById[$id]) && $i < 10; $i++) {
        array_unshift($parts, $secById[$id]['name']);
        $id = (int)($secById[$id]['parent_id'] ?? 0);
    }
    return implode(' › ', $parts);
};
$descendants = function (int $rootId) use ($secById): array {
    $ids = [$rootId];
    for ($grew = true; $grew;) {
        $grew = false;
        foreach ($secById as $sid => $sec) {
            if (!in_array($sid, $ids, true) && in_array((int)($sec['parent_id'] ?? 0), $ids, true)) {
                $ids[] = $sid;
                $grew = true;
            }
        }
    }
    return $ids;
};
$secCount = [];
foreach ($articles as $a) {
    $sid = (int)($a['section_id'] ?? 0);
    $secCount[$sid] = ($secCount[$sid] ?? 0) + 1;
}
$secOptions = [];
foreach ($secCount as $sid => $cnt) {
    $secOptions[$sid] = $sid ? $secPath($sid) : 'Без раздела';
}
asort($secOptions, SORT_NATURAL | SORT_FLAG_CASE);

// Параметры запуска
$scope = in_array($_POST['scope'] ?? 'all', ['article', 'section', 'all'], true) ? ($_POST['scope'] ?? 'all') : 'all';
$scopeArticle = (int)($_POST['scope_article'] ?? 0);
$scopeSection = (int)($_POST['scope_section'] ?? 0);
$withChildren = $_SERVER['REQUEST_METHOD'] !== 'POST' || isset($_POST['with_children']);
$pool = ($_POST['pool'] ?? 'articles') === 'all' ? 'all' : 'articles';
$maxLinks = max(0, (int)($_POST['max_links'] ?? 3));
$onePerBlock = $_SERVER['REQUEST_METHOD'] !== 'POST' ? true : (($_POST['one_per_block'] ?? '0') === '1');
$maxInbound = max(0, (int)($_POST['max_inbound'] ?? 0));
$lowThreshold = max(0, (int)($_POST['low_threshold'] ?? 1));
$onlyLow = isset($_POST['only_low']);
$balance = $_SERVER['REQUEST_METHOD'] !== 'POST' || isset($_POST['balance']);

$mass = new MassAnchors($dataProvider, $iblockId, $pool, $onePerBlock);
$inbound = $mass->inbound();
$inScopeIds = $mass->scopeIds($scope, $scope === 'section' ? $scopeSection : $scopeArticle, $withChildren);

$message = '';
$plan = [];       // [articleId => matches[]]
$mode = $_POST['mass_action'] ?? '';
$planOptions = ['max_links' => $maxLinks, 'max_inbound' => $maxInbound, 'only_low' => $onlyLow, 'low_threshold' => $lowThreshold, 'balance' => $balance];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === 'plan') {
    // запасной путь без JavaScript: считаем сразу всё; с прогресс-баром подбор идёт порциями (progress.js)
    $plan = $mass->planChunk($inScopeIds, $inbound, $planOptions)['plan'];
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === 'plan_show') {
    // результат подбора по частям: проверяем и показываем
    $posted = json_decode((string)($_POST['plan_json'] ?? ''), true);
    foreach (is_array($posted) ? $posted : [] as $aid => $matches) {
        if ($mass->article((int)$aid) && is_array($matches)) {
            $plan[(int)$aid] = $matches;
        }
    }
    $inScopeIds = array_fill(0, max(0, (int)($_POST['scope_total'] ?? count($plan))), 0);
    $mode = 'plan';
}
if ($mode === 'plan' && !$plan) {
    $message = 'Подходящих мест не найдено: попробуйте расширить область или убрать ограничения.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mass_action'] ?? '') === 'apply') {
    // запасной путь без JavaScript (с прогресс-баром вставка идёт порциями)
    $byArticle = [];
    foreach ((array)($_POST['pairs'] ?? []) as $pair) {
        [$aid, $tk] = array_pad(explode('|', (string)$pair, 2), 2, '');
        if ($tk !== '') {
            $byArticle[(int)$aid][] = $tk;
        }
    }
    $res = $mass->applyChunk($byArticle);
    $mode = 'apply';
    $message = 'Вставлено ссылок: ' . $res['applied'] . ' в статьях: ' . $res['articles'];
    $inbound = $mass->inbound();
} elseif (isset($_GET['done'])) {
    [$dApplied, $dArticles] = array_map('intval', array_pad(explode('_', (string)$_GET['done']), 2, '0'));
    $mode = 'apply';
    $message = 'Вставлено ссылок: ' . $dApplied . ' в статьях: ' . $dArticles;
}

// Обзор: кто получает мало ссылок (по статьям области запуска как по целям)
$rows = [];
foreach ($articles as $a) {
    $rows[] = ['id' => (int)$a['id'], 'name' => $a['name'], 'sec' => (int)($a['section_id'] ?? 0), 'section' => $secOptions[(int)($a['section_id'] ?? 0)] ?? 'Без раздела',
        'in' => $inbound['element:' . (int)$a['id']] ?? 0];
}
usort($rows, fn($x, $y) => [$x['in'], $x['id']] <=> [$y['in'], $y['id']]);
$cnts = array_column($rows, 'in');
$zero = count(array_filter($cnts, fn($c) => $c === 0));
$low = count(array_filter($cnts, fn($c) => $c <= $lowThreshold));
$avg = $cnts ? array_sum($cnts) / count($cnts) : 0;
?>
<h1 class="bx-page-title">Массовая расстановка ссылок в текстах</h1>

<?php if ($message): ?>
    <div class="bx-notification bx-notification-<?= $mode === 'apply' ? 'success' : 'warning' ?>"><?= $mode === 'apply' ? '✅ ' : '' ?><?= $h($message) ?></div>
<?php endif; ?>

<?php if (!$articles): ?>
    <div class="bx-notification bx-notification-warning">Нет статей с полем text в elements.json.</div>
<?php else: ?>

<div class="st-cards">
    <div class="st-card"><div class="v"><?= count($articles) ?></div><div class="l">статей в инфоблоке</div></div>
    <div class="st-card"><div class="v"><?= $zero ?></div><div class="l">без входящих ссылок</div></div>
    <div class="st-card"><div class="v"><?= $low ?></div><div class="l">получают не больше <?= (int)$lowThreshold ?></div></div>
    <div class="st-card"><div class="v"><?= number_format($avg, 1, ',', '') ?></div><div class="l">в среднем на статью</div></div>
</div>

<form method="post" id="anchorsPlanForm" class="bx-settings-section mass-form" action="?tab=anchors_mass&m_iblock=<?= $iblockId ?>">
    <input type="hidden" name="mass_action" value="plan">
    <h3>1. Что обработать</h3>
    <div class="mass-row">
        <label>Инфоблок статей
            <select name="m_iblock" onchange="bpNav('?tab=anchors_mass&m_iblock='+this.value)">
                <?php foreach ($articleIblocks as $ib): ?>
                    <option value="<?= (int)$ib['id'] ?>" <?= (int)$ib['id'] === $iblockId ? 'selected' : '' ?>><?= $h($ib['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>
    <div class="mass-scope">
        <label class="mass-opt"><input type="radio" name="scope" value="all" <?= $scope === 'all' ? 'checked' : '' ?>> <b>Все статьи инфоблока</b> <span class="mass-muted">(<?= count($articles) ?>), связать между собой</span></label>
        <label class="mass-opt"><input type="radio" name="scope" value="section" <?= $scope === 'section' ? 'checked' : '' ?>> <b>Раздел</b>
            <select name="scope_section" onfocus="this.form.scope.value='section'">
                <?php foreach ($secOptions as $sid => $name): ?>
                    <option value="<?= (int)$sid ?>" <?= $scopeSection === (int)$sid ? 'selected' : '' ?>><?= $h($name) ?> (<?= (int)$secCount[$sid] ?>)</option>
                <?php endforeach; ?>
            </select>
            <label class="mass-inline"><input type="checkbox" name="with_children" <?= $withChildren ? 'checked' : '' ?>> с подразделами</label>
        </label>
        <label class="mass-opt"><input type="radio" name="scope" value="article" <?= $scope === 'article' ? 'checked' : '' ?>> <b>Одна статья</b>
            <select name="scope_article" onfocus="this.form.scope.value='article'">
                <?php foreach ($articles as $a): ?>
                    <option value="<?= (int)$a['id'] ?>" <?= $scopeArticle === (int)$a['id'] ? 'selected' : '' ?>>#<?= (int)$a['id'] ?> <?= $h($a['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>

    <h3>2. На что ссылаться и сколько</h3>
    <div class="mass-row">
        <label>Цели ссылок
            <select name="pool">
                <option value="articles" <?= $pool === 'articles' ? 'selected' : '' ?>>только статьи этого инфоблока</option>
                <option value="all" <?= $pool === 'all' ? 'selected' : '' ?>>статьи, товары и разделы</option>
            </select>
        </label>
        <label>Ссылок в одной статье, не больше <input type="number" name="max_links" min="0" max="100" value="<?= (int)$maxLinks ?>"> <span class="mass-muted">0 — без ограничения</span></label>
        <label>Входящих на одну цель, не больше <input type="number" name="max_inbound" min="0" value="<?= (int)$maxInbound ?>"> <span class="mass-muted">0 — без ограничения</span></label>
    </div>
    <div class="mass-row">
        <label class="mass-inline"><input type="hidden" name="one_per_block" value="0"><input type="checkbox" name="one_per_block" value="1" <?= $onePerBlock ? 'checked' : '' ?>> Не больше одной ссылки на абзац</label>
        <label class="mass-inline"><input type="checkbox" name="balance" <?= $balance ? 'checked' : '' ?>> Сначала цели, на которые ссылок меньше</label>
        <label class="mass-inline"><input type="checkbox" name="only_low" <?= $onlyLow ? 'checked' : '' ?>> Ссылаться только на обделённые: не больше <input type="number" name="low_threshold" min="0" value="<?= (int)$lowThreshold ?>"> входящих</label>
    </div>
    <div class="mass-actions"><button class="bx-btn bx-btn-primary" type="submit">🔍 Подобрать ссылки</button>
        <span class="mass-muted">Ничего не меняется, пока вы не подтвердите вставку.</span></div>
</form>

<?php if ($mode === 'plan' && $plan):
    $planSections = [];
    $planSources = [];
    foreach ($plan as $aid => $matches) {
        $sid = (int)($articleById[$aid]['section_id'] ?? 0);
        $planSections[$sid] = $secOptions[$sid] ?? 'Без раздела';
        foreach ($matches as $m) {
            $planSources[$m['source']] = $m['source'];
        }
    }
    asort($planSections, SORT_NATURAL | SORT_FLAG_CASE);
    sort($planSources);
?>
<form method="post" id="anchorsApplyForm" action="?tab=anchors_mass&m_iblock=<?= $iblockId ?>">
    <input type="hidden" name="mass_action" value="apply">
    <input type="hidden" name="m_iblock" value="<?= $iblockId ?>">
    <input type="hidden" name="one_per_block" value="<?= $onePerBlock ? 1 : 0 ?>">
    <input type="hidden" name="pool" value="<?= $h($pool) ?>">
    <div class="bx-table-wrap" style="margin-bottom:16px;">
        <div class="bx-table-toolbar">
            <div class="left"><b>Найдено: <?= array_sum(array_map('count', $plan)) ?> ссылок в <?= count($plan) ?> статьях из <?= count($inScopeIds) ?></b></div>
            <div class="right"><button type="submit" class="bx-btn bx-btn-sm bx-btn-success">✔ Вставить выбранные</button></div>
        </div>
        <div class="tk-filters">
            <input type="search" id="planSearch" placeholder="Поиск: статья, цель, анкор, текст…">
            <select id="planSection"><option value="">Все разделы статей</option>
                <?php foreach ($planSections as $sid => $nm): ?><option value="<?= (int)$sid ?>"><?= $h($nm) ?></option><?php endforeach; ?></select>
            <select id="planSrc"><option value="">Любой источник фразы</option>
                <?php foreach ($planSources as $sn): ?><option value="<?= $h($sn) ?>"><?= $h($sn) ?></option><?php endforeach; ?></select>
            <select id="planTt"><option value="">Любые цели</option><option value="element">только элементы</option><option value="section">только разделы</option></select>
            <span class="mass-muted" id="planSel"></span>
        </div>
        <table class="bx-table" id="planTable">
            <thead><tr><th style="width:30px;"><input type="checkbox" id="massAll" checked title="Выбрать все найденные (на всех страницах)"></th><th>Статья</th><th>Цель</th><th>Анкор</th><th>Источник фразы</th><th>Контекст</th></tr></thead>
            <tbody>
            <?php foreach ($plan as $aid => $matches): foreach ($matches as $m):
                $aSec = (int)($articleById[$aid]['section_id'] ?? 0); ?>
                <tr data-s="<?= $h(mb_strtolower('#' . $aid . ' ' . ($articleById[$aid]['name'] ?? '') . ' ' . $m['target_name'] . ' ' . $m['anchor'] . ' ' . $m['context']['left'] . $m['context']['match'] . $m['context']['right'])) ?>"
                    data-section="<?= $aSec ?>" data-src="<?= $h($m['source']) ?>" data-tt="<?= $h($m['target_type']) ?>">
                    <td><input type="checkbox" name="pairs[]" value="<?= (int)$aid ?>|<?= $h($m['target_type'] . ':' . $m['target_id']) ?>" checked></td>
                    <td><b>#<?= (int)$aid ?></b> <?= $h($articleById[$aid]['name'] ?? '') ?></td>
                    <td>
                        <span class="entity-type type-<?= $m['target_type'] === 'element' ? 'elem' : 'sect' ?>"><?= $m['target_type'] === 'element' ? 'ЭЛ' : 'РЗ' ?></span>
                        #<?= (int)$m['target_id'] ?> <?= $h($m['target_name']) ?>
                        <span class="mass-muted">(сейчас <?= (int)($inbound[$m['target_type'] . ':' . $m['target_id']] ?? 0) ?>)</span>
                    </td>
                    <td><b><?= $h($m['anchor']) ?></b></td>
                    <td><span class="badge badge-related"><?= $h($m['source']) ?></span></td>
                    <td style="color:#666;">…<?= $h($m['context']['left']) ?><mark><?= $h($m['context']['match']) ?></mark><?= $h($m['context']['right']) ?>…</td>
                </tr>
            <?php endforeach; endforeach; ?>
            </tbody>
        </table>
        <div class="tk-foot"><span class="mass-muted" id="planInfo"></span><span class="tk-pager" id="planPager"></span>
            <label class="mass-muted">на странице <select id="planSize"><option>25</option><option selected>50</option><option>100</option><option>200</option></select></label></div>
    </div>
</form>
<?php endif; ?>

<div class="bx-table-wrap">
    <div class="bx-table-toolbar"><div class="left"><b>Входящие ссылки в статьи</b> <span class="mass-muted">сначала те, на кого ссылаются меньше всего</span></div></div>
    <div class="tk-filters">
        <input type="search" id="inSearch" placeholder="Поиск по названию или номеру">
        <select id="inSection"><option value="">Все разделы</option>
            <?php foreach ($secOptions as $sid => $nm): ?><option value="<?= (int)$sid ?>"><?= $h($nm) ?></option><?php endforeach; ?></select>
        <select id="inLevel"><option value="">Любое число ссылок</option><option value="zero">без ссылок</option><option value="low">мало (до <?= (int)$lowThreshold ?>)</option><option value="ok">достаточно</option></select>
    </div>
    <table class="bx-table" id="inTable">
        <thead><tr><th>Статья</th><th>Раздел</th><th style="width:140px;">Входящих</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
            $lvl = $r['in'] === 0 ? 'zero' : ($r['in'] <= $lowThreshold ? 'low' : 'ok'); ?>
            <tr data-s="<?= $h(mb_strtolower('#' . $r['id'] . ' ' . $r['name'])) ?>" data-section="<?= (int)$r['sec'] ?>" data-level="<?= $lvl ?>">
                <td><span class="mass-muted">#<?= $r['id'] ?></span> <?= $h($r['name']) ?></td>
                <td class="mass-muted"><?= $h($r['section']) ?></td>
                <td><span class="pill <?= $lvl === 'zero' ? 'pill-weak' : ($lvl === 'low' ? 'pill-medium' : 'pill-strong') ?>"><?= $r['in'] ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="tk-foot"><span class="mass-muted" id="inInfo"></span><span class="tk-pager" id="inPager"></span>
        <label class="mass-muted">на странице <select id="inSize"><option>25</option><option selected>50</option><option>100</option></select></label></div>
</div>

<script>
bpTable({table: '#planTable', search: '#planSearch', filters: [{el: '#planSection', attr: 'section'}, {el: '#planSrc', attr: 'src'}, {el: '#planTt', attr: 'tt'}],
    master: '#massAll', pager: '#planPager', info: '#planInfo', selected: '#planSel', sizeSel: '#planSize', size: 50});
bpTable({table: '#inTable', search: '#inSearch', filters: [{el: '#inSection', attr: 'section'}, {el: '#inLevel', attr: 'level'}],
    pager: '#inPager', info: '#inInfo', sizeSel: '#inSize', size: 50});
</script>
<style>
.st-cards{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin:0 0 20px}
.st-card{background:#fff;border:1px solid #e5e9f0;border-radius:12px;padding:14px 16px;box-shadow:0 1px 2px rgba(16,24,40,.05)}
.st-card .v{font-size:26px;font-weight:700}.st-card .l{color:#6b7280;font-size:12px;margin-top:2px}
.mass-form{padding:18px 20px}
.mass-form h3{margin:18px 0 10px}.mass-form h3:first-child{margin-top:0}
.mass-row{display:flex;flex-wrap:wrap;gap:18px 28px;align-items:center;margin-bottom:12px}
.mass-row label{display:flex;gap:8px;align-items:center}
.mass-scope{display:flex;flex-direction:column;gap:10px;margin:6px 0 22px}
.mass-opt{display:flex;gap:10px;align-items:center;padding:10px 12px;border:1px solid #e3e6ec;border-radius:8px;background:#fff;flex-wrap:wrap}
.mass-inline{display:inline-flex;gap:6px;align-items:center}
.mass-muted{color:#9aa0ad;font-size:13px}
.tk-filters{display:flex;gap:10px;flex-wrap:wrap;align-items:center;padding:12px 16px;border-bottom:1px solid #eef0f4}
.tk-filters input[type=search]{flex:1;min-width:220px;padding:7px 10px;border:1px solid #d5d9e0;border-radius:6px}
.tk-filters select{padding:7px 8px;border:1px solid #d5d9e0;border-radius:6px}
.tk-foot{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding:12px 16px;border-top:1px solid #eef0f4}
.tk-pager{display:flex;gap:4px;align-items:center}.tk-dots{color:#9aa0ad;padding:0 4px}
.tk-foot select{padding:4px 6px;margin-left:4px}
tr[hidden]{display:none}
.mass-actions{display:flex;gap:14px;align-items:center;margin-top:16px}
.mass-form input[type=number]{width:72px}
.mass-form,.bx-table-wrap,.st-cards{margin-bottom:20px}
@media(max-width:900px){.st-cards{grid-template-columns:repeat(2,1fr)}}
mark{background:#fff3a3}
</style>
<?php endif; ?>
