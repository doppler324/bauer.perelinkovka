<?php
/**
 * module_shell.php — проверка боевой оболочки модуля (admin/ui.php + scoped admin.css) на JSON-данных,
 * без Битрикса. В Битриксе тот же код выводит admin/page.php внутри штатной админки.
 */
require_once __DIR__ . '/autoload.php';

use Bauer\Perelinkovka\Env;

Env::forJson(__DIR__ . '/data', 'asset.php?f=', 'progress_api.php');
require_once __DIR__ . '/../admin/ui.php';
$tab = bp_tab($_GET['tab'] ?? null);
[$section, $title] = bp_section_titles($tab);
?><!DOCTYPE html>
<html lang="ru"><head><meta charset="UTF-8"><title><?= $title ?></title>
<style>body{margin:0;font:13px Arial;background:#fff}.fake-bitrix{background:#3b4b5a;color:#fff;padding:10px 20px}.fake-wrap{padding:10px}</style>
<link rel="stylesheet" href="<?= Env::assetUrl('admin.css') ?>">
<script src="<?= Env::assetUrl('tablekit.js') ?>"></script>
</head><body>
<div class="fake-bitrix">Имитация штатной админки Битрикса (шапка)</div>
<div class="fake-wrap">
<div id="bp-app">
<?php bp_render_menus($tab, '#settings'); ?>
<div class="bx-content">
<?php bp_render_tab($tab); ?>
</div>
</div></div>
<script>window.BP_API = <?= json_encode(Env::apiUrl()) ?>; window.BP_SESSID = 'test';</script>
<script src="<?= Env::assetUrl('ajaxnav.js') ?>"></script>
<script src="<?= Env::assetUrl('progress.js') ?>"></script>
</body></html>
