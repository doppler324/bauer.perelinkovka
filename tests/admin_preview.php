<?php
/**
 * admin_preview.php
 * 
 * Предпросмотр админки модуля перелинковки на тестовых JSON-данных.
 * Имитирует стандартный интерфейс Битрикс: CAdminFilter, CAdminList.
 * 
 * Запуск: php -S localhost:8000 -t bauer.perelinkovka/tests
 * Открыть: http://localhost:8000/admin_preview.php
 */

require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/../admin/tabs/labels.php';
// Опциональный конфиг с API-ключами (создайте config.local.php)
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
} elseif (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

use Bauer\Perelinkovka\JsonDataProvider;
use Bauer\Perelinkovka\Linker;
use Bauer\Perelinkovka\KeywordMatchingStrategy;
use Bauer\Perelinkovka\TfIdfStrategy;
use Bauer\Perelinkovka\EmbeddingStrategy;
use Bauer\Perelinkovka\OpenRouterClient;

$dataDir = __DIR__ . '/data';
\Bauer\Perelinkovka\Env::forJson($dataDir, 'asset.php?f=', 'progress_api.php');
$dataProvider = \Bauer\Perelinkovka\Env::provider();
$linker = new Linker($dataProvider);

// ---- Загрузка настроек из settings.json с дефолтами ----
$settingsFile = $dataDir . '/settings.json';
$defaultSettings = [
    'module_enabled'     => true,
    'iblock_ids'         => [1, 2, 3],
    'max_links_per_entity' => 10,
    'min_score'          => 0.3,
    'ai_provider'        => 'openrouter',
    'base_url'           => 'https://openrouter.ai/api/v1',
    'api_key'            => defined('OPENROUTER_API_KEY') ? OPENROUTER_API_KEY : '',
    'llm_model'          => 'deepseek-chat',
    'embedding_model'    => 'text-embedding-3-small',
    'strategy_weights'   => ['keyword' => 0.15, 'tfidf' => 0.25, 'embedding' => 0.35, 'llm' => 0.25],
    'strategy_enabled'   => ['keyword' => true, 'tfidf' => true, 'embedding' => false, 'llm' => false],
    'agent_enabled'      => true,
    'agent_interval'     => '6h',
    'agent_batch_size'   => 50,
    'agent_strategies'   => ['keyword', 'tfidf'],
];
$settings = $defaultSettings;
if (file_exists($settingsFile)) {
    $loaded = json_decode(file_get_contents($settingsFile), true);
    if (is_array($loaded)) {
        // Рекурсивное слияние (чтобы новые поля появлялись)
        $settings = array_replace_recursive($defaultSettings, $loaded);
    }
}

// Собираем стратегии из GET-параметров (или по умолчанию keyword + tfidf)
$useKeyword  = isset($_GET['strategy_keyword']);
$useTfidf    = isset($_GET['strategy_tfidf']);
$useEmbed    = isset($_GET['strategy_embedding']);
$useLlm      = isset($_GET['strategy_llm']);

// Если ничего не выбрано — по умолчанию keyword + tfidf
if (!$useKeyword && !$useTfidf && !$useEmbed && !$useLlm) {
    $useKeyword = true;
    $useTfidf = true;
}

$strategies = [];
if ($useKeyword) {
    $strategies[] = new KeywordMatchingStrategy(['weight' => 0.15]);
}
if ($useTfidf) {
    $strategies[] = new TfIdfStrategy($dataProvider, ['weight' => 0.25]);
}
$embeddingError = '';
if ($useEmbed) {
    $apiKey = defined('OPENROUTER_API_KEY') ? OPENROUTER_API_KEY : '';
    if (!$apiKey) {
        $embeddingError = '❌ API-ключ OpenRouter не задан. Укажите OPENROUTER_API_KEY в config.php';
    } else {
        $client = new OpenRouterClient(['api_key' => $apiKey, 'timeout' => 60]);
        try {
            if ($client->validate()) {
                $embeddingStrategy = new EmbeddingStrategy($client, ['weight' => 0.35]);
                $strategies[] = $embeddingStrategy;
                // Увеличиваем лимит времени на batch-запрос эмбеддингов
                $prevLimit = ini_get('max_execution_time');
                set_time_limit(120);
                $embeddingStrategy->preloadFromProvider($dataProvider);
                set_time_limit($prevLimit ?: 30);
            } else {
                $embeddingError = '❌ API-ключ OpenRouter невалиден или API недоступен. Проверьте ключ в config.php';
            }
        } catch (\Exception $e) {
            $embeddingError = '❌ Ошибка подключения к OpenRouter: ' . $e->getMessage();
        }
    }
}
if ($useLlm) {
    // LLM-стратегия пока не реализована
}

if (!empty($strategies)) {
    $linker->setStrategies($strategies);
}

// ---- Определяем текущую вкладку ----
$tab = $_GET['tab'] ?? 'blocks';

// ---- Загружаем данные ----
$iblocks = $dataProvider->getIBlocks();
$allLinks = [];
foreach ($dataProvider->getSections() as $s) {
    $links = $dataProvider->getLinks('section', $s['id']);
    $allLinks = array_merge($allLinks, $links);
}
foreach ($dataProvider->getElements() as $e) {
    $links = $dataProvider->getLinks('element', $e['id']);
    $allLinks = array_merge($allLinks, $links);
}

// Убираем дубликаты по id
$seenIds = [];
$uniqueLinks = [];
foreach ($allLinks as $link) {
    if (!isset($seenIds[$link['id']])) {
        $seenIds[$link['id']] = true;
        $uniqueLinks[] = $link;
    }
}
$allLinks = $uniqueLinks;

// ---- Фильтрация ----
$filterIblock = $_GET['filter_iblock'] ?? '';
$filterStrategy = $_GET['filter_strategy'] ?? '';
$filterActive = $_GET['filter_active'] ?? '';
$searchQuery = $_GET['search'] ?? '';

if ($filterIblock) {
    $allLinks = array_filter($allLinks, fn($l) => 
        $l['source_iblock_id'] == $filterIblock || $l['target_iblock_id'] == $filterIblock
    );
}
if ($filterStrategy) {
    $allLinks = array_filter($allLinks, fn($l) => ($l['strategy'] ?? 'auto') === $filterStrategy);
}
if ($filterActive !== '') {
    $allLinks = array_filter($allLinks, fn($l) => $l['active'] == $filterActive);
}
if ($searchQuery) {
    $q = mb_strtolower($searchQuery);
    $allLinks = array_filter($allLinks, function ($l) use ($q, $dataProvider) {
        $source = $l['source_type'] === 'element' 
            ? $dataProvider->getElement($l['source_id'])
            : $dataProvider->getSection($l['source_id']);
        $target = $l['target_type'] === 'element'
            ? $dataProvider->getElement($l['target_id'])
            : $dataProvider->getSection($l['target_id']);
        
        return $source && stripos($source['name'], $q) !== false
            || $target && stripos($target['name'], $q) !== false;
    });
}

// ---- Сортировка ----
$sortBy = $_GET['sort_by'] ?? 'id';
$sortOrder = $_GET['sort_order'] ?? 'asc';
usort($allLinks, function ($a, $b) use ($sortBy, $sortOrder) {
    $valA = match ($sortBy) {
        'score'    => $a['score'] ?? (($a['sort'] ?? 0) / 100),
        'strategy' => $a['strategy'] ?? 'auto',
        default    => $a[$sortBy] ?? 0,
    };
    $valB = match ($sortBy) {
        'score'    => $b['score'] ?? (($b['sort'] ?? 0) / 100),
        'strategy' => $b['strategy'] ?? 'auto',
        default    => $b[$sortBy] ?? 0,
    };
    $cmp = $valA <=> $valB;
    return $sortOrder === 'asc' ? $cmp : -$cmp;
});

$page = max(1, intval($_GET['page'] ?? 1));
$perPage = max(5, min(100, intval($_GET['per_page'] ?? 10)));
$totalLinks = count($allLinks);
$totalPages = ceil($totalLinks / $perPage);
$linksOnPage = array_slice($allLinks, ($page - 1) * $perPage, $perPage);

// ---- Обработка сохранения выбранных связей (POST) ----
$saveMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_selected']) && !empty($_POST['selected'])) {
    $savedSelected = 0;
    foreach ($_POST['selected'] as $index) {
        if (isset($_POST['link_data'][$index])) {
            $linkData = json_decode($_POST['link_data'][$index], true);
            if ($linkData && !empty($linkData['source_type'])) {
                $dataProvider->saveLink($linkData);
                $savedSelected++;
            }
        }
    }
    $params = $_GET;
    $params['saved'] = $savedSelected;
    header('Location: ?' . http_build_query($params));
    exit;
}

// ---- Сообщение о сохранении выбранных ----
$savedSelectedCount = isset($_GET['saved']) ? (int)$_GET['saved'] : 0;

