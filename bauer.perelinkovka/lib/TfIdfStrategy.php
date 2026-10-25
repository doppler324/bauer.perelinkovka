<?php

namespace Bauer\Perelinkovka;

/**
 * Class TfIdfStrategy
 * 
 * Стратегия на основе TF-IDF (Term Frequency — Inverse Document Frequency).
 * 
 * Алгоритм:
 * 1. Собирает корпус текстов всех элементов (название + описание + ключевые слова)
 * 2. Строит частотную матрицу термов для каждого документа
 * 3. Вычисляет IDF для каждого терма по всем документам
 * 4. Для пары сущностей считает косинусную близость их TF-IDF векторов
 * 
 * Чистый PHP, без внешних API.
 */
class TfIdfStrategy implements MatchingStrategyInterface
{
    private float $weight;
    private ?DataProviderInterface $dataProvider;
    private array $stopWords = [
        'для', 'и', 'в', 'на', 'с', 'по', 'от', 'до', 'из', 'у', 'о', 'к',
        'за', 'не', 'ни', 'как', 'что', 'это', 'все', 'или', 'но', 'а',
        'если', 'при', 'без', 'над', 'под', 'об', 'про', 'через', 'после',
        'the', 'a', 'an', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'and', 'or'
    ];
    private ?array $idfCache = null;
    private ?array $tfCache = null;
    private ?array $vectorCache = null;
    private ?array $allTokens = null;

    public function __construct(?DataProviderInterface $dataProvider = null, array $config = [])
    {
        $this->dataProvider = $dataProvider;
        $this->weight = (float)($config['weight'] ?? 0.25);
    }

    public function getName(): string { return 'tfidf'; }

    public function getDescription(): string
    {
        return 'TF-IDF: частотная матрица термов, косинусная близость. Чистый PHP, без API.';
    }

    public function getWeight(): float { return $this->weight; }
    public function isAvailable(): bool { return true; }

    private function tokenize(string $text): array
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}\s-]/u', ' ', $text);
        $words = preg_split('/\s+/u', $text);
        $words = array_filter($words, function ($w) {
            $w = trim($w);
            return mb_strlen($w, 'UTF-8') >= 3 && !in_array($w, $this->stopWords);
        });
        return array_values(array_map('trim', $words));
    }

    private function getEntityText(array $entity): string
    {
        $parts = [
            $entity['name'] ?? '',
            $entity['description'] ?? '',
            is_array($entity['keywords'] ?? null) ? implode(' ', $entity['keywords']) : '',
        ];
        return implode(' ', $parts);
    }

    private function buildCorpus(): void
    {
        if ($this->idfCache !== null) return;
        if (!$this->dataProvider) {
            $this->idfCache = []; $this->tfCache = []; $this->vectorCache = []; $this->allTokens = [];
            return;
        }

        $elements = $this->dataProvider->getElements(['active' => true]);
        $docTokens = [];
        foreach ($elements as $el) {
            $docTokens[$el['id']] = $this->tokenize($this->getEntityText($el));
        }

        $allTerms = [];
        foreach ($docTokens as $tokens) {
            foreach ($tokens as $token) $allTerms[$token] = true;
        }
        $this->allTokens = array_keys($allTerms);

        $df = [];
        foreach ($allTerms as $term => $_) $df[$term] = 0;
        foreach ($docTokens as $tokens) {
            foreach (array_unique($tokens) as $token) {
                if (isset($df[$token])) $df[$token]++;
            }
        }

        $totalDocs = count($docTokens) ?: 1;
        $this->idfCache = [];
        foreach ($df as $term => $count) {
            $this->idfCache[$term] = log($totalDocs / ($count ?: 1));
        }

        $this->tfCache = [];
        foreach ($docTokens as $docId => $tokens) {
            $total = count($tokens) ?: 1;
            $tf = [];
            foreach ($tokens as $token) $tf[$token] = ($tf[$token] ?? 0) + 1;
            foreach ($tf as $token => $c) $tf[$token] = $c / $total;
            $this->tfCache[$docId] = $tf;
        }

        $this->vectorCache = [];
        foreach ($this->tfCache as $docId => $tf) {
            $vector = [];
            foreach ($this->allTokens as $term) {
                $vector[$term] = ($tf[$term] ?? 0) * ($this->idfCache[$term] ?? 0);
            }
            $this->vectorCache[$docId] = $vector;
        }
    }

    private function cosineSimilarity(array $vecA, array $vecB): float
    {
        $dot = 0; $normA = 0; $normB = 0;
        foreach ($vecA as $term => $v) {
            $dot += $v * ($vecB[$term] ?? 0);
            $normA += $v * $v;
        }
        foreach ($vecB as $v) $normB += $v * $v;
        $denom = sqrt($normA) * sqrt($normB);
        return $denom > 0 ? $dot / $denom : 0.0;
    }

    public function calculate(array $entityA, array $entityB, string $type = 'element'): float
    {
        if ($type !== 'element') return 0.0;
        $idA = $entityA['id'] ?? 0;
        $idB = $entityB['id'] ?? 0;
        if (!$idA || !$idB) return 0.0;
        $this->buildCorpus();
        $vecA = $this->vectorCache[$idA] ?? null;
        $vecB = $this->vectorCache[$idB] ?? null;
        if (!$vecA || !$vecB) return 0.0;
        return $this->cosineSimilarity($vecA, $vecB);
    }

    public function resetCorpus(): void
    {
        $this->idfCache = null;
        $this->tfCache = null;
        $this->vectorCache = null;
        $this->allTokens = null;
    }
}
