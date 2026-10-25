<?php
/**
 * Эндпоинт прогресс-баров (JSON). Подключается из /bitrix/admin/bauer_perelinkovka_api.php.
 * Только администратор и только POST с корректным sessid.
 */
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_CHECK', true);
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

global $USER;
header('Content-Type: application/json; charset=utf-8');
$deny = function (string $m, int $code) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $m], JSON_UNESCAPED_UNICODE);
    exit;
};
if (!$USER->IsAdmin()) {
    $deny('Доступ запрещён', 403);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !check_bitrix_sessid()) {
    $deny('Сессия устарела, обновите страницу', 400);
}
if (!\Bitrix\Main\Loader::includeModule('bauer.perelinkovka') || !\Bitrix\Main\Loader::includeModule('iblock')) {
    $deny('Модуль не подключён', 500);
}

\Bauer\Perelinkovka\Env::forBitrix('/bitrix/js/bauer.perelinkovka/', '/bitrix/admin/bauer_perelinkovka_api.php');
require __DIR__ . '/progress_handler.php';