// ---- Обработка сохранения настроек (POST) ----
$settingsSaved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $newSettings = $settings;
    $newSettings['module_enabled'] = !empty($_POST['module_enabled']);
    $newSettings['max_links_per_entity'] = intval($_POST['max_links'] ?? 10);
    $newSettings['min_score'] = floatval($_POST['min_score'] ?? 0.3);
    $newSettings['ai_provider'] = $_POST['ai_provider'] ?? 'openrouter';
    $newSettings['base_url'] = $_POST['base_url'] ?? 'https://openrouter.ai/api/v1';
    $newSettings['api_key'] = ($_POST['api_key'] ?? '') !== '' ? $_POST['api_key'] : $settings['api_key'];
    $newSettings['llm_model'] = $_POST['llm_model'] ?? 'deepseek-chat';
    $newSettings['embedding_model'] = $_POST['embedding_model'] ?? 'text-embedding-3-small';
    $newSettings['iblock_ids'] = array_map('intval', array_keys($_POST['iblock_ids'] ?? []));
    $newSettings['strategy_weights'] = [
        'keyword'   => floatval($_POST['weight_keyword'] ?? 0.15),
        'tfidf'     => floatval($_POST['weight_tfidf'] ?? 0.25),
        'embedding' => floatval($_POST['weight_embedding'] ?? 0.35),
        'llm'       => floatval($_POST['weight_llm'] ?? 0.25),
    ];
    $newSettings['strategy_enabled'] = [
        'keyword'   => !empty($_POST['enable_keyword']),
        'tfidf'     => !empty($_POST['enable_tfidf']),
        'embedding' => !empty($_POST['enable_embedding']),
        'llm'       => !empty($_POST['enable_llm']),
    ];
    $newSettings['agent_enabled'] = !empty($_POST['agent_enabled']);
    $newSettings['agent_interval'] = $_POST['agent_interval'] ?? '6h';
    $newSettings['agent_batch_size'] = intval($_POST['agent_batch_size'] ?? 50);
    $newSettings['agent_strategies'] = array_keys($_POST['agent_strategies'] ?? ['keyword' => '1', 'tfidf' => '1']);
    $settings = $newSettings;
    file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $settingsSaved = true;
}

