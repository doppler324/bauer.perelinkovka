<?php
/**
 * anchors_tab.php — вкладка «Анкоры в текстах» (подключается из admin_preview.php по ?tab=anchors).
 * Ищет в тексте статьи фразы целей (название, части, ключи, синонимы) и расставляет ссылки.
 * Данные берёт из Env (тестовые JSON или Битрикс).
 */

use Bauer\Perelinkovka\Env;

$dataProvider = Env::provider();
$anchorEngine = Env::anchorEngine();
$anchorStore = Env::anchorStore();

// Инфоблоки, в которых есть статьи (элементы с текстом)
$articleIblocks = array_values(array_filter(
    $dataProvider->getIBlocks(),
    fn($ib) => $dataProvider->countElements(['iblock_id' => (int)$ib['id'], 'has_text' => true]) > 0
));
$articleIblockIds = array_map(fn($ib) => (int)$ib['id'], $articleIblocks);
$articleIblockId = (int)($_REQUEST['article_iblock'] ?? 0);
if (!in_array($articleIblockId, $articleIblockIds, true)) {
    $articleIblockId = $articleIblockIds[0] ?? 0;
}
$articles = $articleIblockId
    ? array_map(function ($e) { unset($e['text']); return $e; }, $dataProvider->getElements(['iblock_id' => $articleIblockId, 'has_text' => true]))
    : [];
$articleId = (int)($_REQUEST['article'] ?? 0);
if (!in_array($articleId, array_map(fn($e) => (int)$e['id'], $articles), true)) {
    $articleId = (int)($articles[0]['id'] ?? 0);
}
$article = $articleId ? $dataProvider->getElement($articleId) : null;

$anchorMsg = '';
$matches = [];
$originalText = $article['text'] ?? '';
$currentText = $originalText;
$stored = $article ? $anchorStore->get($articleId) : null;
if ($stored) {
    $currentText = $stored['text'];
}

if ($article) {
    // Цели: элементы и разделы инфоблоков из настроек анкоров, кроме самой статьи
    $targets = array_values(array_filter(
        Env::anchorTargets(),
        fn($t) => !(($t['type'] ?? 'element') === 'element' && (int)$t['id'] === $articleId)
    ));

    $action = $_POST['anchor_action'] ?? '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'reset') {
        $anchorStore->reset($articleId);
        $currentText = $originalText;
        $stored = null;
        $anchorMsg = 'Текст статьи возвращён к исходному.';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'apply') {
        $only = array_map('strval', (array)($_POST['targets'] ?? []));
        $res = $anchorEngine->process($currentText, $targets, true, $only);
        if ($res['matches']) {
            $links = array_merge($stored['links'] ?? [], array_map(
                fn($m) => ['target_id' => $m['target_id'], 'anchor' => $m['anchor'], 'href' => $m['href']],
                $res['matches']
            ));
            $anchorStore->save($articleId, $res['html'], $links);
            $currentText = $res['html'];
            $stored = $anchorStore->get($articleId);
            $anchorMsg = 'Вставлено ссылок: ' . count($res['matches']);
        } else {
            $anchorMsg = 'Ничего не выбрано или подходящих мест нет.';
        }
    }
    $matches = $anchorEngine->process($currentText, $targets)['matches'];
}

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<h1 class="bx-page-title">Анкоры и синонимы в тексте <a href="?tab=anchor_settings" class="bx-btn bx-btn-sm" style="vertical-align:middle;">⚙️ Настройки</a></h1>

<?php if ($anchorMsg): ?>
    <div class="bx-notification bx-notification-success">✅ <?= $h($anchorMsg) ?></div>
<?php endif; ?>

<?php if (!$articles): ?>
    <div class="bx-notification bx-notification-warning">Нет статей: в инфоблоках нет элементов с текстом (детальное описание).</div>
