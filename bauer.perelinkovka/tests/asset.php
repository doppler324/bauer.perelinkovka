<?php
// Отдаёт js/css модуля для тестовой админки (в Битриксе они лежат в /bitrix/js и /bitrix/css).
$f = basename((string)($_GET['f'] ?? ''));
foreach (['js' => 'application/javascript', 'css' => 'text/css'] as $ext => $type) {
    $path = __DIR__ . "/../install/$ext/$f";
    if (str_ends_with($f, ".$ext") && is_file($path)) {
        header("Content-Type: $type; charset=utf-8");
        header('Cache-Control: no-cache');
        readfile($path);
        exit;
    }
}
http_response_code(404);