// ---- AJAX-обработчик проверки API ----
if (($_GET['run'] ?? '') === 'validate_api') {
    header('Content-Type: application/json; charset=utf-8');
    $testApiKey = $_GET['api_key'] ?? $settings['api_key'];
    $testBaseUrl = $_GET['base_url'] ?? $settings['base_url'];
    if (!$testApiKey) {
        echo json_encode(['ok' => false, 'message' => 'API-ключ не указан'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $testClient = new OpenRouterClient(['api_key' => $testApiKey, 'base_url' => $testBaseUrl, 'timeout' => 10]);
        $valid = $testClient->validate();
        if ($valid) {
            echo json_encode(['ok' => true, 'message' => '✅ Подключение успешно! API-ключ валиден.'], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['ok' => false, 'message' => '❌ API вернул ошибку. Проверьте ключ и URL.'], JSON_UNESCAPED_UNICODE);
        }
    } catch (\Exception $e) {
        echo json_encode(['ok' => false, 'message' => '❌ Ошибка: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ---- AJAX-обработчик поиска по батчам ----
if ($_GET['run'] ?? '' === 'search_batch') {
    header('Content-Type: application/json; charset=utf-8');

    $batchSize = 5;
    $batch = (int)($_GET['batch'] ?? 0);
    $sourceIblockId = (int)($_GET['source_iblock'] ?? 0);
    $targetIblockId = (int)($_GET['target_iblock'] ?? 0);

    // Пересобираем стратегии
    $useKw  = isset($_GET['strategy_keyword']);
    $useTf  = isset($_GET['strategy_tfidf']);
    $useEmb = isset($_GET['strategy_embedding']);

    $batchStrategies = [];
    if ($useKw) $batchStrategies[] = new KeywordMatchingStrategy(['weight' => 0.15]);
    if ($useTf) $batchStrategies[] = new TfIdfStrategy($dataProvider, ['weight' => 0.25]);
    $embeddingError = '';
    if ($useEmb) {
        $apiKey = defined('OPENROUTER_API_KEY') ? OPENROUTER_API_KEY : '';
        if ($apiKey) {
            try {
                $client = new OpenRouterClient(['api_key' => $apiKey, 'timeout' => 60]);
                if ($client->validate()) {
                    $embStrat = new EmbeddingStrategy($client, ['weight' => 0.35]);
                    $batchStrategies[] = $embStrat;
                    if ($batch === 0) {
                        $prevLimit = ini_get('max_execution_time');
                        set_time_limit(120);
                        $embStrat->preloadFromProvider($dataProvider);
                        set_time_limit($prevLimit ?: 30);
                    }
                } else {
                    $embeddingError = '❌ API-ключ невалиден';
                }
            } catch (\Exception $e) {
                $embeddingError = '❌ Ошибка: ' . $e->getMessage();
            }
        } else {
            $embeddingError = '❌ API-ключ не задан';
        }
    }

    $batchLinker = new Linker($dataProvider);
    if (!empty($batchStrategies)) {
        $batchLinker->setStrategies($batchStrategies);
    }

    $sourceElements = $dataProvider->getElements(['iblock_id' => $sourceIblockId, 'active' => true]);
    $totalElements = count($sourceElements);
    $startIdx = $batch * $batchSize;
    $batchElements = array_slice($sourceElements, $startIdx, $batchSize);

    $batchResults = [];
    $logMessages = [];
    $seenPairs = []; // дедупликация A→B / B→A

    if ($embeddingError) {
        $logMessages[] = '⚠️ ' . $embeddingError;
    }

    foreach ($batchElements as $se) {
        try {
            $candidates = $batchLinker->findRelevantLinks('element', $se['id'], [
                'limit' => 10,
                'cross_iblock' => ($targetIblockId === 0 || $targetIblockId !== $se['iblock_id']),
            ]);
            foreach ($candidates as $c) {
                if ($targetIblockId > 0 && ($c['iblock_id'] ?? 0) !== $targetIblockId) continue;
                
                // Проверка дубликатов A→B / B→A
                $pairA = (int)$se['id'];
                $pairB = (int)$c['target_id'];
                $pairKey = $pairA < $pairB ? $pairA . '_' . $pairB : $pairB . '_' . $pairA;
                if (isset($seenPairs[$pairKey])) continue;
                $seenPairs[$pairKey] = true;
                $targetEntity = $c['target_type'] === 'element'
                    ? $dataProvider->getElement($c['target_id'])
                    : $dataProvider->getSection($c['target_id']);
                $sourceIblockName = $dataProvider->getIBlock($se['iblock_id'])['name'] ?? '';
                $targetIblockName = $dataProvider->getIBlock($targetEntity['iblock_id'] ?? 0)['name'] ?? '';
                $batchResults[] = [
                    'source'   => array_merge($se, ['iblock_name' => $sourceIblockName]),
                    'target'   => array_merge($targetEntity, ['iblock_name' => $targetIblockName]),
                    'weight'   => $c['weight'],
                    'strategy' => $c['strategy'] ?? 'keyword',
                    'type'     => $c['type'] ?? 'weak',
                ];
            }
        } catch (\Exception $e) {
            $logMessages[] = '❌ Ошибка: элемент #' . $se['id'] . ': ' . $e->getMessage();
        }
    }

    // Подсчитываем количество найденных связей для этого батча
    $logMessages[] = '✅ Батч ' . ($batch + 1) . ': обработано элементов ' . count($batchElements) . ', найдено связей ' . count($batchResults);

    $processedTotal = min($startIdx + $batchSize, $totalElements);
    $isLast = $processedTotal >= $totalElements;

    echo json_encode([
        'done'      => $isLast,
        'batch'     => $batch,
        'processed' => $processedTotal,
        'total'     => $totalElements,
        'results'   => $batchResults,
        'log'       => $logMessages,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Перелинковка — административный интерфейс</title>
    <link rel="stylesheet" href="<?= \Bauer\Perelinkovka\Env::assetUrl('base.css') ?>">
    <link rel="stylesheet" href="<?= \Bauer\Perelinkovka\Env::assetUrl('modern.css') ?>">
    <script src="<?= \Bauer\Perelinkovka\Env::assetUrl('tablekit.js') ?>"></script>
</head>
<body>

<!-- Шапка -->
<div class="bx-top-panel">
    <div class="logo">🏠 Bitrix</div>
    <div class="breadcrumbs">
        <span>Сервис</span>
        <span>›</span>
        <span>Перелинковка</span>
        <span>›</span>
        <?php
        $textTabs = ['anchors', 'anchors_mass', 'anchor_settings'];
        $section = in_array($tab, $textTabs, true) ? 'Перелинковка внутри текстов' : 'Перелинковка блоков';
        $titles = ['list' => 'Список связей', 'settings' => 'Настройки', 'stats' => 'Статистика', 'iblock' => 'Управление инфоблоком', 'anchors' => 'Анкоры и синонимы', 'anchors_mass' => 'Массовая расстановка', 'blocks' => 'Блоки и правила', 'anchor_settings' => 'Настройки'];
        ?>
        <span><?= $section ?></span>
        <span>›</span>
        <span><?= $titles[$tab] ?? '' ?></span>
    </div>
</div>

<!-- Разделы: две отдельные работы -->
<div class="bx-menu" style="background:#2c3e50;border-bottom:1px solid #1f2d3a;">
    <a href="?tab=blocks" class="<?= !in_array($tab, $textTabs, true) ? 'active' : '' ?>">🧩 Перелинковка блоков</a>
    <a href="?tab=anchors" class="<?= in_array($tab, $textTabs, true) ? 'active' : '' ?>">✍️ Перелинковка внутри текстов</a>
</div>

<!-- Меню раздела -->
<div class="bx-menu">
<?php if (in_array($tab, $textTabs, true)): ?>
    <a href="?tab=anchors" class="<?= $tab === 'anchors' ? 'active' : '' ?>">🔗 Анкоры и синонимы</a>
    <a href="?tab=anchors_mass" class="<?= $tab === 'anchors_mass' ? 'active' : '' ?>">📚 Массовая расстановка</a>
    <a href="?tab=anchor_settings" class="<?= $tab === 'anchor_settings' ? 'active' : '' ?>">⚙️ Настройки</a>
<?php else: ?>
    <a href="?tab=blocks" class="<?= $tab === 'blocks' ? 'active' : '' ?>">🧩 Блоки и правила</a>
    <a href="?tab=list" class="<?= $tab === 'list' ? 'active' : '' ?>">📋 Список связей</a>
    <a href="?tab=settings" class="<?= $tab === 'settings' ? 'active' : '' ?>">⚙️ Настройки</a>
    <a href="?tab=stats" class="<?= $tab === 'stats' ? 'active' : '' ?>">📊 Статистика</a>
<?php endif; ?>
</div>

<div class="bx-content">

<?php
// Счётчики для всех страниц
$activeCount = count(array_filter($allLinks, fn($l) => $l['active']));
$manualCount = count(array_filter($allLinks, fn($l) => $l['type'] === 'manual'));
$totalElements = count($dataProvider->getElements(['active' => true]));
$totalSections = count($dataProvider->getSections());
?>

<?php if ($tab === 'list'): ?>
    <?php require __DIR__ . '/../admin/tabs/links_tab.php'; ?>

<?php elseif ($tab === 'settings'): ?>
    <!-- ===== СТРАНИЦА НАСТРОЕК ===== -->
    <h1 class="bx-page-title">Настройки модуля</h1>

    <?php if ($settingsSaved): ?>
    <div class="bx-notification bx-notification-success">✅ Настройки сохранены</div>
    <?php endif; ?>

    <div class="bx-tabs">
        <a href="?tab=settings&settings_tab=main" class="<?= ($_GET['settings_tab'] ?? 'main') === 'main' ? 'active' : '' ?>">Основные</a>
        <a href="?tab=settings&settings_tab=provider" class="<?= ($_GET['settings_tab'] ?? '') === 'provider' ? 'active' : '' ?>">Провайдер AI</a>
        <a href="?tab=settings&settings_tab=strategies" class="<?= ($_GET['settings_tab'] ?? '') === 'strategies' ? 'active' : '' ?>">Стратегии</a>
        <a href="?tab=settings&settings_tab=schedule" class="<?= ($_GET['settings_tab'] ?? '') === 'schedule' ? 'active' : '' ?>">Расписание</a>
    </div>

    <?php $settingsTab = $_GET['settings_tab'] ?? 'main'; ?>

    <form method="POST">
    <input type="hidden" name="tab" value="settings">
    <input type="hidden" name="save_settings" value="1">

    <?php if ($settingsTab === 'main'): ?>
    <!-- Основные настройки -->
    <div class="bx-settings-section">
        <h3>Основные параметры</h3>
        <div class="bx-settings-row">
            <label>Модуль включён</label>
            <input type="checkbox" name="module_enabled" value="1" <?= $settings['module_enabled'] ? 'checked' : '' ?>>
            <span class="hint">Глобальное вкл/выкл перелинковки</span>
        </div>
        <div class="bx-settings-row">
            <label>Инфоблоки для перелинковки</label>
            <div style="display:flex;flex-direction:column;gap:4px;">
                <?php foreach ($iblocks as $ib): ?>
                    <label style="font-weight:normal;min-width:auto;">
                        <input type="checkbox" name="iblock_ids[<?= $ib['id'] ?>]" value="1" <?= in_array($ib['id'], $settings['iblock_ids']) ? 'checked' : '' ?>>
                        <?= htmlspecialchars($ib['name']) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="bx-settings-row">
            <label>Макс. связей на элемент</label>
            <input type="number" name="max_links" value="<?= $settings['max_links_per_entity'] ?>" min="1" max="50">
            <span class="hint">Сколько ссылок выводить в блоке на сайте</span>
        </div>
        <div class="bx-settings-row">
            <label>Порог релевантности (min score)</label>
            <input type="number" name="min_score" value="<?= $settings['min_score'] ?>" min="0" max="1" step="0.05">
            <span class="hint">Связи ниже порога не создаются</span>
        </div>
    </div>

    <?php elseif ($settingsTab === 'provider'): ?>
    <!-- Провайдер AI -->
    <div class="bx-settings-section">
        <h3>Подключение к OpenRouter / DeepSeek</h3>
        <div class="bx-settings-row">
            <label>Провайдер</label>
            <select name="ai_provider">
                <option value="openrouter" <?= $settings['ai_provider'] === 'openrouter' ? 'selected' : '' ?>>OpenRouter</option>
                <option value="deepseek" <?= $settings['ai_provider'] === 'deepseek' ? 'selected' : '' ?>>DeepSeek (прямое подключение)</option>
            </select>
        </div>
        <div class="bx-settings-row">
            <label>Base URL</label>
            <input type="text" name="base_url" value="<?= htmlspecialchars($settings['base_url']) ?>">
            <span class="hint">Для DeepSeek: https://api.deepseek.com</span>
        </div>
        <div class="bx-settings-row">
            <label>API Key</label>
            <input type="password" name="api_key" value="<?= htmlspecialchars($settings['api_key']) ?>" id="apiKeyInput">
            <button type="button" class="bx-btn bx-btn-sm" style="margin-left:8px;" onclick="toggleApiKey()">👁️ Показать</button>
            <span class="hint">Ключ хранится в settings.json</span>
        </div>
        <div class="bx-settings-row">
            <label></label>
            <button type="button" class="bx-btn bx-btn-primary bx-btn-sm" onclick="validateApi()">🔌 Проверить подключение</button>
            <span id="apiValidateResult"></span>
        </div>
    </div>

    <div class="bx-settings-section">
        <h3>Модели</h3>
        <div class="bx-settings-row">
            <label>Модель для LLM</label>
            <select name="llm_model">
                <option value="deepseek-chat" <?= $settings['llm_model'] === 'deepseek-chat' ? 'selected' : '' ?>>deepseek-chat</option>
                <option value="deepseek-r1" <?= $settings['llm_model'] === 'deepseek-r1' ? 'selected' : '' ?>>deepseek-r1</option>
                <option value="deepseek-r1-distill-llama-70b" <?= $settings['llm_model'] === 'deepseek-r1-distill-llama-70b' ? 'selected' : '' ?>>deepseek-r1-distill-llama-70b</option>
                <option value="gpt-4o-mini" <?= $settings['llm_model'] === 'gpt-4o-mini' ? 'selected' : '' ?>>gpt-4o-mini</option>
            </select>
            <span class="hint">Используется для LLM-анализа релевантности</span>
        </div>
        <div class="bx-settings-row">
            <label>Модель для эмбедингов</label>
            <select name="embedding_model">
                <option value="text-embedding-3-small" <?= $settings['embedding_model'] === 'text-embedding-3-small' ? 'selected' : '' ?>>text-embedding-3-small</option>
                <option value="text-embedding-ada-002" <?= $settings['embedding_model'] === 'text-embedding-ada-002' ? 'selected' : '' ?>>text-embedding-ada-002</option>
            </select>
            <span class="hint">Используется для семантического поиска</span>
        </div>
    </div>

    <?php elseif ($settingsTab === 'strategies'): ?>
    <!-- Стратегии -->
    <div class="bx-settings-section">
        <h3>Веса стратегий</h3>
        <p style="font-size:12px;color:#888;margin-bottom:12px;">Настройте вес каждой стратегии в итоговой оценке. Сумма должна равняться 1.00.</p>

        <div class="weight-row">
            <span class="name">🔤 Keyword Matching</span>
            <input type="range" name="weight_keyword" min="0" max="100" value="<?= $settings['strategy_weights']['keyword'] * 100 ?>" oninput="updateWeight(this)">
            <span class="value" id="w_1"><?= number_format($settings['strategy_weights']['keyword'], 2) ?></span>
            <input type="checkbox" name="enable_keyword" value="1" <?= $settings['strategy_enabled']['keyword'] ? 'checked' : '' ?> title="Включить">
        </div>
        <div style="margin:0 0 14px 0;padding:10px 14px;background:#f8f9fa;border-radius:4px;border-left:3px solid #27ae60;font-size:12px;color:#555;">
            <strong style="color:#27ae60;">🔤 Keyword Matching</strong> — сопоставляет элементы по прямым совпадениям ключевых слов, токенов в заголовке и описании, общему разделу и бренду.
            <span style="color:#999;">Не требует API, работает мгновенно. Не понимает синонимы и контекст.</span>
        </div>

        <div class="weight-row">
            <span class="name">📊 TF-IDF</span>
            <input type="range" name="weight_tfidf" min="0" max="100" value="<?= $settings['strategy_weights']['tfidf'] * 100 ?>" oninput="updateWeight(this)">
            <span class="value" id="w_2"><?= number_format($settings['strategy_weights']['tfidf'], 2) ?></span>
            <input type="checkbox" name="enable_tfidf" value="1" <?= $settings['strategy_enabled']['tfidf'] ? 'checked' : '' ?> title="Включить">
        </div>
        <div style="margin:0 0 14px 0;padding:10px 14px;background:#f8f9fa;border-radius:4px;border-left:3px solid #2980b9;font-size:12px;color:#555;">
            <strong style="color:#2980b9;">📊 TF-IDF</strong> — строит матрицу важности слов для каждого элемента и вычисляет косинусную близость между текстами.
            <span style="color:#999;">Чистый PHP, без внешних API. Понимает, какие слова действительно важны, а какие — шум.</span>
        </div>

        <div class="weight-row">
            <span class="name">🧠 Embedding</span>
            <input type="range" name="weight_embedding" min="0" max="100" value="<?= $settings['strategy_weights']['embedding'] * 100 ?>" oninput="updateWeight(this)">
            <span class="value" id="w_3"><?= number_format($settings['strategy_weights']['embedding'], 2) ?></span>
            <input type="checkbox" name="enable_embedding" value="1" <?= $settings['strategy_enabled']['embedding'] ? 'checked' : '' ?> title="Включить">
        </div>
        <div style="margin:0 0 14px 0;padding:10px 14px;background:#f8f9fa;border-radius:4px;border-left:3px solid #8e44ad;font-size:12px;color:#555;">
            <strong style="color:#8e44ad;">🧠 Embedding</strong> — каждый элемент превращается в вектор через OpenRouter (text-embedding-3-small). Поиск semantically similar items.
            <span style="color:#999;">Понимает смысл, находит неочевидные связи. Требует API-ключа, есть стоимость запросов.</span>
        </div>

        <div class="weight-row">
            <span class="name">🤖 LLM</span>
            <input type="range" name="weight_llm" min="0" max="100" value="<?= $settings['strategy_weights']['llm'] * 100 ?>" oninput="updateWeight(this)">
            <span class="value" id="w_4"><?= number_format($settings['strategy_weights']['llm'], 2) ?></span>
            <input type="checkbox" name="enable_llm" value="1" <?= $settings['strategy_enabled']['llm'] ? 'checked' : '' ?> title="Включить">
        </div>
        <div style="margin:0 0 14px 0;padding:10px 14px;background:#f8f9fa;border-radius:4px;border-left:3px solid #d35400;font-size:12px;color:#555;">
            <strong style="color:#d35400;">🤖 LLM</strong> — отправляет промпт DeepSeek/OpenRouter: «Насколько связаны эти товары?». Получает оценку + обоснование.
            <span style="color:#999;">Максимально точный, понимает контекст. Самый дорогой и медленный, рекомендуется только для сложных случаев.</span>
        </div>

        <div class="weight-row">
            <span class="name" style="font-weight:600;">Сумма:</span>
            <span style="font-weight:700;color:#27ae60;" id="totalWeight">1.00</span>
            <span class="total" id="weightStatus">✅ Нормы</span>
        </div>
    </div>

    <?php elseif ($settingsTab === 'schedule'): ?>
    <!-- Расписание -->
    <div class="bx-settings-section">
        <h3>Агент пересчёта</h3>
        <div class="bx-settings-row">
            <label>Агент включён</label>
            <input type="checkbox" name="agent_enabled" value="1" <?= $settings['agent_enabled'] ? 'checked' : '' ?>>
        </div>
        <div class="bx-settings-row">
            <label>Интервал</label>
            <select name="agent_interval">
                <?php $intervals = ['1h' => 'Каждый час', '6h' => 'Раз в 6 часов', '12h' => 'Раз в 12 часов', '24h' => 'Раз в сутки', '168h' => 'Раз в неделю']; ?>
                <?php foreach ($intervals as $val => $label): ?>
                    <option value="<?= $val ?>" <?= $settings['agent_interval'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="bx-settings-row">
            <label>Элементов за шаг</label>
            <input type="number" name="agent_batch_size" value="<?= $settings['agent_batch_size'] ?>" min="10" max="500">
            <span class="hint">Сколько элементов обрабатывать за один запуск агента</span>
        </div>
        <div class="bx-settings-row">
            <label>Стратегии в агенте</label>
            <div style="display:flex;flex-direction:column;gap:4px;">
                <?php $agentStrats = ['keyword' => 'Keyword Matching', 'tfidf' => 'TF-IDF', 'embedding' => 'Embedding', 'llm' => 'LLM']; ?>
                <?php foreach ($agentStrats as $val => $label): ?>
                    <label style="font-weight:normal;min-width:auto;">
                        <input type="checkbox" name="agent_strategies[<?= $val ?>]" value="1" <?= in_array($val, $settings['agent_strategies']) ? 'checked' : '' ?>>
                        <?= $label ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <span class="hint">Тяжёлые стратегии лучше использовать только в агенте, не на событиях</span>
        </div>
    </div>
    <?php endif; ?>

    <div style="margin-top:20px;display:flex;gap:8px;">
        <button type="submit" class="bx-btn bx-btn-primary">💾 Сохранить настройки</button>
        <a href="?tab=settings" class="bx-btn">↩️ Сбросить</a>
    </div>
    </form>

<?php elseif ($tab === 'iblock'): ?>
    <!-- ===== СТРАНИЦА УПРАВЛЕНИЯ ИНФОБЛОКОМ ===== -->
    <h1 class="bx-page-title">📦 Управление инфоблоком</h1>

    <?php
    $sourceIblockId = intval($_GET['source_iblock'] ?? $iblocks[0]['id'] ?? 0);
    $targetIblockId = intval($_GET['target_iblock'] ?? 0);
    $sourceIblock = $dataProvider->getIBlock($sourceIblockId);
    $targetIblock = $targetIblockId ? $dataProvider->getIBlock($targetIblockId) : null;

    $sourceElements = $dataProvider->getElements(['iblock_id' => $sourceIblockId, 'active' => true]);
    $sourceSections = $dataProvider->getSections(['iblock_id' => $sourceIblockId]);

    $elementsWithLinks = 0;
    foreach ($sourceElements as $e) {
        $links = $dataProvider->getLinks('element', $e['id']);
        if (!empty($links)) $elementsWithLinks++;
    }

    $showResults = isset($_GET['run']) && $_GET['run'] === 'search';
    ?>

    <form id="iblockForm" method="GET">
    <input type="hidden" name="tab" value="iblock">

    <!-- Форма выбора -->
    <div class="bx-settings-section">
        <div class="bx-settings-row">
            <label style="font-weight:600;min-width:120px;">📥 Источник</label>
            <select name="source_iblock" onchange="this.form.submit()">
                <?php foreach ($iblocks as $ib): ?>
                    <option value="<?= $ib['id'] ?>" <?= $sourceIblockId === $ib['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($ib['name']) ?> (<?= count($dataProvider->getElements(['iblock_id' => $ib['id']])) ?> эл.)
                    </option>
                <?php endforeach; ?>
            </select>
            <span class="hint">Из этого инфоблока ищем связи</span>
        </div>
        <div class="bx-settings-row">
            <label style="font-weight:600;min-width:120px;">🎯 Цель</label>
            <select name="target_iblock" onchange="this.form.submit()">
                <option value="0">— Все инфоблоки —</option>
                <?php foreach ($iblocks as $ib): ?>
                    <option value="<?= $ib['id'] ?>" <?= $targetIblockId === $ib['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($ib['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span class="hint">В каком инфоблоке ищем. "Все" = кросс-линковка</span>
        </div>
    </div>

    <div class="bx-settings-section">
        <h3>🚀 Запуск перелинковки</h3>
        <div class="bx-settings-row">
            <label>Стратегии</label>
            <div style="display:flex;gap:16px;flex-wrap:wrap;">
                <label style="font-weight:normal;min-width:auto;"><input type="checkbox" name="strategy_keyword" value="1" checked> Keyword Matching</label>
                <label style="font-weight:normal;min-width:auto;"><input type="checkbox" name="strategy_tfidf" value="1" checked> TF-IDF</label>
                <label style="font-weight:normal;min-width:auto;"><input type="checkbox" name="strategy_embedding" value="1"> Embedding</label>
                <label style="font-weight:normal;min-width:auto;"><input type="checkbox" name="strategy_llm" value="1"> LLM</label>
            </div>
        </div>
        <div class="bx-settings-row" style="gap:12px;margin-top:8px;">
            <button type="button" onclick="startAjaxSearch()" class="bx-btn bx-btn-primary">🔍 Найти связи (тест)</button>
            <button type="submit" name="run" value="save" class="bx-btn bx-btn-success" onclick="return confirm('Создать связи и сохранить?')">💾 Создать и сохранить</button>
        </div>
    </div>
    </form>

    <!-- Контейнер прогресса (AJAX) -->
    <div class="progress-container" id="progressContainer">
        <h3>⏳ Поиск связей...</h3>
        <div class="progress-bar-outer">
            <div class="progress-bar-inner" id="progressBarInner">0%</div>
        </div>
        <div class="progress-status" id="progressStatus">Подготовка...</div>
        <div class="progress-log" id="progressLog"></div>
    </div>

    <!-- Контейнер для результатов AJAX -->
    <div id="ajaxResultsContainer"></div>

    <?php
    // Считаем существующие связи для выбранного инфоблока-источника
    $sourceIblockLinks = 0;
    foreach ($sourceElements as $se) {
        $sourceIblockLinks += count($dataProvider->getLinks('element', $se['id']));
    }
    ?>
    <!-- Статистика по инфоблоку -->
    <p style="font-size:12px;color:#888;margin:-8px 0 12px 0;">Статистика для выбранного инфоблока-источника "<?= htmlspecialchars($sourceIblock['name'] ?? '') ?>"</p>
    <div class="bx-stats">
        <div class="bx-stat-card">
            <div class="value"><?= count($sourceElements) ?></div>
            <div class="label">Элементов в источнике</div>
        </div>
        <div class="bx-stat-card">
            <div class="value"><?= $sourceIblockLinks ?></div>
            <div class="label">Существующих связей</div>
        </div>
        <div class="bx-stat-card">
            <div class="value"><?= $elementsWithLinks ?></div>
            <div class="label">Элементов со связями</div>
        </div>
        <div class="bx-stat-card">
            <div class="value"><?= count($sourceElements) - $elementsWithLinks ?></div>
            <div class="label">Без связей</div>
        </div>
    </div>

    <div style="display:flex;gap:8px;margin-bottom:16px;">
        <button class="bx-btn bx-btn-danger" onclick="if(confirm('Очистить все авто-связи этого инфоблока?'))alert('Очищено (демо)')">🗑️ Очистить все связи</button>
        <button class="bx-btn" onclick="alert('Экспортировано (демо)')">📋 Экспорт CSV</button>
    </div>

    <?php if ($embeddingError): ?>
    <div class="bx-settings-section" style="border-left:4px solid #e74c3c;margin-bottom:16px;">
        <h3 style="color:#e74c3c;">⚠️ Embedding не подключён</h3>
        <p style="font-size:13px;color:#555;"><?= htmlspecialchars($embeddingError) ?></p>
    </div>
    <?php endif; ?>

    <?php if ($showResults):
    // Реальные результаты поиска через Linker
    $linkerResults = [];
    $processedCount = 0;
    $totalToProcess = count($sourceElements);
    $seenPairs = []; // дедупликация A→B / B→A

    // Если run=save — сохраняем найденные связи
    $saveMode = ($_GET['run'] ?? '') === 'save';
    $savedCount = 0;

    foreach ($sourceElements as $se) {
        // Настройки для Linker: limit=10 результатов на элемент
        // cross_iblock=true если "Все инфоблоки" (0) или выбран другой инфоблок
        $candidates = $linker->findRelevantLinks('element', $se['id'], [
            'limit' => 10,
            'cross_iblock' => ($targetIblockId === 0 || $targetIblockId !== $se['iblock_id']),
        ]);

        foreach ($candidates as $c) {
            // Фильтр по целевому инфоблоку, если выбран конкретный
            if ($targetIblockId > 0 && $c['iblock_id'] !== $targetIblockId) {
                continue;
            }
            
            // Дедупликация A→B / B→A
            $pairA = (int)$se['id'];
            $pairB = (int)$c['target_id'];
            $pairKey = $pairA < $pairB ? $pairA . '_' . $pairB : $pairB . '_' . $pairA;
            if (isset($seenPairs[$pairKey])) continue;
            $seenPairs[$pairKey] = true;

            $targetEntity = $c['target_type'] === 'element'
                ? $dataProvider->getElement($c['target_id'])
                : $dataProvider->getSection($c['target_id']);

            $linkerResults[] = [
                'source'     => $se,
                'target'     => $targetEntity,
                'weight'     => $c['weight'],
                'strategy'   => $c['strategy'] ?? 'keyword',
                'type'       => $c['type'] ?? 'weak',
            ];

            // В режиме сохранения — реально сохраняем
            if ($saveMode && $targetEntity) {
                $dataProvider->saveLink([
                    'source_type'      => 'element',
                    'source_id'        => $se['id'],
                    'source_iblock_id' => $se['iblock_id'],
                    'target_type'      => 'element',
                    'target_id'        => $c['target_id'],
                    'target_iblock_id' => $c['iblock_id'],
                    'type'             => $c['type'],
                    'strategy'         => $c['strategy'] ?? 'auto',
                    'score'            => round($c['weight'], 4),
                    'sort'             => (int)round($c['weight'] * 100),
                    'active'           => true,
                    'created_at'       => date('Y-m-d H:i:s'),
                ]);
                $savedCount++;
            }
        }
        $processedCount++;
    }

    // Сортируем по весу (высшие — первые)
    usort($linkerResults, fn($a, $b) => $b['weight'] <=> $a['weight']);

    // Для отображения показываем топ-50
    $displayResults = array_slice($linkerResults, 0, 50);
    $totalFound = count($linkerResults);
    $displayCount = count($displayResults);

    // Если режим сохранения — показываем сообщение
    if ($saveMode):
    ?>
    <div class="bx-settings-section" style="border-left:4px solid #27ae60;margin-bottom:16px;">
        <h3 style="color:#27ae60;">✅ Сохранено</h3>
        <p style="font-size:13px;color:#555;">
            Создано <strong><?= $savedCount ?></strong> новых связей для инфоблока "<?= htmlspecialchars($sourceIblock['name'] ?? '') ?>".
            <a href="?tab=iblock&source_iblock=<?= $sourceIblockId ?>&target_iblock=<?= $targetIblockId ?>">🔄 Обновить страницу</a>
        </p>
    </div>
    <?php endif; ?>

    <!-- Прогресс -->
    <div class="bx-settings-section" style="margin-bottom:16px;">
        <h3>⏳ Прогресс</h3>
        <?php $progressPct = $totalToProcess > 0 ? round($processedCount / $totalToProcess * 100) : 100; ?>
        <div style="background:#ecf0f1;height:20px;border-radius:10px;overflow:hidden;margin-bottom:8px;">
            <div style="height:100%;width:<?= $progressPct ?>%;background:linear-gradient(90deg,#3498db,#27ae60);border-radius:10px;transition:width 0.5s;"></div>
        </div>
        <div style="font-size:12px;color:#666;display:flex;justify-content:space-between;">
            <span>Обработано: <?= $processedCount ?> из <?= $totalToProcess ?> элементов</span>
            <span>Найдено связей: <?= $totalFound ?></span>
        </div>
    </div>

    <?php
    // Анализ покрытия: сколько элементов имеют хотя бы одну связь
    $elementsWithLinks = 0;
    $coveredIds = [];
    foreach ($linkerResults as $lr) {
        $sid = $lr['source']['id'] ?? 0;
        if ($sid && !isset($coveredIds[$sid]) && $lr['weight'] > 0.05) {
            $coveredIds[$sid] = true;
            $elementsWithLinks++;
        }
    }
    $coveragePct = $totalToProcess > 0 ? round($elementsWithLinks / $totalToProcess * 100) : 0;
    $embeddingAvailable = defined('OPENROUTER_API_KEY') && OPENROUTER_API_KEY;
    $useEmbed = isset($_GET['strategy_embedding']);
    $needsEmbedding = $coveragePct < 50 && !$useEmbed && $embeddingAvailable;
    ?>
    <!-- Покрытие и рекомендации -->
    <div class="bx-settings-section" style="margin-bottom:16px;<?= $needsEmbedding ? 'border-left:4px solid #ff9800;' : '' ?>">
        <h3>📊 Покрытие</h3>
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <div style="background:#ecf0f1;height:12px;width:200px;border-radius:6px;overflow:hidden;flex-shrink:0;">
                <div style="height:100%;width:<?= $coveragePct ?>%;background:<?= $coveragePct >= 50 ? '#27ae60' : ($coveragePct >= 25 ? '#f39c12' : '#e74c3c') ?>;border-radius:6px;"></div>
            </div>
            <span style="font-size:13px;font-weight:600;color:#555;">
                <?= $elementsWithLinks ?> из <?= $totalToProcess ?> элементов имеют связи (<?= $coveragePct ?>%)
            </span>
        </div>
        <?php if ($needsEmbedding): ?>
        <div style="margin-top:8px;padding:8px 12px;background:#fff8e1;border-radius:4px;font-size:12px;color:#e65100;display:flex;align-items:center;gap:8px;">
            <span style="font-size:16px;">💡</span>
            <span>
                Покрытие всего <strong><?= $coveragePct ?>%</strong>. 
                Рекомендуется включить <strong>Embedding</strong> для поиска семантических связей.
                <a href="?tab=iblock&source_iblock=<?= $sourceIblockId ?>&target_iblock=<?= $targetIblockId ?>&run=search&strategy_keyword=1&strategy_tfidf=1&strategy_embedding=1" style="color:#2a5885;text-decoration:underline;">🔄 Попробовать с Embedding</a>
            </span>
        </div>
        <?php elseif ($coveragePct >= 50 && !$useEmbed): ?>
        <div style="margin-top:8px;font-size:12px;color:#888;">
            ✅ Покрытие достаточное. При необходимости можно добавить Embedding для более точных связей.
        </div>
        <?php endif; ?>
    </div>

    <!-- Сообщение о сохранении выбранных -->
    <?php if ($savedSelectedCount > 0): ?>
    <div class="bx-settings-section" style="border-left:4px solid #27ae60;margin-bottom:16px;">
        <h3 style="color:#27ae60;">✅ Сохранено</h3>
        <p style="font-size:13px;color:#555;">
            Сохранено <strong><?= $savedSelectedCount ?></strong> выбранных связей.
            <a href="?tab=iblock&source_iblock=<?= $sourceIblockId ?>&target_iblock=<?= $targetIblockId ?>">🔄 Обновить страницу</a>
        </p>
    </div>
    <?php endif; ?>

    <!-- Фильтр по типу связи -->
    <div style="margin-bottom:8px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:8px 12px;background:#f8f9fa;border-radius:4px;border:1px solid #dee2e6;">
        <span style="font-size:12px;font-weight:600;color:#555;">🔍 Фильтр по типу:</span>
        <label style="font-weight:normal;font-size:12px;display:flex;align-items:center;gap:4px;cursor:pointer;">
            <input type="checkbox" class="type-filter" data-type="strong" checked onchange="filterByType()">
            <span class="badge badge-strong" style="font-size:11px;padding:1px 8px;">сильная</span>
        </label>
        <label style="font-weight:normal;font-size:12px;display:flex;align-items:center;gap:4px;cursor:pointer;">
            <input type="checkbox" class="type-filter" data-type="medium" checked onchange="filterByType()">
            <span class="badge badge-medium" style="font-size:11px;padding:1px 8px;">средняя</span>
        </label>
        <label style="font-weight:normal;font-size:12px;display:flex;align-items:center;gap:4px;cursor:pointer;">
            <input type="checkbox" class="type-filter" data-type="weak" checked onchange="filterByType()">
            <span class="badge badge-weak" style="font-size:11px;padding:1px 8px;">слабая</span>
        </label>
        <span style="color:#ccc;">|</span>
        <span style="font-size:11px;color:#888;" id="filterCount">показано: <?= $displayCount ?> из <?= $totalFound ?></span>
    </div>

    <!-- Результаты поиска с формой для выборочного сохранения -->
    <form method="POST" id="saveSelectedForm">
    <input type="hidden" name="save_selected" value="1">
    <div class="bx-table-wrap">
        <div class="bx-table-toolbar">
            <div class="left">
                <span style="font-size:12px;color:#666;">Найдено: <strong><?= $totalFound ?> связей</strong> (показано <?= $displayCount ?>)</span>
            </div>
            <div class="right">
                <button type="submit" class="bx-btn bx-btn-success bx-btn-sm" onclick="return confirm('Сохранить выбранные связи?')">💾 Сохранить выбранные</button>
            </div>
        </div>
        <table class="bx-table">
            <thead>
                <tr>
                    <th style="width:30px;"><input type="checkbox" class="select-all" onchange="toggleAll(this)"></th>
                    <th>Вес</th>
                    <th>Источник</th>
                    <th>Цель</th>
                    <th>Стратегия</th>
                    <th>Тип</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($displayResults as $i => $dr):
                    $target = $dr['target'];
                    if (!$target) continue;
                    $isCross = ($dr['source']['iblock_id'] ?? 0) !== ($target['iblock_id'] ?? 0);
                    // Берём тип связи из результата Linker
                    $linkType = false
                        ? ($dr['weight'] >= 5 ? 'strong' : ($dr['weight'] >= 3 ? 'medium' : 'weak'))
                        : ($dr['type'] ?? 'weak');
                    // Подготовка данных для сохранения
                    $linkSaveData = [
                        'source_type'      => 'element',
                        'source_id'        => $dr['source']['id'],
                        'source_iblock_id' => $dr['source']['iblock_id'],
                        'target_type'      => 'element',
                        'target_id'        => $target['id'],
                        'target_iblock_id' => $target['iblock_id'],
                        'type'             => $linkType,
                        'active'           => true,
                        'created_at'       => date('Y-m-d H:i:s'),
                    ];
                ?>
                <tr data-link-type="<?= $linkType ?>">
                    <td>
                        <input type="checkbox" class="row-checkbox" name="selected[]" value="<?= $i ?>" checked>
                        <input type="hidden" name="link_data[<?= $i ?>]" value='<?= htmlspecialchars(json_encode($linkSaveData), ENT_QUOTES) ?>'>
                    </td>
                    <td><strong><?= number_format($dr['weight'], 2) ?></strong></td>
                    <td>
                        <span class="entity-type type-elem">ЭЛ</span>
                        #<?= $dr['source']['id'] ?>: <?= htmlspecialchars($dr['source']['name']) ?>
                        <?php if ($isCross): ?><span style="font-size:10px;color:#888;">(<?= $dataProvider->getIBlock($dr['source']['iblock_id'])['name'] ?? '' ?>)</span><?php endif; ?>
                    </td>
                    <td>
                        <span class="entity-type type-elem">ЭЛ</span>
                        #<?= $target['id'] ?>: <?= htmlspecialchars($target['name']) ?>
                        <?php if ($isCross): ?><span style="font-size:10px;color:#888;">(<?= $dataProvider->getIBlock($target['iblock_id'])['name'] ?? '' ?>)</span><?php endif; ?>
                    </td>
                    <td><span class="badge badge-<?= $dr['strategy'] ?>"><?= ru_strategy($dr['strategy']) ?></span></td>
                    <td><span class="badge badge-<?= $linkType ?>"><?= ['strong' => 'сильная', 'medium' => 'средняя', 'weak' => 'слабая'][$linkType] ?? $linkType ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($displayResults)): ?>
                <tr>
                    <td colspan="6" style="text-align:center;padding:30px;color:#888;">
                        🤷‍♂️ Связей не найдено. Попробуйте выбрать другие стратегии или инфоблоки.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        <div class="bx-pagination">
            <div class="info" style="display:flex;align-items:center;gap:12px;">
                <span>Показано <?= $displayCount ?> из <?= $totalFound ?> найденных связей</span>
                <span style="color:#ccc;">|</span>
                <span>На странице:
                    <select onchange="alert('Выбрано: '+this.value+' (демо)')" style="padding:2px 6px;border:1px solid #dce1e6;border-radius:3px;">
                        <?php foreach ([5, 10, 20, 50] as $n): ?>
                            <option value="<?= $n ?>" <?= $n == 10 ? 'selected' : '' ?>><?= $n ?></option>
                        <?php endforeach; ?>
                    </select>
                </span>
            </div>
            <div class="pages">
                <span class="current">1</span>
                <a href="#">2</a>
                <a href="#">3</a>
            </div>
        </div>
    </div>
    </form>
    <?php else: ?>
    <!-- Подсказка -->
    <div class="bx-notification bx-notification-info">
        🔍 Нажмите <strong>"Найти связи (тест)"</strong>, чтобы увидеть, какие связи будут созданы
    </div>
    <?php endif; ?>

<?php elseif ($tab === 'blocks'): ?>
    <?php require __DIR__ . '/../admin/tabs/blocks_tab.php'; ?>

<?php elseif ($tab === 'anchors'): ?>
    <?php require __DIR__ . '/../admin/tabs/anchors_tab.php'; ?>

<?php elseif ($tab === 'anchors_mass'): ?>
    <?php require __DIR__ . '/../admin/tabs/anchors_mass_tab.php'; ?>

<?php elseif ($tab === 'anchor_settings'): ?>
    <?php require __DIR__ . '/../admin/tabs/anchor_settings_tab.php'; ?>

<?php elseif ($tab === 'stats'): ?>
    <?php require __DIR__ . '/../admin/tabs/stats_tab.php'; ?>

<?php endif; ?>

</div>

<!-- Модалка "Добавить связь" -->
<div class="modal-overlay" id="addLinkModal">
    <div class="modal">
        <div class="modal-header">➕ Добавление ручной связи</div>
        <div class="modal-body">
            <div class="field-group">
                <label>Тип источника</label>
                <select>
                    <option>Элемент</option>
                    <option>Раздел</option>
                </select>
            </div>
            <div class="field-group">
                <label>ID или название источника</label>
                <div class="field-row">
                    <input type="text" placeholder="ID или часть названия..." id="sourceSearch" oninput="searchEntity(this.value, 'sourceResult')">
                    <button class="bx-btn bx-btn-sm">🔍 Найти</button>
                </div>
                <div class="search-result" id="sourceResult">Начните вводить название для поиска...</div>
            </div>
            <div class="field-group">
                <label>Тип цели</label>
                <select>
                    <option>Элемент</option>
                    <option>Раздел</option>
                </select>
            </div>
            <div class="field-group">
                <label>ID или название цели</label>
                <div class="field-row">
                    <input type="text" placeholder="ID или часть названия...">
                    <button class="bx-btn bx-btn-sm">🔍 Найти</button>
                </div>
                <div class="search-result" id="targetResult">Начните вводить название для поиска...</div>
            </div>
            <div class="field-group">
                <label>Тип связи</label>
                <select>
                    <option>related — связанный товар</option>
                    <option>accessory — аксессуар</option>
                    <option>section — раздел</option>
                </select>
            </div>
            <div class="field-group">
                <label>Сортировка</label>
                <input type="number" value="500" style="width:100px;">
            </div>
        </div>
        <div class="modal-footer">
            <button class="bx-btn" onclick="closeModal()">Отмена</button>
            <button class="bx-btn bx-btn-success" onclick="closeModal();alert('Связь добавлена (демо) ✅')">💾 Сохранить</button>
        </div>
    </div>
</div>

<script>
function toggleFilter() {
    const body = document.getElementById('filterBody');
    const arrow = document.getElementById('filterArrow');
    body.classList.toggle('open');
    arrow.textContent = body.classList.contains('open') ? '▲' : '▼';
}

function toggleAll(checkbox) {
    document.querySelectorAll('.row-checkbox').forEach(cb => {
        // Отмечаем только видимые строки
        const row = cb.closest('tr');
        if (!row || row.style.display !== 'none') {
            cb.checked = checkbox.checked;
        }
    });
}

function openModal() {
    document.getElementById('addLinkModal').classList.add('open');
}

function closeModal() {
    document.getElementById('addLinkModal').classList.remove('open');
}

function updateWeight(slider) {
    const val = (parseInt(slider.value) / 100).toFixed(2);
    const id = slider.parentElement.querySelector('.value').id;
    document.getElementById(id).textContent = val;
    
    // Пересчёт суммы
    const weights = document.querySelectorAll('.weight-row .value');
    let total = 0;
    weights.forEach(w => total += parseFloat(w.textContent));
    document.getElementById('totalWeight').textContent = total.toFixed(2);
    document.getElementById('weightStatus').textContent = 
        Math.abs(total - 1.0) < 0.01 ? '✅ Нормы' : `⚠️ Сумма ${total.toFixed(2)}, нужно 1.00`;
    document.getElementById('weightStatus').style.color = 
        Math.abs(total - 1.0) < 0.01 ? '#27ae60' : '#e67e22';
}

// Фильтрация строк таблицы по типу связи
function filterByType() {
    const checkboxes = document.querySelectorAll('.type-filter');
    const activeTypes = {};
    checkboxes.forEach(cb => {
        activeTypes[cb.dataset.type] = cb.checked;
    });

    let visibleCount = 0;
    document.querySelectorAll('.bx-table tbody tr[data-link-type]').forEach(row => {
        const type = row.dataset.linkType;
        const isVisible = activeTypes[type] !== false;
        row.style.display = isVisible ? '' : 'none';
        if (isVisible) visibleCount++;
    });

    // Обновляем счётчик
    const totalEl = document.getElementById('filterCount');
    if (totalEl) {
        const total = document.querySelectorAll('.bx-table tbody tr[data-link-type]').length;
        totalEl.textContent = 'показано: ' + visibleCount + ' из ' + total;
    }
}

function searchEntity(query, resultId) {
    const el = document.getElementById(resultId);
    if (query.length < 2) {
        el.textContent = 'Начните вводить название для поиска...';
        return;
    }
    el.textContent = '🔍 Поиск: "' + query + '"...'; // В реальности AJAX к CIBlockElement::GetList
}

// ===== AJAX-поиск с прогрессом =====
let ajaxAllResults = [];
let ajaxCurrentBatch = 0;
const BATCH_SIZE = 5;

function addLog(msg, type) {
    const log = document.getElementById('progressLog');
    if (!log) return;
    const div = document.createElement('div');
    div.className = 'log-' + (type || 'info');
    div.textContent = '> ' + msg;
    log.appendChild(div);
    log.scrollTop = log.scrollHeight;
}

function updateProgress(processed, total, logMessages) {
    const pct = Math.round((processed / total) * 100);
    const bar = document.getElementById('progressBarInner');
    if (bar) {
        bar.style.width = pct + '%';
        bar.textContent = pct + '%';
    }
    const status = document.getElementById('progressStatus');
    if (status) {
        status.textContent = 'Обработано: ' + processed + ' из ' + total + ' элементов';
    }
    if (logMessages && logMessages.length) {
        logMessages.forEach(msg => {
            if (msg.includes('⚠️')) addLog(msg, 'warning');
            else if (msg.includes('❌')) addLog(msg, 'error');
            else if (msg.includes('✅')) addLog(msg, 'success');
            else if (msg.includes('Embedding') || msg.includes('эмбед')) addLog(msg, 'embedding');
            else addLog(msg, 'info');
        });
    }
}

function renderAjaxResults(results) {
    const container = document.getElementById('ajaxResultsContainer');
    if (!container) return;

    // Сортируем по весу
    results.sort((a, b) => b.weight - a.weight);
    const top50 = results.slice(0, 50);

    // Считаем покрытие
    const covered = {};
    results.forEach(r => {
        const sid = (r.source && r.source.id) || 0;
        if (sid && r.weight > 0.05) covered[sid] = true;
    });
    const totalElements = results.length > 0 ? new Set(results.map(r => r.source && r.source.id)).size : 0;
    const coveredCount = Object.keys(covered).length;
    const coveragePct = totalElements > 0 ? Math.round((coveredCount / totalElements) * 100) : 0;

    let html = '';

    // Покрытие
    html += '<div class="bx-settings-section" style="margin-bottom:16px;">';
    html += '<h3>📊 Покрытие</h3>';
    html += '<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">';
    html += '<div style="background:#ecf0f1;height:12px;width:200px;border-radius:6px;overflow:hidden;flex-shrink:0;">';
    const barColor = coveragePct >= 50 ? '#27ae60' : (coveragePct >= 25 ? '#f39c12' : '#e74c3c');
    html += '<div style="height:100%;width:' + coveragePct + '%;background:' + barColor + ';border-radius:6px;"></div></div>';
    html += '<span style="font-size:13px;font-weight:600;color:#555;">' + coveredCount + ' из ' + totalElements + ' элементов имеют связи (' + coveragePct + '%)</span>';
    html += '</div></div>';

    // Фильтр по типу
    html += '<div style="margin-bottom:8px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:8px 12px;background:#f8f9fa;border-radius:4px;border:1px solid #dee2e6;">';
    html += '<span style="font-size:12px;font-weight:600;color:#555;">🔍 Фильтр по типу:</span>';
    html += '<label style="font-weight:normal;font-size:12px;display:flex;align-items:center;gap:4px;cursor:pointer;"><input type="checkbox" class="type-filter" data-type="strong" checked onchange="filterByType()"><span class="badge badge-strong" style="font-size:11px;padding:1px 8px;">сильная</span></label>';
    html += '<label style="font-weight:normal;font-size:12px;display:flex;align-items:center;gap:4px;cursor:pointer;"><input type="checkbox" class="type-filter" data-type="medium" checked onchange="filterByType()"><span class="badge badge-medium" style="font-size:11px;padding:1px 8px;">средняя</span></label>';
    html += '<label style="font-weight:normal;font-size:12px;display:flex;align-items:center;gap:4px;cursor:pointer;"><input type="checkbox" class="type-filter" data-type="weak" checked onchange="filterByType()"><span class="badge badge-weak" style="font-size:11px;padding:1px 8px;">слабая</span></label>';
    html += '<span style="color:#ccc;">|</span>';
    html += '<span style="font-size:11px;color:#888;" id="filterCount">показано: ' + top50.length + ' из ' + results.length + '</span>';
    html += '</div>';

    // Форма с таблицей
    html += '<form method="POST" id="ajaxSaveForm">';
    html += '<input type="hidden" name="save_selected" value="1">';
    html += '<div class="bx-table-wrap">';
    html += '<div class="bx-table-toolbar">';
    html += '<div class="left"><span style="font-size:12px;color:#666;">Найдено: <strong>' + results.length + ' связей</strong> (показано ' + top50.length + ')</span></div>';
    html += '<div class="right"><button type="submit" class="bx-btn bx-btn-success bx-btn-sm" onclick="return confirm(\'Сохранить выбранные связи?\')">💾 Сохранить выбранные</button></div>';
    html += '</div>';
    html += '<table class="bx-table"><thead><tr>';
    html += '<th style="width:30px;"><input type="checkbox" class="select-all" onchange="toggleAll(this)"></th>';
    html += '<th>Вес</th><th>Источник</th><th>Цель</th><th>Стратегия</th><th>Тип</th>';
    html += '</tr></thead><tbody>';

    top50.forEach(function(dr, i) {
        if (!dr.target) return;
        const isCross = dr.source && dr.target && (dr.source.iblock_id || 0) !== (dr.target.iblock_id || 0);
        let linkType = dr.strategy === 'keyword' && dr.weight > 1
            ? (dr.weight >= 5 ? 'strong' : (dr.weight >= 3 ? 'medium' : 'weak'))
            : (dr.type || 'weak');
        const typeLabel = { strong: 'сильная', medium: 'средняя', weak: 'слабая' }[linkType] || linkType;

        const linkData = {
            source_type: 'element',
            source_id: dr.source.id,
            source_iblock_id: dr.source.iblock_id,
            target_type: 'element',
            target_id: dr.target.id,
            target_iblock_id: dr.target.iblock_id,
            type: linkType,
            active: true,
            created_at: new Date().toISOString().slice(0, 19).replace('T', ' ')
        };

        html += '<tr data-link-type="' + linkType + '">';
        html += '<td><input type="checkbox" class="row-checkbox" name="selected[]" value="' + i + '" checked>';
        html += '<input type="hidden" name="link_data[' + i + ']" value=\'' + JSON.stringify(linkData).replace(/'/g, '&#39;') + '\'></td>';
        html += '<td><strong>' + dr.weight.toFixed(2) + '</strong></td>';
        html += '<td><span class="entity-type type-elem">ЭЛ</span> #' + dr.source.id + ': ' + escHtml(dr.source.name || '');
        if (isCross && dr.source.iblock_id) html += ' <span style="font-size:10px;color:#888;">(' + (dr.source.iblock_name || '') + ')</span>';
        html += '</td>';
        html += '<td><span class="entity-type type-elem">ЭЛ</span> #' + dr.target.id + ': ' + escHtml(dr.target.name || '');
        if (isCross && dr.target.iblock_id) html += ' <span style="font-size:10px;color:#888;">(' + (dr.target.iblock_name || '') + ')</span>';
        html += '</td>';
        html += '<td><span class="badge badge-' + dr.strategy + '">' + dr.strategy + '</span></td>';
        html += '<td><span class="badge badge-' + linkType + '">' + typeLabel + '</span></td>';
        html += '</tr>';
    });

    if (top50.length === 0) {
        html += '<tr><td colspan="6" style="text-align:center;padding:30px;color:#888;">🤷‍♂️ Связей не найдено</td></tr>';
    }

    html += '</tbody></table></div></form>';

    container.innerHTML = html;
    container.style.display = 'block';
}

function escHtml(str) {
    const div = document.createElement('div');
    div.textContent = str || '';
    return div.innerHTML;
}

function startAjaxSearch() {
    // Собираем параметры из формы
    const form = document.getElementById('iblockForm');
    if (!form) return;

    const sourceIblock = form.querySelector('[name="source_iblock"]').value;
    const targetIblock = form.querySelector('[name="target_iblock"]').value;
    const kw = form.querySelector('[name="strategy_keyword"]').checked ? '1' : '';
    const tf = form.querySelector('[name="strategy_tfidf"]').checked ? '1' : '';
    const emb = form.querySelector('[name="strategy_embedding"]').checked ? '1' : '';
    const llm = form.querySelector('[name="strategy_llm"]').checked ? '1' : '';

    // Показываем контейнер прогресса
    const container = document.getElementById('progressContainer');
    if (container) container.classList.add('active');

    // Очищаем лог
    const log = document.getElementById('progressLog');
    if (log) log.innerHTML = '';

    // Очищаем старые результаты
    const resultsContainer = document.getElementById('ajaxResultsContainer');
    if (resultsContainer) {
        resultsContainer.innerHTML = '';
        resultsContainer.style.display = 'none';
    }

    ajaxAllResults = [];
    ajaxCurrentBatch = 0;

    addLog('🚀 Запуск поиска связей...', 'info');
    if (emb) addLog('🧠 Embedding включён — может занять до 60 сек на предзагрузку...', 'embedding');

    processNextBatch(sourceIblock, targetIblock, kw, tf, emb, llm);
}

function processNextBatch(sourceIblock, targetIblock, kw, tf, emb, llm) {
    let url = 'admin_preview.php?run=search_batch&batch=' + ajaxCurrentBatch + '&source_iblock=' + sourceIblock + '&target_iblock=' + targetIblock;
    if (kw) url += '&strategy_keyword=1';
    if (tf) url += '&strategy_tfidf=1';
    if (emb) url += '&strategy_embedding=1';
    if (llm) url += '&strategy_llm=1';

    fetch(url)
        .then(response => response.json())
        .then(data => {
            // Добавляем результаты
            if (data.results && data.results.length) {
                ajaxAllResults = ajaxAllResults.concat(data.results);
            }

            // Обновляем прогресс
            updateProgress(data.processed, data.total, data.log);

            if (data.done) {
                addLog('✅ Поиск завершён! Найдено связей: ' + ajaxAllResults.length, 'success');
                // Задержка чтобы пользователь увидел 100%
                setTimeout(function() {
                    const container = document.getElementById('progressContainer');
                    if (container) container.classList.remove('active');
                    renderAjaxResults(ajaxAllResults);
                }, 800);
            } else {
                ajaxCurrentBatch++;
                processNextBatch(sourceIblock, targetIblock, kw, tf, emb, llm);
            }
        })
        .catch(error => {
            addLog('❌ Ошибка AJAX-запроса: ' + error.message, 'error');
        });
}

function toggleApiKey() {
    const input = document.getElementById('apiKeyInput');
    input.type = input.type === 'password' ? 'text' : 'password';
}

function validateApi() {
    const apiKey = document.getElementById('apiKeyInput').value;
    const baseUrl = document.querySelector('input[name="base_url"]').value;
    const resultEl = document.getElementById('apiValidateResult');
    resultEl.innerHTML = '⏳ Проверка...';
    
    fetch('?run=validate_api&api_key=' + encodeURIComponent(apiKey) + '&base_url=' + encodeURIComponent(baseUrl))
        .then(r => r.json())
        .then(data => {
            resultEl.innerHTML = data.message;
            resultEl.style.color = data.ok ? '#27ae60' : '#e74c3c';
        })
        .catch(err => {
            resultEl.innerHTML = '❌ Ошибка запроса: ' + err.message;
            resultEl.style.color = '#e74c3c';
        });
}
</script>
<script src="<?= \Bauer\Perelinkovka\Env::assetUrl('ajaxnav.js') ?>"></script>
<script>window.BP_API = <?= json_encode(\Bauer\Perelinkovka\Env::apiUrl()) ?>;</script>
<script src="<?= \Bauer\Perelinkovka\Env::assetUrl('progress.js') ?>"></script>

</body>
</html>
