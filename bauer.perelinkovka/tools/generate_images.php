<?php
/**
 * Подстановка картинок в статьи: заменяет «[Изображение: описание]» на сгенерированную картинку.
 *
 * Использование:
 *   OPENROUTER_API_KEY=... php generate_images.php <файл-статьи> [--dir=./images] [--url=/upload/article_images]
 *       [--model=google/gemini-2.5-flash-image] [--style="Фотореализм, без текста на картинке."]
 *       [--limit=N] [--dry-run] [--out=<файл>]
 * По умолчанию файл статьи перезаписывается; --out сохраняет результат в другой файл.
 */
require_once __DIR__ . '/../lib/OpenRouterClient.php';
require_once __DIR__ . '/../lib/ArticleImages.php';

use Bauer\Perelinkovka\ArticleImages;
use Bauer\Perelinkovka\OpenRouterClient;

$opts = [];
$files = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $a, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    } else {
        $files[] = $a;
    }
}
if (!$files) {
    fwrite(STDERR, "Укажите файл статьи. См. описание в начале скрипта.\n");
    exit(1);
}

$text = file_get_contents($files[0]);
if ($text === false) {
    fwrite(STDERR, "Не удалось прочитать {$files[0]}\n");
    exit(1);
}

$blocks = ArticleImages::findBlocks($text);
echo 'Найдено блоков: ' . count($blocks) . "\n";
if (isset($opts['dry-run']) || !$blocks) {
    foreach ($blocks as $b) {
        echo " - $b\n";
    }
    exit(0);
}

$key = getenv('OPENROUTER_API_KEY') ?: '';
if ($key === '') {
    fwrite(STDERR, "Не задан OPENROUTER_API_KEY\n");
    exit(1);
}

$gen = new ArticleImages(
    new OpenRouterClient(['api_key' => $key]),
    $opts['dir'] ?? __DIR__ . '/images',
    $opts['url'] ?? '/upload/article_images',
    ['model' => $opts['model'] ?? 'google/gemini-2.5-flash-image', 'style' => is_string($opts['style'] ?? null) ? $opts['style'] : ArticleImages::DEFAULT_STYLE]
);
$res = $gen->process($text, isset($opts['limit']) ? (int)$opts['limit'] : null);

file_put_contents($opts['out'] ?? $files[0], $res['text']);
echo "Заменено: {$res['done']}\n";
foreach ($res['errors'] as $e) {
    echo "Ошибка: $e\n";
}
exit($res['errors'] ? 2 : 0);
