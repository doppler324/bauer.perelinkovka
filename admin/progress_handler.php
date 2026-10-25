<?php
/**
 * progress_handler.php — порционные операции для прогресс-баров (отвечает JSON).
 * Окружение (Env) уже настроено вызывающим файлом: тестовым progress_api.php или Битрикс-эндпоинтом.
 * Каждый вызов обрабатывает небольшую часть работы; браузер вызывает его по кругу и рисует прогресс.
 */

use Bauer\Perelinkovka\JsonDataProvider;
use Bauer\Perelinkovka\Linker;
use Bauer\Perelinkovka\KeywordMatchingStrategy;
use Bauer\Perelinkovka\TfIdfStrategy;
use Bauer\Perelinkovka\MassAnchors;
@set_time_limit(120);
// Ответ отправляем сразу и целиком: Битрикс при завершении сбрасывает свои буферы вывода раньше наших
// shutdown-функций, поэтому полагаться на них нельзя. Посторонний вывод (предупреждения, HTML) отбрасываем.
ob_start();
$GLOBALS['bp_sent'] = false;
$send = function (array $a, int $code = 200) {
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    $GLOBALS['bp_sent'] = true;
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
};
// фатальные ошибки PHP и ответ, который так и не был отправлен, превращаем в понятный JSON
register_shutdown_function(function () use ($send) {
    if ($GLOBALS['bp_sent']) {
        return;
    }
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        $send(['ok' => false, 'error' => 'Фатальная ошибка PHP: ' . $err['message'] . ' (' . basename($err['file']) . ':' . $err['line'] . ')'], 500);
        return;
    }
    $noise = trim(strip_tags((string)ob_get_contents()));
    $send(['ok' => false, 'error' => 'Пустой ответ сервера' . ($noise !== '' ? ': ' . mb_substr($noise, 0, 300) : '')], 500);
});

$dp = \Bauer\Perelinkovka\Env::provider();
$out = fn(array $a) => $send($a + ['ok' => true]);
$fail = function (string $m) use ($send) { $send(['ok' => false, 'error' => $m], 400); exit; };

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $fail('Нужен POST');
}
$step = (string)($_POST['step'] ?? '');

try {
    // ---------- Блоки: подбор связей по правилам блока ----------
    if ($step === 'links') {
        $block = $dp->getBlock((string)($_POST['block'] ?? ''));
        if (!$block) {
            $fail('Блок не найден');
        }
        $linker = new Linker($dp);
        $st = [];
        $en = $block['strategies'] ?? ['keyword'];
        if (in_array('keyword', $en, true)) $st[] = new KeywordMatchingStrategy(['weight' => 1.0]);
        if (in_array('tfidf', $en, true)) $st[] = new TfIdfStrategy($dp, ['weight' => 1.0]);
        if ($st) $linker->setStrategies($st);

        $inbound = json_decode((string)($_POST['inbound'] ?? ''), true);
        $save = ($_POST['mode'] ?? 'preview') === 'save';
        $r = $linker->autoLinkBlock($block, [
            'save' => $save,
            'offset' => (int)($_POST['offset'] ?? 0),
            'limit' => max(1, (int)($_POST['limit'] ?? 5)),
            'inbound' => is_array($inbound) && !$save ? $inbound : null,
        ]);
        $out([
            'total' => $r['total'], 'next' => $r['next'],
            'created' => $r['created'], 'skipped' => $r['skipped'], 'errors' => count($r['errors']),
            'found' => count($r['links']),
            'links' => $save ? [] : $r['links'],
            'inbound' => $r['inbound'],
        ]);
        exit;
    }

    // ---------- Тексты: массовая расстановка анкоров ----------
    if (in_array($step, ['anchors_init', 'anchors_plan', 'anchors_apply'], true)) {
        $mass = new MassAnchors($dp, (int)($_POST['m_iblock'] ?? 0), (string)($_POST['pool'] ?? 'articles'), isset($_POST['one_per_block']) ? $_POST['one_per_block'] === '1' : null);

        if ($step === 'anchors_init') {
            $ids = $mass->scopeIds((string)($_POST['scope'] ?? 'all'), (int)($_POST['scope_id'] ?? 0), !empty($_POST['with_children']));
            $out(['ids' => $ids, 'inbound' => $mass->inbound()]);
            exit;
        }
        if ($step === 'anchors_plan') {
            $ids = array_map('intval', (array)($_POST['ids'] ?? []));
            $inbound = json_decode((string)($_POST['inbound'] ?? '{}'), true) ?: [];
            $r = $mass->planChunk($ids, $inbound, [
                'max_links' => $_POST['max_links'] ?? 3, 'max_inbound' => $_POST['max_inbound'] ?? 0,
                'only_low' => !empty($_POST['only_low']), 'low_threshold' => $_POST['low_threshold'] ?? 1,
                'balance' => !empty($_POST['balance']),
            ], 2.0);
            $out(['plan' => (object)$r['plan'], 'inbound' => (object)$r['inbound'], 'processed' => $r['processed']]);
            exit;
        }
        // anchors_apply: pairs[] = "id статьи|тип:id"
        $by = [];
        foreach ((array)($_POST['pairs'] ?? []) as $pair) {
            [$aid, $tk] = array_pad(explode('|', (string)$pair, 2), 2, '');
            if ($tk !== '') {
                $by[(int)$aid][] = $tk;
            }
        }
        $r = $mass->applyChunk($by);
        $out(['applied' => $r['applied'], 'articles' => $r['articles']]);
        exit;
    }
    // ---------- Эмбеддинги целей: расчёт векторов порциями ----------
    if (in_array($step, ['emb_init', 'emb_index'], true)) {
        $emb = \Bauer\Perelinkovka\Env::semantic(null, true);
        if (!$emb) {
            $fail('Не задан ключ OpenRouter: Настройки продукта → Модули → Перелинковка');
        }
        if ($step === 'emb_init') {
            $targets = \Bauer\Perelinkovka\Env::anchorTargets();
            $tokens = 0;
            foreach ($targets as $t) {
                $tokens += \Bauer\Perelinkovka\Embeddings::estimateTokens(\Bauer\Perelinkovka\Embeddings::targetText($t));
            }
            $out(['model' => $emb->model(), 'keys' => array_map([\Bauer\Perelinkovka\Embeddings::class, 'key'], $targets), 'tokens' => $tokens,
                'cost' => round($tokens / 1e6 * \Bauer\Perelinkovka\Embeddings::PRICE_PER_MTOK, 3)]);
            exit;
        }
        $ids = ['element' => [], 'section' => []];
        foreach ((array)($_POST['keys'] ?? []) as $k) {
            [$type, $id] = array_pad(explode(':', (string)$k, 2), 2, '');
            if (isset($ids[$type]) && (int)$id > 0) {
                $ids[$type][] = (int)$id;
            }
        }
        $targets = [];
        if ($ids['element']) {
            foreach ($dp->getElements(['id' => $ids['element']]) as $e) {
                $targets[] = $e + ['type' => 'element'];
            }
        }
        if ($ids['section']) {
            foreach ($dp->getSections(['id' => $ids['section']]) as $sec) {
                $targets[] = $sec + ['type' => 'section'];
            }
        }
        $r = $emb->indexTargets($targets);
        $out(['embedded' => $r['embedded'], 'skipped' => $r['skipped'], 'tokens' => $r['tokens']]);
        exit;
    }
    $fail('Неизвестный шаг');
} catch (\Throwable $e) {
    $fail($e->getMessage());
}
