<?php
/**
 * Страница админки модуля «Перелинковка». Подключается из /bitrix/admin/bauer_perelinkovka.php.
 * Вкладки (admin/tabs) и оболочка (admin/ui.php) те же, что в тестовой админке на JSON-данных.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bauer\Perelinkovka\Env;
use Bitrix\Main\Loader;

global $APPLICATION, $USER;

if (!$USER->IsAdmin()) {
    $APPLICATION->AuthForm('Доступ запрещён');
}
if (!Loader::includeModule('bauer.perelinkovka') || !Loader::includeModule('iblock')) {
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
    CAdminMessage::ShowMessage(['MESSAGE' => 'Модуль «Перелинковка» или модуль «Инфоблоки» не подключён', 'TYPE' => 'ERROR']);
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
    return;
}

Env::forBitrix('/bitrix/js/bauer.perelinkovka/', '/bitrix/admin/bauer_perelinkovka_api.php');
require_once __DIR__ . '/ui.php';

$tab = bp_tab($_GET['tab'] ?? null);
[$section, $title] = bp_section_titles($tab);

$APPLICATION->SetTitle('Перелинковка: ' . $title);
$APPLICATION->SetAdditionalCSS('/bitrix/css/bauer.perelinkovka/admin.css');
$APPLICATION->AddHeadScript('/bitrix/js/bauer.perelinkovka/tablekit.js');

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !check_bitrix_sessid()) {
    CAdminMessage::ShowMessage(['MESSAGE' => 'Сессия устарела, обновите страницу и повторите действие', 'TYPE' => 'ERROR']);
} else {
    $settingsUrl = '/bitrix/admin/settings.php?mid=bauer.perelinkovka&lang=' . LANGUAGE_ID . '&back_url_settings=' . urlencode('/bitrix/admin/bauer_perelinkovka.php?lang=' . LANGUAGE_ID);
    ?>
    <div id="bp-app">
        <?php bp_render_menus($tab, $settingsUrl); ?>
        <div class="bx-content">
            <?php bp_render_tab($tab); ?>
        </div>
    </div>
    <script>
        window.BP_API = <?= CUtil::PhpToJSObject(Env::apiUrl()) ?>;
        window.BP_SESSID = <?= CUtil::PhpToJSObject(bitrix_sessid()) ?>;
    </script>
    <script src="/bitrix/js/bauer.perelinkovka/ajaxnav.js"></script>
    <script src="/bitrix/js/bauer.perelinkovka/progress.js"></script>
    <?php
}

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
