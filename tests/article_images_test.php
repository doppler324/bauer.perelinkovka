<?php
// Проверка поиска и подстановки блоков «[Изображение: ...]» без обращения к OpenRouter.
require_once __DIR__ . '/autoload.php';

use Bauer\Perelinkovka\ArticleImages;
use Bauer\Perelinkovka\OpenRouterClient;

class FakeClient extends OpenRouterClient
{
    public function generateImage(string $prompt, array $options = []): array
    {
        if (strpos($prompt, 'ошибка') !== false) {
            throw new \RuntimeException('fail');
        }
        return ['data' => 'PNGDATA', 'mime' => 'image/png'];
    }
}

$text = "A\n[Изображение: Два шланга, красный и синий.]\nB\n[Изображение: ошибка]";
assert(count(ArticleImages::findBlocks($text)) === 2);

$dir = sys_get_temp_dir() . '/ai_test_' . getmypid();
$g = new ArticleImages(new FakeClient(), $dir, '/img');
$r = $g->process($text);
assert($r['done'] === 1 && count($r['errors']) === 1);
assert(strpos($r['text'], '<img src="/img/') !== false);
assert(strpos($r['text'], '[Изображение: ошибка]') !== false);
array_map('unlink', glob($dir . '/*'));
rmdir($dir);
echo "OK\n";
