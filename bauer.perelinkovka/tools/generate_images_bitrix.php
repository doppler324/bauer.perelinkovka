<?php
/**
 * Подстановка картинок в статьи инфоблока Битрикс: в DETAIL_TEXT заменяет «[Изображение: описание]»
 * на сгенерированную картинку.
 *
 * Запуск из консоли на сервере сайта:
 *   OPENROUTER_API_KEY=... php generate_images_bitrix.php --iblock=ID [--root=/путь/к/сайту] [--limit=N]
 *       [--model=...] [--style="..."] [--dry-run]
 * --limit — максимум генераций за запуск (по всем элементам). Картинки кладутся в /upload/article_images.
 */
$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $a, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}
if (empty($opts['iblock'])) {
    fwrite(STDERR, "Укажите --iblock=ID\n");
    exit(1);
}

$_SERVER['DOCUMENT_ROOT'] = rtrim($opts['root'] ?? ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
if ($_SERVER['DOCUMENT_ROOT'] === '') {
    fwrite(STDERR, "Укажите --root=путь к корню сайта\n");
    exit(1);
}
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_BUFFER_USED', true);
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bauer\Perelinkovka\ArticleImages;
use Bauer\Perelinkovka\OpenRouterClient;

if (!\Bitrix\Main\Loader::includeModule('iblock') || !\Bitrix\Main\Loader::includeModule('bauer.perelinkovka')) {
    fwrite(STDERR, "Не подключились модули iblock и bauer.perelinkovka\n");
    exit(1);
}
$key = getenv('OPENROUTER_API_KEY') ?: '';
if ($key === '' && !isset($opts['dry-run'])) {
    fwrite(STDERR, "Не задан OPENROUTER_API_KEY\n");
    exit(1);
}

$gen = new ArticleImages(
    new OpenRouterClient(['api_key' => $key]),
    $_SERVER['DOCUMENT_ROOT'] . '/upload/article_images',
    '/upload/article_images',
    ['model' => $opts['model'] ?? 'google/gemini-2.5-flash-image', 'style' => $opts['style'] ?? '']
);

$left = isset($opts['limit']) ? (int)$opts['limit'] : null;
$updated = 0;
$errors = 0;
$res = CIBlockElement::GetList(
    ['ID' => 'ASC'],
    ['IBLOCK_ID' => (int)$opts['iblock'], '%DETAIL_TEXT' => '[Изображение:'],
    false,
    false,
    ['ID', 'NAME', 'DETAIL_TEXT']
);
$el = new CIBlockElement();
while ($row = $res->Fetch()) {
    if ($left !== null && $left <= 0) {
        break;
    }
    $blocks = ArticleImages::findBlocks((string)$row['DETAIL_TEXT']);
    if (!$blocks) {
        continue;
    }
    echo "[{$row['ID']}] {$row['NAME']}: блоков " . count($blocks) . "\n";
    if (isset($opts['dry-run'])) {
        continue;
    }
    $r = $gen->process((string)$row['DETAIL_TEXT'], $left);
    if ($left !== null) {
        $left -= $r['done'];
    }
    foreach ($r['errors'] as $e) {
        echo "  Ошибка: $e\n";
        $errors++;
    }
    if ($r['done'] > 0) {
        if ($el->Update((int)$row['ID'], ['DETAIL_TEXT' => $r['text']])) {
            $updated++;
        } else {
            echo '  Не удалось сохранить элемент: ' . $el->LAST_ERROR . "\n";
            $errors++;
        }
    }
}
echo "Обновлено элементов: $updated, ошибок: $errors\n";
exit($errors ? 2 : 0);
