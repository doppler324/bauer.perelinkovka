<?php

namespace Bauer\Perelinkovka;

/**
 * Interface MatchingStrategyInterface
 * 
 * Интерфейс стратегии поиска связей между сущностями.
 * Каждая стратегия реализует свой алгоритм оценки релевантности
 * и возвращает нормализованный вес (0.0 – 1.0).
 */
interface MatchingStrategyInterface
{
    /**
     * Название стратегии (для отображения в админке)
     * @return string
     */
    public function getName(): string;

    /**
     * Описание стратегии (что делает, когда применять)
     * @return string
     */
    public function getDescription(): string;

    /**
     * Рассчитывает вес связи между двумя сущностями.
     * 
     * @param array $entityA - первая сущность (element или section)
     * @param array $entityB - вторая сущность
     * @param string $type - тип сущностей ('element' или 'section')
     * @return float - вес связи от 0.0 (нет связи) до 1.0 (макс. релевантность)
     */
    public function calculate(array $entityA, array $entityB, string $type = 'element'): float;

    /**
     * Вес стратегии в итоговой композитной оценке (0.0 – 1.0).
     * Сумма весов всех стратегий должна быть 1.0.
     * @return float
     */
    public function getWeight(): float;

    /**
     * Доступна ли стратегия (например, есть ли API-ключ для AI-стратегий)
     * @return bool
     */
    public function isAvailable(): bool;
}
