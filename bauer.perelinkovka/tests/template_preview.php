<?php
/**
 * Предпросмотр шаблонов компонента вывода (до установки в Битрикс).
 * Открыть: http://localhost:8000/template_preview.php
 * Берёт готовые связи блока из data/, подставляет их в шаблон из components/.../templates/ и подключает его style.css.
 */
define('B_PROLOG_INCLUDED', true);
if (!function_exists('htmlspecialcharsbx')) {
    function htmlspecialcharsbx($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
}
require_once __DIR__ . '/autoload.php';

use Bauer\Perelinkovka\JsonDataProvider;
use Bauer\Perelinkovka\Linker;

$provider = new JsonDataProvider(__DIR__ . '/data');
$linker = new Linker($provider);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);

$blocks = $provider->getBlocks();
$blockCode = $_GET['block'] ?? ($blocks[0]['code'] ?? '');
$elements = $provider->getElements();
$elementId = (int)($_GET['element_id'] ?? ($elements[0]['id'] ?? 1));
$tplName = ($_GET['tpl'] ?? '.default') === 'list' ? 'list' : '.default';

$arParams = [
    'TITLE' => $_GET['title'] ?? 'Похожие товары',
    'COLUMNS' => max(1, min(6, (int)($_GET['cols'] ?? 4))),
    'SHOW_PICTURE' => isset($_GET['go']) ? isset($_GET['pic']) : true,
    'SHOW_PREVIEW' => isset($_GET['prev']),
    'SHOW_TYPE' => isset($_GET['type']),
];
$arResult = ['ITEMS' => $linker->getLinkedEntities('element', $elementId, 50, $blockCode ?: null)];
foreach ($arResult['ITEMS'] as &$it) {
    $e = $provider->getElement((int)$it['entity_id']);
    $it['url'] = '#' . ($it['code'] ?? $it['entity_id']);
    $it['picture'] = '';
    $it['preview'] = $arParams['SHOW_PREVIEW'] ? (string)($e['description'] ?? '') : '';
}
unset($it);
$tplDir = __DIR__ . '/../install/components/bauer/perelinkovka.links/templates/' . $tplName;
?><!doctype html>
<html lang="ru"><head><meta charset="utf-8"><title>Предпросмотр шаблона</title>
<style>body{font-family:system-ui,sans-serif;margin:0;background:#fafafa}.bar{background:#fff;border-bottom:1px solid #e3e6ec;padding:12px 24px;display:flex;gap:16px;flex-wrap:wrap;align-items:center}.bar select,.bar input[type=text],.bar input[type=number]{padding:6px 8px;border:1px solid #d5d9e0;border-radius:6px}.page{max-width:1100px;margin:0 auto;padding:24px}</style>
<link id="tplCss" rel="stylesheet" href="../install/components/bauer/perelinkovka.links/templates/<?= $h($tplName) ?>/style.css">
</head><body>
<form class="bar" method="get" id="bar">
    <input type="hidden" name="go" value="1">
    <label>Блок <select name="block"><?php foreach ($blocks as $b): ?><option value="<?= $h($b['code']) ?>" <?= $b['code'] === $blockCode ? 'selected' : '' ?>><?= $h($b['name']) ?></option><?php endforeach ?></select></label>
    <label>Страница <select name="element_id"><?php foreach ($elements as $e): ?><option value="<?= (int)$e['id'] ?>" <?= (int)$e['id'] === $elementId ? 'selected' : '' ?>><?= $h($e['name']) ?></option><?php endforeach ?></select></label>
    <label>Шаблон <select name="tpl"><option value=".default" <?= $tplName === '.default' ? 'selected' : '' ?>>Карточки</option><option value="list" <?= $tplName === 'list' ? 'selected' : '' ?>>Список</option></select></label>
    <label>Заголовок <input type="text" name="title" value="<?= $h($arParams['TITLE']) ?>"></label>
    <label>Колонок <input type="number" name="cols" min="1" max="6" value="<?= (int)$arParams['COLUMNS'] ?>" style="width:60px"></label>
    <label><input type="checkbox" name="pic" <?= $arParams['SHOW_PICTURE'] ? 'checked' : '' ?>> картинка</label>
    <label><input type="checkbox" name="prev" <?= $arParams['SHOW_PREVIEW'] ? 'checked' : '' ?>> описание</label>
    <label><input type="checkbox" name="type" <?= $arParams['SHOW_TYPE'] ? 'checked' : '' ?>> тип связи</label>
    <button>Показать</button>
</form>
<div class="page"><?php include $tplDir . '/template.php'; ?>
<?php if (!$arResult['ITEMS']): ?><p style="color:#888">У этой страницы нет связей в выбранном блоке. Создайте их во вкладке «Список связей».</p><?php endif ?></div>
<script>
// Обновление предпросмотра без перезагрузки страницы
(function () {
    var form = document.getElementById('bar'), t = null;
    function refresh() {
        var q = new URLSearchParams(new FormData(form)).toString();
        fetch('?' + q).then(function (r) { return r.text(); }).then(function (html) {
            var doc = new DOMParser().parseFromString(html, 'text/html');
            document.querySelector('.page').innerHTML = doc.querySelector('.page').innerHTML;
            var css = document.getElementById('tplCss'), nc = doc.getElementById('tplCss');
            if (css.getAttribute('href') !== nc.getAttribute('href')) { css.setAttribute('href', nc.getAttribute('href')); }
            history.replaceState(null, '', '?' + q);
        });
    }
    form.addEventListener('submit', function (e) { e.preventDefault(); refresh(); });
    form.addEventListener('change', refresh);
    form.addEventListener('input', function (e) { if (e.target.type === 'text' || e.target.type === 'number') { clearTimeout(t); t = setTimeout(refresh, 250); } });
})();
</script>
</body></html>
