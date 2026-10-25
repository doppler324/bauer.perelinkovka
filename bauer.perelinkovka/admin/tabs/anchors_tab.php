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
// Разделы выбранного инфоблока с полным путём и числом статей
$secById = [];
foreach ($dataProvider->getSections() as $sec) {
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
$articlesSorted = $articles;
usort($articlesSorted, fn($x, $y) => [($secOptions[(int)($x['section_id'] ?? 0)] ?? ''), $x['name']] <=> [($secOptions[(int)($y['section_id'] ?? 0)] ?? ''), $y['name']]);
$curIdx = array_search($articleId, array_column($articlesSorted, 'id'));
$prevId = $curIdx > 0 ? $articlesSorted[$curIdx - 1]['id'] : 0;
$nextId = ($curIdx !== false && $curIdx < count($articlesSorted) - 1) ? $articlesSorted[$curIdx + 1]['id'] : 0;
$pickUrl = fn(int $id) => '?tab=anchors&article_iblock=' . $articleIblockId . '&article=' . $id;
?>
<div class="bx-filter art-picker" id="artPicker">
    <form method="GET" class="art-picker__top">
        <input type="hidden" name="tab" value="anchors">
        <div class="bx-filter-field">
            <label>Инфоблок статей</label>
            <select name="article_iblock">
                <?php foreach ($articleIblocks as $ib): ?>
                    <option value="<?= (int)$ib['id'] ?>" <?= (int)$ib['id'] === $articleIblockId ? 'selected' : '' ?>><?= $h($ib['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="bx-filter-field">
            <label>Раздел</label>
            <select id="artSection">
                <option value="">Все разделы (<?= count($articles) ?>)</option>
                <?php foreach ($secOptions as $sid => $name): ?>
                    <option value="<?= (int)$sid ?>"><?= $h($name) ?> (<?= (int)$secCount[$sid] ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="bx-filter-field art-picker__search">
            <label>Поиск статьи</label>
            <input type="search" id="artSearch" placeholder="Название или номер, например «смартфон» или 13" autocomplete="off">
        </div>
    </form>
    <div class="art-picker__list" id="artList">
        <?php $lastSec = null; foreach ($articlesSorted as $a):
            $sid = (int)($a['section_id'] ?? 0);
            if ($sid !== $lastSec): $lastSec = $sid; ?>
                <div class="art-picker__group" data-section="<?= $sid ?>"><?= $h($secOptions[$sid] ?? 'Без раздела') ?></div>
            <?php endif; ?>
            <a class="art-picker__item <?= $a['id'] === $articleId ? 'is-current' : '' ?>" href="<?= $h($pickUrl((int)$a['id'])) ?>"
               data-section="<?= $sid ?>" data-search="<?= $h(mb_strtolower('#' . $a['id'] . ' ' . $a['name'])) ?>">
                <span class="art-picker__id">#<?= (int)$a['id'] ?></span> <?= $h($a['name']) ?>
                <?php if ($anchorStore->get((int)$a['id'])): ?><span class="art-picker__done" title="Ссылки уже вставлены">✓</span><?php endif; ?>
            </a>
        <?php endforeach; ?>
        <div class="art-picker__empty" id="artEmpty" hidden>Ничего не найдено</div>
    </div>
    <div class="art-picker__foot">
        <span>Выбрано: <b>#<?= (int)$articleId ?> <?= $h($article['name'] ?? '') ?></b> <span id="artShown" class="art-picker__muted"></span></span>
        <span>
            <?php if ($prevId): ?><a class="bx-btn bx-btn-sm" href="<?= $h($pickUrl((int)$prevId)) ?>">← Предыдущая</a><?php endif; ?>
            <?php if ($nextId): ?><a class="bx-btn bx-btn-sm" href="<?= $h($pickUrl((int)$nextId)) ?>">Следующая →</a><?php endif; ?>
        </span>
    </div>
</div>
<script>
(function () {
    var list = document.getElementById('artList'), sec = document.getElementById('artSection'),
        q = document.getElementById('artSearch'), empty = document.getElementById('artEmpty'),
        shown = document.getElementById('artShown');
    function apply() {
        var s = sec.value, t = q.value.trim().toLowerCase(), n = 0, groups = {};
        list.querySelectorAll('.art-picker__item').forEach(function (a) {
            var ok = (!s || a.dataset.section === s) && (!t || a.dataset.search.indexOf(t) !== -1);
            a.hidden = !ok;
            if (ok) { n++; groups[a.dataset.section] = true; }
        });
        list.querySelectorAll('.art-picker__group').forEach(function (g) { g.hidden = !groups[g.dataset.section]; });
        empty.hidden = n > 0;
        shown.textContent = (s || t) ? '· найдено: ' + n : '';
    }
    var cur = list.querySelector('.is-current');
    if (cur) list.scrollTop = cur.offsetTop - list.offsetTop - 60;

    // Переключение статьи без перезагрузки страницы
    var picker = document.getElementById('artPicker');
    var busy = false;
    function load(url, full) {
        if (busy) return;
        busy = true;
        var main = document.getElementById('artMain');
        main.style.opacity = '.5';
        fetch(url, {credentials: 'same-origin'}).then(function (r) { return r.text(); }).then(function (html) {
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var newMain = doc.getElementById('artMain');
            if (!newMain) { location.href = url; return; }
            main.innerHTML = newMain.innerHTML;
            if (full) {
                // сменился инфоблок: список статей другой, пересобираем панель выбора
                picker.innerHTML = doc.getElementById('artPicker').innerHTML;
                bindPicker();
            } else {
                var nf = doc.querySelector('#artPicker .art-picker__foot');
                picker.querySelector('.art-picker__foot').innerHTML = nf.innerHTML;
                var nc = new URL(url, location.href).searchParams.get('article');
                list.querySelectorAll('.art-picker__item').forEach(function (a) {
                    a.classList.toggle('is-current', new URL(a.href).searchParams.get('article') === nc);
                });
                apply();
            }
            history.pushState(null, '', url);
            main.style.opacity = '';
        }).catch(function () { location.href = url; }).then(function () { busy = false; });
    }
    function bindPicker() {
        list = document.getElementById('artList'); sec = document.getElementById('artSection');
        q = document.getElementById('artSearch'); empty = document.getElementById('artEmpty'); shown = document.getElementById('artShown');
        sec.addEventListener('change', apply);
        q.addEventListener('input', apply);
        q.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); var f = list.querySelector('.art-picker__item:not([hidden])'); if (f) load(f.href, false); }
        });
        var ib = picker.querySelector('select[name=article_iblock]');
        ib.onchange = function () { load('?tab=anchors&article_iblock=' + ib.value, true); };
    }
    picker.addEventListener('click', function (e) {
        var a = e.target.closest('a.art-picker__item, .art-picker__foot a');
        if (!a || e.ctrlKey || e.metaKey) return;
        e.preventDefault();
        load(a.href, false);
    });
    bindPicker();
})();
</script>
<style>
.art-picker{padding:14px;display:block}
.art-picker__top{display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end;margin-bottom:12px}
.art-picker__search{flex:1;min-width:260px}.art-picker__search input{width:100%;box-sizing:border-box}
.art-picker__list{max-height:280px;overflow:auto;border:1px solid #e3e6ec;border-radius:8px;background:#fff}
.art-picker__group{position:sticky;top:0;padding:6px 12px;font-size:12px;font-weight:600;color:#6b7280;background:#f5f6fa;border-bottom:1px solid #e3e6ec}
.art-picker__item{display:block;padding:7px 12px;color:inherit;text-decoration:none;border-bottom:1px solid #f0f1f5}
.art-picker__item:hover{background:#f3f4ff}.art-picker__item.is-current{background:#e8e9ff;font-weight:600}
.art-picker__id{color:#9aa0ad;margin-right:4px}.art-picker__done{color:#16a34a;margin-left:6px}
.art-picker__empty{padding:18px;text-align:center;color:#999}
.art-picker__foot{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:10px;flex-wrap:wrap}
.art-picker__muted{color:#9aa0ad}
.art-picker [hidden]{display:none!important}
</style>

<div id="artMain">
<form method="POST" action="?tab=anchors&article_iblock=<?= $articleIblockId ?>&article=<?= $articleId ?>">
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
