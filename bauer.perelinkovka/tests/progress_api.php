<?php
/** Тестовый эндпоинт прогресс-баров: JSON-окружение + общий обработчик модуля. */
require_once __DIR__ . '/autoload.php';

\Bauer\Perelinkovka\Env::forJson(__DIR__ . '/data', 'asset.php?f=', 'progress_api.php');
require __DIR__ . '/../admin/progress_handler.php';
