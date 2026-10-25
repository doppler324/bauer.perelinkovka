<?php

namespace Bauer\Perelinkovka;

/**
 * Class KeywordMatchingStrategy
 * 
 * Стратегия поиска связей по прямым совпадениям:
 * - ключевые слова
 * - токены в заголовке
 * - токены в описании
 * - общий раздел
 * - общий бренд
 * 
 * Не требует API, работает мгновенно.
 */
class KeywordMatchingStrategy implements MatchingStrategyInterface
{
    /** @var float вес стратегии в композитной оценке */
    private float $weight;

    /** @var array стоп-слова */
    private array $stopWords = [
        'для', 'и', 'в', 'на', 'с', 'по', 'от', 'до', 'из', 'у', 'о', 'к',
        'за', 'не', 'ни', 'как', 'что', 'это', 'все', 'или', 'но', 'а',
        'если', 'при', 'без', 'над', 'под', 'об', 'про', 'через', 'после',
        'the', 'a', 'an', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'and', 'or'
    ];

    /** @var array веса компонентов */
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'keyword_weight'   => 3,
            'title_weight'     => 2,
            'description_weight' => 1,
            'section_weight'   => 1,
            'brand_weight'     => 2,
        ], $config);

        $this->weight = (float)($config['weight'] ?? 0.15);
    }

    public function getName(): string
    {
        return 'keyword';
    }

    public function getDescription(): string
    {
        return 'Сопоставляет элементы по прямым совпадениям ключевых слов, токенов в заголовке и описании, общему разделу и бренду. Не требует API.';
    }

    public function getWeight(): float
    {
        return $this->weight;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * Токенизация текста
     * @param string $text
     * @return array
     */
    private function tokenize(string $text): array
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}\s-]/u', ' ', $text);
        $words = preg_split('/\s+/u', $text);

        $words = array_filter($words, function ($word) {
            $word = trim($word);
            return mb_strlen($word, 'UTF-8') >= 3 && !in_array($word, $this->stopWords);
        });

        return array_unique(array_values(array_map('trim', $words)));
    }

    /**
     * Рассчитывает сырой вес (не нормализованный).
     * Используется для совместимости с текущим Linker.
     * 
     * @param array $entityA
     * @param array $entityB
     * @param string $type
     * @return int
     */
    public function calculateRaw(array $entityA, array $entityB, string $type = 'element'): int
    {
        $weight = 0;

        // 1. Ключевые слова
        $keywordsA = $entityA['keywords'] ?? [];
        $keywordsB = $entityB['keywords'] ?? [];
        $commonKeywords = array_intersect($keywordsA, $keywordsB);
        $weight += count($commonKeywords) * $this->config['keyword_weight'];

        // 2. Заголовки
        $titleTokensA = $this->tokenize($entityA['name'] ?? '');
        $titleTokensB = $this->tokenize($entityB['name'] ?? '');
        $commonTitleWords = array_intersect($titleTokensA, $titleTokensB);
        $weight += count($commonTitleWords) * $this->config['title_weight'];

        // 3. Описания
        $descTokensA = $this->tokenize($entityA['description'] ?? '');
        $descTokensB = $this->tokenize($entityB['description'] ?? '');
        $commonDescWords = array_intersect($descTokensA, $descTokensB);
        $weight += count($commonDescWords) * $this->config['description_weight'];

        // 4. Общий раздел
        if ($type === 'element') {
            $sectionA = $entityA['section_id'] ?? null;
            $sectionB = $entityB['section_id'] ?? null;
            if ($sectionA && $sectionB && $sectionA === $sectionB) {
                $weight += $this->config['section_weight'];
            }
        }

        // 5. Общий бренд
        $brands = ['apple', 'samsung', 'xiaomi', 'lenovo', 'asus', 'lg'];
        foreach ($brands as $brand) {
            if (in_array($brand, $titleTokensA) && in_array($brand, $titleTokensB)) {
                $weight += $this->config['brand_weight'];
            }
        }

        return $weight;
    }

    /**
     * @inheritDoc
     * 
     * Нормализует сырой вес к диапазону 0.0 – 1.0.
     * Максимальный сырой вес принимается за 20 (5 совпадений × 4 макс. веса).
     */
    public function calculate(array $entityA, array $entityB, string $type = 'element'): float
    {
        $raw = $this->calculateRaw($entityA, $entityB, $type);
        // Нормализация: делим на макс. возможный вес (20 — эмпирически)
        return min(1.0, $raw / 20.0);
    }
}
