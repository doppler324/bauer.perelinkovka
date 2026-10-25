<?php
/**
 * component_test.php
 * 
 * Тестовая страница для демонстрации компонента LinksComponent.
 * Показывает, как будут выглядеть связанные материалы на страницах сайта.
 * 
 * Запуск: php -S localhost:8000 -t bauer.perelinkovka/tests
 * Открыть: http://localhost:8000/component_test.php
 */

require_once __DIR__ . '/autoload.php';

use Bauer\Perelinkovka\JsonDataProvider;
use Bauer\Perelinkovka\Linker;
use Bauer\Perelinkovka\KeywordMatchingStrategy;
use Bauer\Perelinkovka\TfIdfStrategy;
use Bauer\Perelinkovka\LinksComponent;

$dataDir = __DIR__ . '/data';
$dataProvider = new JsonDataProvider($dataDir);
$linker = new Linker($dataProvider);
$linker->setStrategies([
    new KeywordMatchingStrategy(['weight' => 0.15]),
    new TfIdfStrategy($dataProvider, ['weight' => 0.25]),
]);

// Выбираем элемент для демонстрации
$elementId = intval($_GET['element_id'] ?? 1);
$element = $dataProvider->getElement($elementId);
$template = $_GET['template'] ?? 'list';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Компонент перелинковки — демо</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            background: #f5f6f8;
            color: #333;
            padding: 30px;
        }
        .demo-container {
            max-width: 900px;
            margin: 0 auto;
        }
        h1 {
            font-size: 22px;
            margin-bottom: 8px;
        }
        .demo-desc {
            color: #888;
            font-size: 13px;
            margin-bottom: 20px;
        }
        .demo-controls {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 24px;
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }
        .demo-controls label {
            font-size: 13px;
            color: #555;
        }
        .demo-controls select {
            padding: 6px 10px;
            border: 1px solid #d0d0d0;
            border-radius: 4px;
            font-size: 13px;
        }
        .demo-controls .btn {
            padding: 6px 14px;
            background: #2a5885;
            color: #fff;
            border: none;
            border-radius: 4px;
            font-size: 13px;
            cursor: pointer;
        }
        .demo-controls .btn:hover {
            background: #1a3a5a;
        }
        .demo-section {
            margin-bottom: 32px;
        }
        .demo-section h2 {
            font-size: 15px;
            color: #666;
            margin-bottom: 10px;
            padding-bottom: 6px;
            border-bottom: 1px solid #e0e0e0;
        }
        .demo-info {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 16px;
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
        }
        .demo-info-item {
            font-size: 13px;
            color: #555;
        }
        .demo-info-item strong {
            color: #333;
        }
        .templates-nav {
            display: flex;
            gap: 4px;
            margin-bottom: 16px;
        }
        .templates-nav a {
            padding: 5px 12px;
            font-size: 12px;
            border: 1px solid #d0d0d0;
            border-radius: 4px;
            text-decoration: none;
            color: #555;
            background: #fff;
        }
        .templates-nav a.active {
            background: #2a5885;
            color: #fff;
            border-color: #2a5885;
        }
    </style>
</head>
<body>
    <div class="demo-container">
        <h1>📎 Компонент перелинковки</h1>
        <p class="demo-desc">
            Демонстрация работы LinksComponent — выводит связанные материалы для элемента.
            Три шаблона: список, inline, карточки.
        </p>

        <!-- Панель управления -->
        <form class="demo-controls" method="GET">
            <label>Элемент:</label>
            <select name="element_id">
                <?php foreach ($dataProvider->getElements(['active' => true]) as $e): ?>
                    <option value="<?= $e['id'] ?>" <?= $e['id'] === $elementId ? 'selected' : '' ?>>
                        #<?= $e['id'] ?>: <?= htmlspecialchars($e['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn">Показать</button>
        </form>

        <?php if ($element): ?>
        <!-- Информация о текущем элементе -->
        <div class="demo-info">
            <div class="demo-info-item">
                📄 <strong><?= htmlspecialchars($element['name']) ?></strong>
            </div>
            <div class="demo-info-item">
                🏷 Код: <strong><?= htmlspecialchars($element['code'] ?? '') ?></strong>
            </div>
            <div class="demo-info-item">
                📂 Раздел: <strong>
                    <?php
                    $section = $dataProvider->getSection($element['section_id'] ?? 0);
                    echo $section ? htmlspecialchars($section['name']) : '—';
                    ?>
                </strong>
            </div>
            <div class="demo-info-item">
                📦 Инфоблок: <strong>
                    <?php
                    $iblock = $dataProvider->getIBlock($element['iblock_id'] ?? 0);
                    echo $iblock ? htmlspecialchars($iblock['name']) : '—';
                    ?>
                </strong>
            </div>
        </div>

        <!-- Навигация по шаблонам -->
        <div class="templates-nav">
            <a href="?element_id=<?= $elementId ?>&template=list" class="<?= $template === 'list' ? 'active' : '' ?>">📋 Список</a>
            <a href="?element_id=<?= $elementId ?>&template=inline" class="<?= $template === 'inline' ? 'active' : '' ?>">↔️ Inline</a>
            <a href="?element_id=<?= $elementId ?>&template=cards" class="<?= $template === 'cards' ? 'active' : '' ?>">🃏 Карточки</a>
        </div>

        <!-- Компонент: шаблон list -->
        <div class="demo-section">
            <h2>Шаблон: <?= $template ?></h2>
            <?php
            $component = new LinksComponent($dataProvider, $linker, [
                'title'       => 'Связанные материалы',
                'max_links'   => 10,
                'show_type'   => true,
                'show_weight' => true,
                'show_iblock' => true,
                'template'    => $template,
            ]);
            $component->setEntity('element', $elementId);
            echo $component->render();
            ?>
        </div>

        <!-- Сырые данные для отладки -->
        <details style="margin-top:16px;">
            <summary style="cursor:pointer;font-size:12px;color:#999;">📊 Сырые данные связей</summary>
            <pre style="background:#f0f0f0;padding:12px;border-radius:4px;font-size:11px;margin-top:8px;overflow:auto;">
<?php
$allLinks = $dataProvider->getLinks('element', $elementId);
$autoLinks = $linker->findRelevantLinks('element', $elementId, ['limit' => 10]);
echo htmlspecialchars(json_encode([
    'saved' => $allLinks,
    'auto'  => $autoLinks,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
?>
            </pre>
        </details>

        <?php else: ?>
        <p style="color:#999;">Элемент #<?= $elementId ?> не найден.</p>
        <?php endif; ?>
    </div>
</body>
</html>
