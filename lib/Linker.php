<?php

namespace Bauer\Perelinkovka;

/**
 * Class Linker
 * 
 * Основной класс для перелинковки разделов и элементов инфоблоков.
 * Анализирует контент и находит релевантные связи между сущностями.
 */
class Linker
{
    /** @var DataProviderInterface провайдер данных */
    private DataProviderInterface $dataProvider;

    /** @var array настройки анализатора */
    private array $config;

    /** @var array стоп-слова для анализа (предлоги, союзы и т.д.) */
    private array $stopWords = [
        'для', 'и', 'в', 'на', 'с', 'по', 'от', 'до', 'из', 'у', 'о', 'к',
        'за', 'не', 'ни', 'как', 'что', 'это', 'все', 'или', 'но', 'а',
        'если', 'при', 'без', 'над', 'под', 'об', 'про', 'через', 'после',
        'the', 'a', 'an', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'and', 'or'
    ];

    /** Делитель для перевода «сырого» веса (целые баллы) в шкалу 0..1 */
    private const RAW_SCALE = 20;

    /** @var int минимальный вес совпадения для создания связи */
    private int $minMatchWeight = 1;

    /** @var MatchingStrategyInterface[] стратегии для расчёта веса */
    private array $strategies = [];

    /**
     * @param DataProviderInterface $dataProvider
     * @param array $config - настройки
     */
    public function __construct(DataProviderInterface $dataProvider, array $config = [])
    {
        $this->dataProvider = $dataProvider;
        $this->config = array_merge([
            'min_match_weight' => 1,        // минимальный вес для связи
            'keyword_weight' => 3,           // вес совпадения по ключевым словам
            'title_weight' => 2,             // вес совпадения в заголовке
            'description_weight' => 1,       // вес совпадения в описании
            'section_weight' => 1,           // вес за то, что в одном разделе
            'max_links_per_entity' => 10,    // макс. связей на сущность
            'cross_iblock' => true,          // разрешить связи между инфоблоками
        ], $config);

        $this->minMatchWeight = $this->config['min_match_weight'];
    }

    /**
     * Устанавливает стратегии для расчёта веса связей
     * @param MatchingStrategyInterface[] $strategies
     * @return self
     */
    public function setStrategies(array $strategies): self
    {
        $this->strategies = [];
        foreach ($strategies as $s) {
            if ($s instanceof MatchingStrategyInterface) {
                $this->strategies[] = $s;
            }
        }
        return $this;
    }

    /**
     * Возвращает текущие стратегии
     * @return MatchingStrategyInterface[]
     */
    public function getStrategies(): array
    {
        return $this->strategies;
    }

