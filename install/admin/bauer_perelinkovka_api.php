<?php
// Модуль может лежать в /local/modules или в /bitrix/modules
$bpRoot = $_SERVER['DOCUMENT_ROOT'] . '/local/modules/bauer.perelinkovka';
if (!is_file($bpRoot . '/admin/api.php')) {
    $bpRoot = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/bauer.perelinkovka';
}
require $bpRoot . '/admin/api.php';
