<?php

namespace Bauer\Perelinkovka;

/**
 * Class Embeddings
 *
 * Смысловая близость текстов через эмбеддинги (OpenRouter).
 * Векторы целей считаются заранее и хранятся в VectorStore (пересчёт только при изменении текста или модели);
 * векторы абзацев статьи считаются на лету одним запросом и не сохраняются.
 */
class Embeddings
{
    public const BATCH = 48;
    /** Цена text-embedding-3-small, $ за 1 млн токенов (для оценки; у других моделей цена иная). */
    public const PRICE_PER_MTOK = 0.02;

    private AiClientInterface $client;
    private VectorStore $store;
    private string $model;

    public function __construct(AiClientInterface $client, VectorStore $store, string $model)
    {
        $this->client = $client;
        $this->store = $store;
        $this->model = $model;
    }

    /** Текст цели для вектора: название и начало описания. */
    public static function targetText(array $t): string
    {
        $text = trim((string)($t['name'] ?? ''));
        $desc = trim((string)($t['description'] ?? ''));
        return $desc === '' ? $text : $text . '. ' . mb_substr($desc, 0, 400);
    }

    public static function key(array $t): string
    {
        return ($t['type'] ?? 'element') . ':' . (int)$t['id'];
    }

    private function hash(string $text): string
    {
        return md5($this->model . '|' . $text);
    }

    public static function estimateTokens(string $text): int
    {
        return (int)ceil(mb_strlen($text) / 2.8);
    }

    /** @return float[][] нормированные векторы в порядке текстов (пустой массив — не посчитан) */
    public function embedTexts(array $texts): array
    {
        $out = [];
        $texts = array_values($texts);
        foreach (array_chunk($texts, self::BATCH, true) as $part) {
            $res = $this->client->getEmbeddings(array_map(fn($t) => mb_substr((string)$t, 0, 6000), array_values($part)));
            $i = 0;
            foreach ($part as $k => $_) {
                $out[$k] = self::normalize($res[$i++] ?? []);
            }
        }
        return $out;
    }

    /**
     * Считает и сохраняет векторы для целей, у которых их нет или изменился текст.
     * @return array{embedded:int,skipped:int,tokens:int}
     */
    public function indexTargets(array $targets): array
    {
        $byKey = [];
        foreach ($targets as $t) {
            $byKey[self::key($t)] = self::targetText($t);
        }
        $have = $this->store->hashes(array_keys($byKey));
        $todo = [];
        foreach ($byKey as $k => $text) {
            if (($have[$k] ?? '') !== $this->hash($text)) {
                $todo[$k] = $text;
            }
        }
        $tokens = 0;
        $rows = [];
        foreach (array_chunk($todo, self::BATCH, true) as $part) {
            $vecs = $this->embedTexts(array_values($part));
            $i = 0;
            foreach ($part as $k => $text) {
                $v = $vecs[$i++] ?? [];
                if ($v) {
                    $rows[$k] = ['h' => $this->hash($text), 'v' => $v];
                    $tokens += self::estimateTokens($text);
                }
            }
        }
        if ($rows) {
            $this->store->put($rows);
        }
        return ['embedded' => count($rows), 'skipped' => count($byKey) - count($todo), 'tokens' => $tokens];
    }

    /** @var array<string,string> векторы целей (бинарно) на время запроса: при массовой расстановке читаются из хранилища один раз */
    private static array $vecCache = [];

    /**
     * Векторы целей по ключам "тип:id" (только имеющиеся), каждый — бинарная строка float32; разбор через floats().
     * @return array<string,string>
     */
    public function targetVectors(array $targets): array
    {
        $keys = array_map([self::class, 'key'], $targets);
        $cacheKey = $this->model . '|';
        $missing = array_filter($keys, fn($k) => !isset(self::$vecCache[$cacheKey . $k]));
        if ($missing) {
            foreach ($this->store->get(array_values($missing)) as $k => $row) {
                self::$vecCache[$cacheKey . $k] = $row['b'];
            }
        }
        $out = [];
        foreach ($keys as $k) {
            if (isset(self::$vecCache[$cacheKey . $k]) && self::$vecCache[$cacheKey . $k] !== '') {
                $out[$k] = self::$vecCache[$cacheKey . $k];
            }
        }
        return $out;
    }

    /** Вектор в виде массива float из бинарной строки (или массива как есть). */
    public static function floats($v): array
    {
        return is_string($v) ? ($v === '' ? [] : array_values(unpack('g*', $v))) : $v;
    }

    public function indexedCount(): int
    {
        return $this->store->count();
    }

    public static function normalize(array $v): array
    {
        $n = 0.0;
        foreach ($v as $x) {
            $n += $x * $x;
        }
        $n = sqrt($n);
        return $n > 0 ? array_map(fn($x) => $x / $n, $v) : [];
    }

    /** Косинус нормированных векторов. */
    public static function dot($a, $b): float
    {
        $a = self::floats($a);
        $b = self::floats($b);
        $s = 0.0;
        foreach ($a as $i => $x) {
            $s += $x * ($b[$i] ?? 0.0);
        }
        return $s;
    }
}
