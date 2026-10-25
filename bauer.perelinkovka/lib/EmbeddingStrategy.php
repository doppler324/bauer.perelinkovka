<?php

namespace Bauer\Perelinkovka;

/**
 * Class EmbeddingStrategy
 * 
 * Стратегия на основе семантических векторов (embeddings).
 * Использует AiClientInterface для получения векторов через OpenRouter.
 * Сравнивает сущности по косинусной близости их embedding-векторов.
 * 
 * Кэширует векторы в памяти на время выполнения скрипта.
 * Для постоянного кэша нужна таблица b_bauer_perelinkovka_embeddings в БД.
 */
class EmbeddingStrategy implements MatchingStrategyInterface
{
    /** @var AiClientInterface */
    private AiClientInterface $aiClient;

    /** @var float вес стратегии */
    private float $weight;

    /** @var array кэш векторов: [entityType_entityId => [float, ...]] */
    private array $vectorCache = [];

    /** @var int размерность векторов (проверяется при первом запросе) */
    private int $vectorDim = 0;

    /** @var array стоп-слова (те же, что в KeywordMatchingStrategy) */
    private array $stopWords = [
        'для', 'и', 'в', 'на', 'с', 'по', 'от', 'до', 'из', 'у', 'о', 'к',
        'за', 'не', 'ни', 'как', 'что', 'это', 'все', 'или', 'но', 'а',
        'если', 'при', 'без', 'над', 'под', 'об', 'про', 'через', 'после',
        'the', 'a', 'an', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'and', 'or'
    ];

    public function __construct(AiClientInterface $aiClient, array $config = [])
    {
        $this->aiClient = $aiClient;
        $this->weight = (float)($config['weight'] ?? 0.35);
    }

    public function getName(): string
    {
        return 'embedding';
    }

    public function getDescription(): string
    {
        return 'Семантический поиск через embeddings (OpenRouter). Понимает смысл текста, находит неочевидные связи. Требует API-ключ.';
    }

    public function getWeight(): float
    {
        return $this->weight;
    }

    public function isAvailable(): bool
    {
        return $this->aiClient->isAvailable();
    }

    /**
     * Собирает текст сущности для векторизации
     * @param array $entity
     * @return string
     */
    private function getEntityText(array $entity): string
    {
        $parts = [
            $entity['name'] ?? '',
            $entity['description'] ?? '',
            is_array($entity['keywords'] ?? null) ? implode(', ', $entity['keywords']) : '',
        ];
        // Обрезаем до 8000 символов (ограничение OpenRouter)
        $text = implode('. ', $parts);
        return mb_substr($text, 0, 8000);
    }

    /**
     * Предзагружает эмбеддинги для всех активных элементов из провайдера
     * одним батч-запросом. Вызвать перед поиском связей.
     * 
     * @param DataProviderInterface $dataProvider
     * @return void
     */
    public function preloadFromProvider(DataProviderInterface $dataProvider): void
    {
        if (!$this->isAvailable()) return;

        $elements = $dataProvider->getElements(['active' => true]);
        if (empty($elements)) return;

        $texts = [];
        $keys = [];
        foreach ($elements as $el) {
            $text = $this->getEntityText($el);
            if (empty(trim($text))) continue;
            $texts[] = $text;
            $keys[] = 'element_' . $el['id'];
        }

        if (empty($texts)) return;

        try {
            $embeddings = $this->aiClient->getEmbeddings($texts);
        } catch (\Exception $e) {
            return;
        }

        foreach ($embeddings as $index => $vector) {
            if (!empty($vector) && isset($keys[$index])) {
                $this->vectorCache[$keys[$index]] = $vector;
            }
        }

        // Определяем размерность
        if ($this->vectorDim === 0 && !empty($this->vectorCache)) {
            $this->vectorDim = count(reset($this->vectorCache));
        }
    }

    /**
     * Получает или загружает вектор для сущности
     * @param array $entity
     * @param string $type
     * @return array|null
     */
    private function getVector(array $entity, string $type): ?array
    {
        $id = $entity['id'] ?? 0;
        $cacheKey = $type . '_' . $id;

        if (isset($this->vectorCache[$cacheKey])) {
            return $this->vectorCache[$cacheKey];
        }

        // Если нет в кэше — запрашиваем по одному
        $text = $this->getEntityText($entity);
        if (empty(trim($text))) {
            return null;
        }

        try {
            $vector = $this->aiClient->getEmbedding($text);
        } catch (\Exception $e) {
            return null;
        }

        if (empty($vector)) {
            return null;
        }

        $this->vectorCache[$cacheKey] = $vector;
        return $vector;
    }

    /**
     * Косинусная близость между векторами
     * @param array $vecA
     * @param array $vecB
     * @return float
     */
    private function cosineSimilarity(array $vecA, array $vecB): float
    {
        $dot = 0;
        $normA = 0;
        $normB = 0;

        $len = min(count($vecA), count($vecB));
        for ($i = 0; $i < $len; $i++) {
            $a = $vecA[$i];
            $b = $vecB[$i];
            $dot += $a * $b;
            $normA += $a * $a;
            $normB += $b * $b;
        }

        $denom = sqrt($normA) * sqrt($normB);
        return $denom > 0 ? max(0, $dot / $denom) : 0.0;
    }

    /**
     * @inheritDoc
     */
    public function calculate(array $entityA, array $entityB, string $type = 'element'): float
    {
        if (!$this->isAvailable()) {
            return 0.0;
        }

        $vecA = $this->getVector($entityA, $type);
        $vecB = $this->getVector($entityB, $type);

        if (!$vecA || !$vecB) {
            return 0.0;
        }

        return $this->cosineSimilarity($vecA, $vecB);
    }

    /**
     * Очищает кэш векторов
     * @return void
     */
    public function clearCache(): void
    {
        $this->vectorCache = [];
        $this->vectorDim = 0;
    }
}