<?php else: ?>
<?php
// Разделы выбранного инфоблока с полным путём
$secById = [];
foreach ($dataProvider->getSections(['iblock_id' => $articleIblockId]) as $sec) {
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
$secCount = [];
foreach ($articles as $a) {
    $sid = (int)($a['section_id'] ?? 0);
    $secCount[$sid] = ($secCount[$sid] ?? 0) + 1;
}
$secOptions = [];
foreach ($secCount as $sid => $cnt) {
    $secOptions[$sid] = ($sid ? $secPath($sid) : 'Без раздела');
}
asort($secOptions, SORT_NATURAL | SORT_FLAG_CASE);

// Фильтр, сортировка и страницы списка статей
$fSection = isset($_GET['section']) && $_GET['section'] !== '' ? (int)$_GET['section'] : null;
$fSearch = trim((string)($_GET['search'] ?? ''));
$perPageOptions = [10, 20, 50, 100, 200];
$perPage = (int)($_GET['per_page'] ?? 10);
if (!in_array($perPage, $perPageOptions, true)) {
    $perPage = 10;
}
$sortBy = in_array($_GET['sort'] ?? '', ['id', 'name'], true) ? $_GET['sort'] : 'id';
$sortDir = ($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

$list = array_values(array_filter($articles, function ($a) use ($fSection, $fSearch) {
    if ($fSection !== null && (int)($a['section_id'] ?? 0) !== $fSection) {
        return false;
    }
    return $fSearch === '' || mb_stripos('#' . $a['id'] . ' ' . $a['name'], $fSearch) !== false;
}));
usort($list, function ($x, $y) use ($sortBy, $sortDir) {
    $r = $sortBy === 'name' ? strcasecmp((string)$x['name'], (string)$y['name']) : (int)$x['id'] <=> (int)$y['id'];
    return $sortDir === 'desc' ? -$r : $r;
});
$total = count($list);
$pages = max(1, (int)ceil($total / $perPage));
if (isset($_GET['page'])) {
    $page = (int)$_GET['page'];
} else {
    $idx = array_search($articleId, array_column($list, 'id'));
    $page = $idx === false ? 1 : intdiv((int)$idx, $perPage) + 1;
}
$page = max(1, min($pages, $page));
$pageItems = array_slice($list, ($page - 1) * $perPage, $perPage);

$listParams = ['tab' => 'anchors', 'article_iblock' => $articleIblockId, 'section' => $fSection ?? '', 'search' => $fSearch,
    'per_page' => $perPage, 'sort' => $sortBy, 'dir' => $sortDir];
$url = fn(array $over = []) => '?' . http_build_query(array_merge($listParams, ['article' => $articleId, 'page' => $page], $over));
$sortLink = fn(string $col) => $url(['sort' => $col, 'dir' => ($sortBy === $col && $sortDir === 'asc') ? 'desc' : 'asc', 'page' => 1]);
$arrow = fn(string $col) => $sortBy === $col ? ($sortDir === 'asc' ? ' ▲' : ' ▼') : '';

// страницы для навигации: первая, последняя и окно вокруг текущей
$pageNums = array_values(array_unique(array_filter(array_merge([1, $pages], range(max(1, $page - 2), min($pages, $page + 2))))));
sort($pageNums);
?>
<?php if (Env::isBitrix()): // стандартный CAdminList (строится в admin/articles_list.php) ?>
<div class="bp-native">
<form method="GET" id="artFilter" style="margin-bottom:12px;">
    <input type="hidden" name="lang" value="<?= $h(LANGUAGE_ID) ?>">
    <input type="hidden" name="tab" value="anchors">
    <input type="hidden" name="article" value="<?= (int)$articleId ?>">
    <table class="adm-filter-content-table" style="margin:8px 0;">
        <tr>
            <td style="padding:4px 12px 4px 0;">Инфоблок:</td>
            <td style="padding:4px 12px 4px 0;"><select class="adm-select" name="article_iblock" onchange="this.form.elements.section.value='';this.form.elements.article.value='';this.form.submit()">
                <?php foreach ($articleIblocks as $ib): ?>
                    <option value="<?= (int)$ib['id'] ?>" <?= (int)$ib['id'] === $articleIblockId ? 'selected' : '' ?>><?= $h($ib['name']) ?></option>
                <?php endforeach; ?></select></td>
            <td style="padding:4px 12px 4px 0;">Раздел:</td>
            <td style="padding:4px 12px 4px 0;"><select class="adm-select" name="section" onchange="this.form.submit()">
                <option value="">(все) — <?= count($articles) ?></option>
                <?php foreach ($secOptions as $sid => $name): ?>
                    <option value="<?= (int)$sid ?>" <?= $fSection === (int)$sid ? 'selected' : '' ?>><?= $h($name) ?> (<?= (int)$secCount[$sid] ?>)</option>
                <?php endforeach; ?></select></td>
            <td style="padding:4px 12px 4px 0;">Поиск:</td>
            <td style="padding:4px 12px 4px 0;"><input type="text" class="adm-input" name="search" value="<?= $h($fSearch) ?>" size="30" placeholder="название или номер"></td>
            <td><input type="submit" class="adm-btn adm-btn-save" value="Найти"></td>
        </tr>
    </table>
</form>
<?php require_once dirname(__DIR__) . '/articles_list.php'; bp_articles_list_display(); ?>
<div style="margin:10px 0;">Выбрана: <b>#<?= (int)$articleId ?> <?= $h($article['name'] ?? '') ?></b></div>
</div>
<?php else: ?>
<form method="GET" class="bx-filter" id="artFilter">
    <input type="hidden" name="tab" value="anchors">
    <input type="hidden" name="article" value="<?= (int)$articleId ?>">
    <input type="hidden" name="sort" value="<?= $h($sortBy) ?>"><input type="hidden" name="dir" value="<?= $h($sortDir) ?>">
    <div class="bx-filter-field">
        <label>Инфоблок статей</label>
        <select name="article_iblock" onchange="this.form.elements.section.value='';this.form.elements.article.value='';this.form.submit()">
            <?php foreach ($articleIblocks as $ib): ?>
                <option value="<?= (int)$ib['id'] ?>" <?= (int)$ib['id'] === $articleIblockId ? 'selected' : '' ?>><?= $h($ib['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="bx-filter-field">
        <label>Раздел</label>
        <select name="section" onchange="this.form.submit()">
            <option value="">Все разделы (<?= count($articles) ?>)</option>
            <?php foreach ($secOptions as $sid => $name): ?>
                <option value="<?= (int)$sid ?>" <?= $fSection === (int)$sid ? 'selected' : '' ?>><?= $h($name) ?> (<?= (int)$secCount[$sid] ?>)</option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="bx-filter-field" style="flex:1;min-width:220px;">
        <label>Поиск статьи</label>
        <input type="search" name="search" value="<?= $h($fSearch) ?>" placeholder="Название или номер" style="width:100%;box-sizing:border-box;">
    </div>
    <div class="bx-filter-field">
        <label>На странице</label>
        <select name="per_page" onchange="this.form.submit()">
            <?php foreach ($perPageOptions as $n): ?><option value="<?= $n ?>" <?= $n === $perPage ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="bx-filter-field"><label>&nbsp;</label><button type="submit" class="bx-btn bx-btn-primary">Найти</button></div>
</form>

<div class="bx-table-wrap" id="artList" style="margin-bottom:16px;">
    <table class="bx-table">
        <thead><tr>
            <th style="width:70px;"><a href="<?= $h($sortLink('id')) ?>">ID<?= $arrow('id') ?></a></th>
            <th><a href="<?= $h($sortLink('name')) ?>">Название<?= $arrow('name') ?></a></th>
            <th>Раздел</th>
            <th style="width:90px;">Ссылки</th>
        </tr></thead>
        <tbody>
        <?php if (!$pageItems): ?>
            <tr><td colspan="4" style="text-align:center;padding:24px;color:#999;">Ничего не найдено</td></tr>
        <?php endif; ?>
        <?php foreach ($pageItems as $a): $cur = (int)$a['id'] === $articleId; ?>
            <tr class="<?= $cur ? 'bx-row-selected' : '' ?>">
                <td>#<?= (int)$a['id'] ?></td>
                <td><a href="<?= $h($url(['article' => (int)$a['id']])) ?>"<?= $cur ? ' style="font-weight:600;"' : '' ?>><?= $h($a['name']) ?></a></td>
                <td style="color:#666;"><?= $h($secOptions[(int)($a['section_id'] ?? 0)] ?? 'Без раздела') ?></td>
                <td><?php if ($anchorStore->get((int)$a['id'])): ?><span style="color:#16a34a;" title="Ссылки уже вставлены">✓ есть</span><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="bx-table-toolbar">
        <div class="left">
            <?php if ($page > 1): ?><a class="bx-btn bx-btn-sm" href="<?= $h($url(['page' => $page - 1])) ?>">←</a><?php endif; ?>
            <?php $prevN = 0; foreach ($pageNums as $n): ?>
                <?php if ($prevN && $n - $prevN > 1): ?><span>…</span><?php endif; ?>
                <a class="bx-btn bx-btn-sm<?= $n === $page ? ' bx-btn-primary' : '' ?>" href="<?= $h($url(['page' => $n])) ?>"><?= $n ?></a>
                <?php $prevN = $n; endforeach; ?>
            <?php if ($page < $pages): ?><a class="bx-btn bx-btn-sm" href="<?= $h($url(['page' => $page + 1])) ?>">→</a><?php endif; ?>
        </div>
        <div class="right" style="color:#666;font-size:12px;align-items:center;">
            Выбрана: <b>#<?= (int)$articleId ?> <?= $h($article['name'] ?? '') ?></b> · показано <?= count($pageItems) ?> из <?= $total ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div id="artMain">
<form method="POST" action="<?= $h($url()) ?>">
    <input type="hidden" name="article" value="<?= $articleId ?>">
    <input type="hidden" name="article_iblock" value="<?= $articleIblockId ?>">
    <div class="bx-table-wrap" style="margin-bottom:16px;">
        <div class="bx-table-toolbar">
            <div class="left"><b>Найденные места для ссылок: <?= count($matches) ?></b></div>
            <div class="right">
                <?php if ($stored): ?>
                    <button type="submit" name="anchor_action" value="reset" class="bx-btn bx-btn-sm bx-btn-danger" onclick="return confirm('Вернуть исходный текст?')">↩ Сбросить</button>
                <?php endif; ?>
                <button type="submit" name="anchor_action" value="apply" class="bx-btn bx-btn-sm bx-btn-success">✔ Вставить выбранные</button>
            </div>
        </div>
        <table class="bx-table">
            <thead><tr><th style="width:30px;"><input type="checkbox" class="select-all" id="anchorsAll" title="Выбрать все" checked></th><th>Цель</th><th>Анкор</th><th>Источник фразы</th><th>Контекст</th></tr></thead>
            <tbody>
            <?php if (!$matches): ?>
                <tr><td colspan="5" style="text-align:center;padding:30px;color:#999;">Подходящих мест не найдено</td></tr>
            <?php endif; ?>
            <?php foreach ($matches as $m): ?>
                <tr>
                    <td><input type="checkbox" name="targets[]" value="<?= $m['target_type'] ?>:<?= $m['target_id'] ?>" checked></td>
                    <td>
                        <span class="entity-type type-<?= $m['target_type'] === 'element' ? 'elem' : 'sect' ?>"><?= $m['target_type'] === 'element' ? 'ЭЛ' : 'РЗ' ?></span>
                        #<?= $m['target_id'] ?>: <?= $h($m['target_name']) ?>
                    </td>
                    <td><b><?= $h($m['anchor']) ?></b></td>
                    <td><span class="badge badge-related"><?= $h($m['source']) ?></span></td>
                    <td style="color:#666;">…<?= $h($m['context']['left']) ?><mark><?= $h($m['context']['match']) ?></mark><?= $h($m['context']['right']) ?>…</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</form>

<div class="bx-settings-section">
    <h3>Текст статьи<?= $stored ? ' (со вставленными ссылками, обновлён ' . $h($stored['updated']) . ')' : '' ?></h3>
    <div style="line-height:1.6;"><?= $currentText ?></div>
</div>
</div>
<script>
(function () {
    // делегирование: блок #artMain подменяется без перезагрузки страницы
    document.addEventListener('change', function (e) {
        var t = e.target;
        if (t.id === 'anchorsAll') {
            document.querySelectorAll('input[name="targets[]"]').forEach(function (c) { c.checked = t.checked; });
        } else if (t.name === 'targets[]') {
            var all = document.getElementById('anchorsAll');
            if (all) all.checked = document.querySelectorAll('input[name="targets[]"]:checked').length === document.querySelectorAll('input[name="targets[]"]').length;
        }
    });
})();
</script>
<style>.bx-settings-section a[data-bp]{color:#1f7bc5;border-bottom:1px dashed #1f7bc5;text-decoration:none;} mark{background:#fff3a3;}</style>
<?php endif; ?>