    /**
     * Разбивает текст на значимые слова (токены)
     * @param string $text - исходный текст
     * @return array - массив уникальных токенов
     */
    public function tokenize(string $text): array
    {
        // Приводим к нижнему регистру, убираем пунктуацию
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}\s-]/u', ' ', $text);
        
        // Разбиваем на слова
        $words = preg_split('/\s+/u', $text);
        
        // Фильтруем стоп-слова и однобуквенные слова
        $words = array_filter($words, function ($word) {
            $word = trim($word);
            if (mb_strlen($word, 'UTF-8') < 2) {
                return false;
            }
            return !in_array($word, $this->stopWords, true);
        });

        return array_unique(array_values(array_map('trim', $words)));
    }

    /**
     * Вычисляет вес связи между двумя элементами
     * 
     * @param array $entityA - первая сущность
     * @param array $entityB - вторая сущность
     * @param string $type - тип сущности ('element' или 'section')
     * @return int - общий вес совпадения
     */
    public function calculateMatchWeight(array $entityA, array $entityB, string $type = 'element'): int
    {
        $weight = 0;

        // 1. Анализ ключевых слов
        $keywordsA = $entityA['keywords'] ?? [];
        $keywordsB = $entityB['keywords'] ?? [];

        $commonKeywords = array_intersect($keywordsA, $keywordsB);
        $weight += count($commonKeywords) * $this->config['keyword_weight'];

        // 2. Анализ заголовков
        $titleTokensA = $this->tokenize($entityA['name'] ?? '');
        $titleTokensB = $this->tokenize($entityB['name'] ?? '');

        $commonTitleWords = array_intersect($titleTokensA, $titleTokensB);
        $weight += count($commonTitleWords) * $this->config['title_weight'];

        // 3. Анализ описаний
        $descTokensA = $this->tokenize($entityA['description'] ?? '');
        $descTokensB = $this->tokenize($entityB['description'] ?? '');

        $commonDescWords = array_intersect($descTokensA, $descTokensB);
        $weight += count($commonDescWords) * $this->config['description_weight'];

        // 4. Один раздел (для элементов)
        if ($type === 'element') {
            $sectionA = $entityA['section_id'] ?? null;
            $sectionB = $entityB['section_id'] ?? null;

            if ($sectionA && $sectionB && (int)$sectionA === (int)$sectionB) {
                $weight += $this->config['section_weight'];
            }
        }

        // 5. Один бренд в заголовке
        $brands = ['apple', 'samsung', 'xiaomi', 'lenovo', 'asus', 'lg'];
        foreach ($brands as $brand) {
            if (in_array($brand, $titleTokensA) && in_array($brand, $titleTokensB)) {
                $weight += 2;
            }
        }

        return $weight;
    }

    /**
     * Единая оценка пары в шкале 0..1.
     * Со стратегиями: взвешенное среднее по доступным стратегиям (сумма весов нормализуется).
     * Без стратегий: сырой балл calculateMatchWeight / RAW_SCALE.
     *
     * @return array{0: float, 1: string} [оценка, имя стратегии]
     */
    public function calculateScore(array $source, array $target, string $type = 'element'): array
    {
        if (empty($this->strategies)) {
            $raw = $this->calculateMatchWeight($source, $target, $type);
            return [min(1.0, $raw / self::RAW_SCALE), 'keyword'];
        }

        $sum = 0.0;
        $weights = 0.0;
        $used = [];
        foreach ($this->strategies as $s) {
            if (!$s->isAvailable()) {
                continue;
            }
            $score = $s->calculate($source, $target, $type);
            $sum += $s->getWeight() * $score;
            $weights += $s->getWeight();
            if ($score > 0) {
                $used[] = $s->getName();
            }
        }
        if ($weights <= 0) {
            return [0.0, 'keyword'];
        }
        return [min(1.0, $sum / $weights), $used ? implode('+', $used) : 'keyword'];
    }

    /** Тип связи по оценке 0..1 */
    public function classify(float $score): string
    {
        return $score >= 0.6 ? 'strong' : ($score >= 0.3 ? 'medium' : 'weak');
    }

    /**
     * Находит релевантные сущности для заданной.
     *
     * Опции: limit, min_score (0..1), cross_iblock,
     *        rule - правило блока (target_type, target_iblock_ids, max_links, min_score).
     * Устаревшая опция min_weight (целые баллы) пересчитывается в min_score.
     *
     * @return array кандидаты; weight - оценка 0..1
     */
    public function findRelevantLinks(string $entityType, int $entityId, array $options = []): array
    {
        $rule = $options['rule'] ?? null;

        $minScore = $options['min_score'] ?? null;
        if ($minScore === null && isset($options['min_weight'])) {
            $minScore = $options['min_weight'] / self::RAW_SCALE;
        }
        if ($minScore === null) {
            $minScore = $rule['min_score'] ?? 0.0;
        }
        $limit = (int)($options['limit'] ?? ($rule['max_links'] ?? 5));
        $crossIblock = $options['cross_iblock'] ?? $this->config['cross_iblock'];

        // Выравнивание: цели, на которые уже ссылаются чаще, получают штраф при равной релевантности
        $balance = (float)($options['balance'] ?? ($rule['balance'] ?? 0.0));
        $inbound = $options['inbound'] ?? [];
        $maxInbound = $inbound ? max($inbound) : 0;

        $sourceEntity = $entityType === 'element'
            ? $this->dataProvider->getElement($entityId)
            : $this->dataProvider->getSection($entityId);
        if (!$sourceEntity) {
            return [];
        }

        $sourceIblockId = (int)$sourceEntity['iblock_id'];

        // Какие типы целей и в каких инфоблоках искать
        if ($rule) {
            $targetTypes = [$rule['target_type'] ?? 'element'];
            $targetIblocks = array_map('intval', $rule['target_iblock_ids'] ?? []);
        } else {
            $targetTypes = $entityType === 'section' ? ['element', 'section'] : ['element'];
            $targetIblocks = [];
        }

        $candidates = [];
        foreach ($targetTypes as $targetType) {
            $pool = $targetType === 'element'
                ? $this->dataProvider->getElements(['active' => true])
                : $this->dataProvider->getSections(!$crossIblock && !$targetIblocks ? ['iblock_id' => $sourceIblockId] : []);

            foreach ($pool as $target) {
                $targetIblockId = (int)$target['iblock_id'];
                if ($targetType === $entityType && (int)$target['id'] === $entityId) {
                    continue;
                }
                if ($targetIblocks && !in_array($targetIblockId, $targetIblocks, true)) {
                    continue;
                }
                if (!$targetIblocks && !$crossIblock && $targetIblockId !== $sourceIblockId) {
                    continue;
                }

                [$score, $strategy] = $this->calculateScore($sourceEntity, $target, $targetType);
                if ($score <= 0 || $score < $minScore) {
                    continue;
                }

                $inboundCount = (int)($inbound[$targetType . '_' . (int)$target['id']] ?? 0);
                $adjusted = $balance > 0 && $maxInbound > 0
                    ? $score * (1 - $balance * $inboundCount / ($maxInbound + 1))
                    : $score;

                $candidates[] = [
                    'source_type' => $entityType,
                    'source_id' => $entityId,
                    'target_type' => $targetType,
                    'target_id' => (int)$target['id'],
                    'adjusted' => $adjusted,
                    'inbound' => $inboundCount,
                    'target_name' => $target['name'],
                    'target_code' => $target['code'] ?? null,
                    'iblock_id' => $targetIblockId,
                    'weight' => $score,
                    'strategy' => $strategy,
                    'type' => $this->classify($score),
                ];
            }
        }

        usort($candidates, fn($a, $b) => $b['adjusted'] <=> $a['adjusted']);

        return array_slice($candidates, 0, $limit);
    }

    /**
     * Ключ связи для проверки дублей (направленный, с учётом блока)
     */
    private function linkKey(string $blockCode, string $sType, int $sId, string $tType, int $tId): string
    {
        return $blockCode . '|' . $sType . '_' . $sId . '|' . $tType . '_' . $tId;
    }

    /**
     * Пересчитывает связи блока: для каждой активной сущности-источника
     * подбирает цели по правилам блока и сохраняет новые связи.
     *
     * @param array $block правило блока (code, source_type, source_iblock_id, ...)
     * @return array created, skipped, errors, links
     */
    public function autoLinkBlock(array $block, array $options = []): array
    {
        $options = array_merge(['save' => true], $options);
        $result = ['created' => 0, 'skipped' => 0, 'errors' => [], 'links' => []];

        $code = (string)($block['code'] ?? '');
        $sourceType = $block['source_type'] ?? 'element';
        $iblockId = (int)($block['source_iblock_id'] ?? 0);

        $sources = $sourceType === 'element'
            ? $this->dataProvider->getElements(['iblock_id' => $iblockId, 'active' => true])
            : $this->dataProvider->getSections(['iblock_id' => $iblockId]);

        // Порциями (для прогресс-бара): offset/limit по списку страниц блока
        $result['total'] = count($sources);
        $offset = max(0, (int)($options['offset'] ?? 0));
        $limit = (int)($options['limit'] ?? 0);
        if ($limit > 0) {
            $sources = array_slice($sources, $offset, $limit);
        }
        $result['next'] = $offset + count($sources);

        $existing = [];
        $inbound = [];
        foreach ($this->dataProvider->getAllLinks() as $l) {
            if (($l['block_code'] ?? '') === $code && !empty($l['active'])) {
                $ik = $l['target_type'] . '_' . (int)$l['target_id'];
                $inbound[$ik] = ($inbound[$ik] ?? 0) + 1;
            }
            $existing[$this->linkKey(
                (string)($l['block_code'] ?? ''),
                (string)$l['source_type'], (int)$l['source_id'],
                (string)$l['target_type'], (int)$l['target_id']
            )] = true;
        }

        // при предпросмотре по частям счётчики входящих передаются между вызовами
        if (isset($options['inbound']) && is_array($options['inbound'])) {
            $inbound = array_map('intval', $options['inbound']);
        }

        foreach ($sources as $source) {
            $found = $this->findRelevantLinks($sourceType, (int)$source['id'], ['rule' => $block, 'inbound' => $inbound]);

            foreach ($found as $c) {
                $key = $this->linkKey($code, $c['source_type'], $c['source_id'], $c['target_type'], $c['target_id']);
                if (isset($existing[$key])) {
                    $result['skipped']++;
                    continue;
                }
                $existing[$key] = true;
                $ik = $c['target_type'] . '_' . $c['target_id'];
                $inbound[$ik] = ($inbound[$ik] ?? 0) + 1;

                if (!$options['save']) {
                    $result['links'][] = $c;
                    continue;
                }

                $saved = $this->dataProvider->saveLink([
                    'block_code' => $code,
                    'source_type' => $c['source_type'],
                    'source_id' => $c['source_id'],
                    'target_type' => $c['target_type'],
                    'target_id' => $c['target_id'],
                    'source_iblock_id' => $iblockId,
                    'target_iblock_id' => $c['iblock_id'],
                    'type' => $c['type'],
                    'strategy' => $c['strategy'],
                    'score' => round($c['weight'], 4),
                    'sort' => (int)round($c['weight'] * 100),
                    'active' => true,
                ]);
                if ($saved) {
                    $result['created']++;
                    $result['links'][] = $c;
                } else {
                    $result['errors'][] = "Не удалось сохранить связь для #{$source['id']}";
                }
            }
        }

        $result['inbound'] = $inbound;
        return $result;
    }

    /**
     * Автоматически создаёт связи для всех элементов инфоблока (без блока)
     */
    public function autoLinkIBlock(int $iblockId, array $options = []): array
    {
        $options = array_merge([
            'limit_per_entity' => 5,
            'min_weight' => $this->minMatchWeight,
            'save' => true,
            'cross_iblock' => $this->config['cross_iblock'],
        ], $options);

        $result = ['created' => 0, 'skipped' => 0, 'errors' => [], 'links' => []];

        $elements = $this->dataProvider->getElements(['iblock_id' => $iblockId, 'active' => true]);
        $existing = $this->getAllExistingLinks();

        foreach ($elements as $element) {
            $found = $this->findRelevantLinks('element', (int)$element['id'], [
                'limit' => $options['limit_per_entity'],
                'min_weight' => $options['min_weight'],
                'cross_iblock' => $options['cross_iblock'],
            ]);

            foreach ($found as $c) {
                // Направленный ключ: A→B и B→A - разные связи, каждая видна на своей странице
                $key = $this->linkKey('', $c['source_type'], $c['source_id'], $c['target_type'], $c['target_id']);
                if (isset($existing[$key])) {
                    $result['skipped']++;
                    continue;
                }
                $existing[$key] = true;

                if (!$options['save']) {
                    $result['links'][] = $c;
                    continue;
                }

                $saved = $this->dataProvider->saveLink([
                    'source_type' => $c['source_type'],
                    'source_id' => $c['source_id'],
                    'target_type' => $c['target_type'],
                    'target_id' => $c['target_id'],
                    'source_iblock_id' => $iblockId,
                    'target_iblock_id' => $c['iblock_id'],
                    'type' => $c['type'],
                    'strategy' => $c['strategy'],
                    'score' => round($c['weight'], 4),
                    'sort' => (int)round($c['weight'] * 100),
                    'active' => true,
                ]);
                if ($saved) {
                    $result['created']++;
                    $result['links'][] = $c;
                } else {
                    $result['errors'][] = "Не удалось сохранить связь для элемента #{$element['id']}";
                }
            }
        }

        return $result;
    }

    /**
     * Карта всех существующих связей (для проверки дублей)
     */
    private function getAllExistingLinks(): array
    {
        $map = [];
        foreach ($this->dataProvider->getAllLinks() as $l) {
            $map[$this->linkKey(
                (string)($l['block_code'] ?? ''),
                (string)$l['source_type'], (int)$l['source_id'],
                (string)$l['target_type'], (int)$l['target_id']
            )] = true;
        }
        return $map;
    }

    /**
     * Связанные сущности для показа на странице.
     * Если задан $blockCode - берутся только связи этого блока (и лимит/порог из его правила);
     * авто-подбор в этом случае делается по правилам блока.
     */
    public function getLinkedEntities(string $entityType, int $entityId, int $limit = 5, ?string $blockCode = null): array
    {
        $block = $blockCode !== null ? $this->dataProvider->getBlock($blockCode) : null;
        if ($blockCode !== null && (!$block || empty($block['active']))) {
            return [];
        }
        if ($block && isset($block['max_links'])) {
            $limit = (int)$block['max_links'];
        }

        $savedLinks = $this->dataProvider->getLinks($entityType, $entityId, $blockCode);
        $result = [];
        $seen = [];

        foreach ($savedLinks as $link) {
            $tType = (string)$link['target_type'];
            $tId = (int)$link['target_id'];
            if (isset($seen[$tType . '_' . $tId])) {
                continue;
            }
            $entity = $tType === 'element'
                ? $this->dataProvider->getElement($tId)
                : $this->dataProvider->getSection($tId);
            if (!$entity) {
                continue;
            }
            $seen[$tType . '_' . $tId] = true;
            $result[] = [
                'link_id' => $link['id'],
                'type' => $link['type'],
                'entity_type' => $tType,
                'entity_id' => $tId,
                'name' => $entity['name'],
                'code' => $entity['code'] ?? null,
                'iblock_id' => $link['target_iblock_id'],
                'sort' => $link['sort'] ?? 0,
                'manual' => ($link['strategy'] ?? '') === 'manual',
            ];
        }

        // Ручные выше, затем по убыванию sort (sort = score*100)
        usort($result, fn($a, $b) => [$b['manual'], $b['sort']] <=> [$a['manual'], $a['sort']]);
        $result = array_slice($result, 0, $limit);

        // Дополняем авто-подбором
        if (count($result) < $limit) {
            $opts = ['limit' => $limit - count($result)];
            if ($block) {
                $opts['rule'] = $block;
            }
            foreach ($this->findRelevantLinks($entityType, $entityId, $opts) as $c) {
                if (isset($seen[$c['target_type'] . '_' . $c['target_id']])) {
                    continue;
                }
                $result[] = [
                    'link_id' => null,
                    'type' => 'auto_' . $c['type'],
                    'entity_type' => $c['target_type'],
                    'entity_id' => $c['target_id'],
                    'name' => $c['target_name'],
                    'code' => $c['target_code'],
                    'iblock_id' => $c['iblock_id'],
                    'sort' => (int)round($c['weight'] * 100),
                    'weight' => $c['weight'],
                    'manual' => false,
                ];
            }
        }

        return array_slice($result, 0, $limit);
    }
}
