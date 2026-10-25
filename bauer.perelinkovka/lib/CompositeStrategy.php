<?php

namespace Bauer\Perelinkovka;

/**
 * Class CompositeStrategy
 * 
 * Композитная стратегия — объединяет несколько стратегий с весами.
 * Итоговая оценка = Σ(вес_стратегии × оценка_стратегии).
 * 
 * Сумма весов всех стратегий должна равняться 1.0.
 */
class CompositeStrategy implements MatchingStrategyInterface
{
    /** @var MatchingStrategyInterface[] */
    private array $strategies;

    /** @var float общий вес композита */
    private float $weight;

    /**
     * @param MatchingStrategyInterface[] $strategies
     * @param array $config
     */
    public function __construct(array $strategies, array $config = [])
    {
        $this->strategies = [];
        foreach ($strategies as $s) {
            if ($s instanceof MatchingStrategyInterface) {
                $this->strategies[] = $s;
            }
        }
        $this->weight = (float)($config['weight'] ?? 1.0);
    }

    public function getName(): string
    {
        $names = array_map(fn($s) => $s->getName(), $this->strategies);
        return 'composite(' . implode('+', $names) . ')';
    }

    public function getDescription(): string
    {
        $parts = [];
        foreach ($this->strategies as $s) {
            $parts[] = $s->getName() . ' (×' . number_format($s->getWeight(), 2) . ')';
        }
        return 'Комбинация: ' . implode(' + ', $parts);
    }

    public function getWeight(): float
    {
        return $this->weight;
    }

    public function isAvailable(): bool
    {
        // Композит доступен, если доступна хотя бы одна стратегия
        foreach ($this->strategies as $s) {
            if ($s->isAvailable()) return true;
        }
        return false;
    }

    /**
     * @inheritDoc
     * 
     * Итоговая оценка = Σ(вес_стратегии × оценка_стратегии).
     * Если стратегия недоступна — её вклад = 0.
     */
    public function calculate(array $entityA, array $entityB, string $type = 'element'): float
    {
        $total = 0.0;
        $weightSum = 0.0;

        foreach ($this->strategies as $s) {
            if (!$s->isAvailable()) continue;

            $score = $s->calculate($entityA, $entityB, $type);
            $w = $s->getWeight();
            $total += $w * $score;
            $weightSum += $w;
        }

        // Нормализация на случай, если сумма весов < 1.0
        return $weightSum > 0 ? $total / $weightSum : 0.0;
    }

    /**
     * Возвращает список стратегий
     * @return MatchingStrategyInterface[]
     */
    public function getStrategies(): array
    {
        return $this->strategies;
    }

    /**
     * Проверяет, что сумма весов стратегий равна 1.0
     * @return bool
     */
    public function validateWeights(): bool
    {
        $sum = 0.0;
        foreach ($this->strategies as $s) {
            $sum += $s->getWeight();
        }
        return abs($sum - 1.0) < 0.001;
    }
}
