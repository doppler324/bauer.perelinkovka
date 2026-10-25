<?php

namespace Bauer\Perelinkovka;

use Bauer\Perelinkovka\Store\JsonFileStore;
use Bauer\Perelinkovka\Store\OptionStore;
use Bauer\Perelinkovka\Store\StoreInterface;

/**
 * Окружение админки: откуда брать данные и где хранить настройки.
 * Вкладки админки одинаковы в тестовой админке (JSON) и в Битриксе, различается только окружение:
 * Env::forJson($dir) или Env::forBitrix().
 */
class Env
{
    private static ?DataProviderInterface $provider = null;
    private static ?StoreInterface $store = null;
    private static string $mode = 'json';
    private static string $dataDir = '';
    private static string $assetUrl = '';
    private static string $apiUrl = 'progress_api.php';
    private static array $anchorOptionsCache = [];

    public static function forJson(string $dataDir, string $assetUrl = '', string $apiUrl = 'progress_api.php'): void
    {
        self::$mode = 'json';
        self::$dataDir = rtrim($dataDir, '/\\');
        self::$provider = new JsonDataProvider(self::$dataDir);
        self::$store = new JsonFileStore(self::$dataDir);
        self::$assetUrl = $assetUrl;
        self::$apiUrl = $apiUrl;
    }

    public static function forBitrix(string $assetUrl, string $apiUrl): void
    {
        self::$mode = 'bitrix';
        self::$provider = new BitrixDataProvider();
        self::$store = new OptionStore();
        self::$assetUrl = rtrim($assetUrl, '/') . '/';
        self::$apiUrl = $apiUrl;
    }

    public static function isBitrix(): bool
    {
        return self::$mode === 'bitrix';
    }

    public static function provider(): DataProviderInterface
    {
        return self::$provider;
    }

    public static function store(): StoreInterface
    {
        return self::$store;
    }

    /** Хранилище текстов статей с расставленными ссылками (каждый раз заново прочитанное). */
    public static function anchorStore(): AnchorStoreInterface
    {
        return self::$mode === 'bitrix' ? new BitrixAnchorStore() : new AnchorStore(self::$dataDir . '/anchors.json');
    }

    public static function synonyms(): SynonymProvider
    {
        return new SynonymProvider(self::$store);
    }

    /** Настройки расстановки анкоров (поверх AnchorEngine::DEFAULTS). */
    public static function anchorOptions(): array
    {
        $saved = self::$store->read('anchor_settings');
        return array_replace(AnchorEngine::DEFAULTS, is_array($saved) ? $saved : [], ['manual_anchors' => self::manualAnchors()]);
    }

    /** Ручные анкоры целей: ["тип:id" => ["фраза", ...]]. */
    public static function manualAnchors(): array
    {
        $saved = self::$store->read('manual_anchors');
        return is_array($saved) ? $saved : [];
    }

    /**
     * Сохраняет ручные анкоры из текста: строка "element:5 = фраза 1, фраза 2" (тип element или section).
     * @return int число целей с анкорами
     */
    public static function saveManualAnchorsFromText(string $text): int
    {
        $map = [];
        foreach (preg_split('/\R/u', $text) as $line) {
            if (!preg_match('/^\s*(element|section)\s*:\s*(\d+)\s*=\s*(.+)$/u', $line, $m)) {
                continue;
            }
            $phrases = array_values(array_unique(array_filter(array_map('trim', explode(',', $m[3])), 'strlen')));
            if ($phrases) {
                $map[$m[1] . ':' . (int)$m[2]] = $phrases;
            }
        }
        self::$store->write('manual_anchors', $map);
        return count($map);
    }

    public static function manualAnchorsText(): string
    {
        $lines = [];
        foreach (self::manualAnchors() as $key => $phrases) {
            $lines[] = $key . ' = ' . implode(', ', $phrases);
        }
        return implode("\n", $lines);
    }

    public static function saveAnchorOptions(array $options): bool
    {
        return self::$store->write('anchor_settings', $options);
    }

    public static function assetUrl(string $file): string
    {
        return self::$assetUrl . $file;
    }

    public static function apiUrl(): string
    {
        return self::$apiUrl;
    }

    /**
     * Цели ссылок в текстах: активные элементы и разделы инфоблоков из настроек анкоров
     * (target_iblock_ids; пусто — все инфоблоки).
     */
    public static function anchorTargets(?array $options = null): array
    {
        $o = $options ?? self::anchorOptions();
        $ids = array_map('intval', (array)($o['target_iblock_ids'] ?? []));
        $targets = [];
        if (!empty($o['target_elements'])) {
            foreach (self::$provider->getElements(['active' => true] + ($ids ? ['iblock_id' => $ids] : [])) as $el) {
                $targets[] = $el + ['type' => 'element'];
            }
        }
        if (!empty($o['target_sections'])) {
            foreach (self::$provider->getSections($ids ? ['iblock_id' => $ids] : []) as $sec) {
                $targets[] = $sec + ['type' => 'section'];
            }
        }
        return $targets;
    }

    /** Ключ OpenRouter: в Битриксе из настроек модуля, в тестовой оболочке — константа OPENROUTER_API_KEY. */
    public static function apiKey(): string
    {
        if (self::$mode === 'bitrix') {
            $key = trim((string)\Bitrix\Main\Config\Option::get('bauer.perelinkovka', 'openrouter_api_key', ''));
            if ($key !== '') {
                return $key;
            }
        }
        return defined('OPENROUTER_API_KEY') ? (string)OPENROUTER_API_KEY : '';
    }

    /**
     * Сервис эмбеддингов. Без $force — только если включён в настройках анкоров и задан ключ;
     * с $force (расчёт векторов целей) — независимо от флага.
     */
    public static function semantic(?array $options = null, bool $force = false): ?Embeddings
    {
        $o = $options ?? self::anchorOptions();
        $key = self::apiKey();
        if ((!$force && empty($o['use_embeddings'])) || $key === '') {
            return null;
        }
        $model = trim((string)($o['embedding_model'] ?? '')) ?: 'openai/text-embedding-3-small';
        $client = new OpenRouterClient(['api_key' => $key, 'embedding_model' => $model, 'timeout' => 60]);
        $store = new VectorStore(self::$mode === 'bitrix' ? null : self::$dataDir . '/vectors.json');
        return new Embeddings($client, $store, $model);
    }

    /** Движок анкоров со всеми нужными данными. */
    public static function anchorEngine(array $override = [], bool $cached = false): AnchorEngine
    {
        $class = $cached ? CachedAnchorEngine::class : AnchorEngine::class;
        return new $class(
            self::synonyms(),
            array_replace(self::anchorOptions(), ['semantic' => self::semantic()], $override),
            self::$provider->getIBlocks(),
            self::$provider->getSections()
        );
    }
}
