<?php

namespace Bauer\Perelinkovka;

/**
 * Class JsonDataProvider
 * 
 * Провайдер данных из JSON-файлов для тестирования модуля без Битрикс.
 * Эталонные связи читает из links.json (не пишет туда).
 * Созданные/удалённые через админку — хранит в saved_links.json.
 */
class JsonDataProvider implements DataProviderInterface
{
    /** @var string путь к директории с JSON-файлами */
    private string $dataDir;

    /** @var array|null кэш загруженных инфоблоков */
    private ?array $iblocks = null;

    /** @var array|null кэш загруженных разделов */
    private ?array $sections = null;

    /** @var array|null кэш загруженных элементов */
    private ?array $elements = null;

    /** @var array|null кэш эталонных связей из links.json */
    private ?array $etalonLinks = null;

    /** @var array|null кэш пользовательских связей из saved_links.json */
    private ?array $savedLinks = null;

    /**
     * @param string $dataDir - путь к папке с JSON-файлами данных
     */
    public function __construct(string $dataDir)
    {
        $this->dataDir = rtrim($dataDir, '/\\');
    }

    /**
     * Загружает данные из JSON-файла
     * @param string $filename - имя файла (без пути)
     * @return array
     */
    private function loadData(string $filename): array
    {
        $filePath = $this->dataDir . DIRECTORY_SEPARATOR . $filename;
        
        if (!file_exists($filePath)) {
            return [];
        }

        $content = file_get_contents($filePath);
        $data = json_decode($content, true);

        return is_array($data) ? $data : [];
    }

    /**
     * Применяет фильтр к массиву данных
     * @param array $items - массив данных
     * @param array $filter - фильтр [поле => значение]
     * @return array
     */
    private function applyFilter(array $items, array $filter): array
    {
        if (empty($filter)) {
            return $items;
        }

        return array_filter($items, function ($item) use ($filter) {
            foreach ($filter as $key => $value) {
                // Поддержка вложенных ключей через точку (напр. "section.id")
                $keys = explode('.', $key);
                $actualValue = $item;

                foreach ($keys as $k) {
                    if (!is_array($actualValue) || !array_key_exists($k, $actualValue)) {
                        return false;
                    }
                    $actualValue = $actualValue[$k];
                }

                if (is_array($value)) {
                    // Если значение фильтра - массив, проверяем вхождение
                    if (!in_array($actualValue, $value, false)) {
                        return false;
                    }
                } else {
                    if ($actualValue != $value) {
                        return false;
                    }
                }
            }
            return true;
        });
    }

    /**
     * @inheritDoc
     */
    public function getIBlocks(array $filter = []): array
    {
        if ($this->iblocks === null) {
            $this->iblocks = $this->loadData('iblocks.json');
        }

        $result = $this->applyFilter($this->iblocks, $filter);
        return array_values($result);
    }

    /**
     * @inheritDoc
     */
    public function getIBlock(int $id): ?array
    {
        $iblocks = $this->getIBlocks(['id' => $id]);
        return $iblocks[0] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function getSections(array $filter = []): array
    {
        if ($this->sections === null) {
            $this->sections = $this->loadData('sections.json');
        }

        $result = $this->applyFilter($this->sections, $filter);
        return array_values($result);
    }

    /**
     * @inheritDoc
     */
    public function getSection(int $id): ?array
    {
        $sections = $this->getSections(['id' => $id]);
        return $sections[0] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function getElements(array $filter = []): array
    {
        if ($this->elements === null) {
            $this->elements = $this->loadData('elements.json');
        }

        // служебные ключи: has_text — только элементы с текстом, with_text — отдать текст (здесь он всегда есть)
        $hasText = !empty($filter['has_text']);
        unset($filter['has_text'], $filter['with_text']);

        $result = $this->applyFilter($this->elements, $filter);
        if ($hasText) {
            $result = array_filter($result, fn($e) => trim((string)($e['text'] ?? '')) !== '');
        }
        return array_values($result);
    }

    /**
     * @inheritDoc
     */
    public function countElements(array $filter = []): int
    {
        return count($this->getElements($filter));
    }

    /**
     * @inheritDoc
     */
    public function getElement(int $id): ?array
    {
        $elements = $this->getElements(['id' => $id]);
        return $elements[0] ?? null;
    }

    /**
     * Возвращает все связи (эталонные + пользовательские), объединённые и отфильтрованные.
     * @inheritDoc
     */
    public function getLinks(string $entityType, int $entityId, ?string $blockCode = null): array
    {
        // Загружаем эталонные связи из links.json (только для чтения)
        if ($this->etalonLinks === null) {
            $this->etalonLinks = $this->loadData('links.json');
        }

        // Загружаем пользовательские связи из saved_links.json
        if ($this->savedLinks === null) {
            $this->savedLinks = $this->loadData('saved_links.json');
        }

        // Объединяем оба массива
        $allLinks = array_merge($this->etalonLinks, $this->savedLinks);

        $filter = [
            'source_type' => $entityType,
            'source_id' => $entityId,
            'active' => true
        ];
        if ($blockCode !== null) {
            $filter['block_code'] = $blockCode;
        }
        $result = $this->applyFilter($allLinks, $filter);

        return array_values($result);
    }

    /**
     * Возвращает все ссылки без фильтрации (для статистики/списка).
     * @param array $filter - опциональный фильтр
     * @return array
     */
    public function getAllLinks(array $filter = []): array
    {
        if ($this->etalonLinks === null) {
            $this->etalonLinks = $this->loadData('links.json');
        }
        if ($this->savedLinks === null) {
            $this->savedLinks = $this->loadData('saved_links.json');
        }

        $allLinks = array_merge($this->etalonLinks, $this->savedLinks);

        if (!empty($filter)) {
            $result = $this->applyFilter($allLinks, $filter);
        } else {
            $result = $allLinks;
        }

        return array_values($result);
    }

    /**
     * Сохраняет связь в saved_links.json (пользовательские данные).
     * @inheritDoc
     */
    public function saveLink(array $linkData): bool
    {
        if ($this->savedLinks === null) {
            $this->savedLinks = $this->loadData('saved_links.json');
        }

        // Находим максимальный ID среди обоих файлов, чтобы не было коллизий
        if ($this->etalonLinks === null) {
            $this->etalonLinks = $this->loadData('links.json');
        }

        $maxId = 0;
        foreach ($this->etalonLinks as $link) {
            if (($link['id'] ?? 0) > $maxId) {
                $maxId = $link['id'];
            }
        }
        foreach ($this->savedLinks as $link) {
            if (($link['id'] ?? 0) > $maxId) {
                $maxId = $link['id'];
            }
        }

        $linkData['id'] = $maxId + 1;
        $linkData['created_at'] = $linkData['created_at'] ?? date('c');
        $linkData['block_code'] = $linkData['block_code'] ?? '';
        
        // Автоматически добавляем strategy и score, если не указаны
        if (!isset($linkData['strategy'])) {
            $linkData['strategy'] = 'auto';
        }
        if (!isset($linkData['score'])) {
            $linkData['score'] = 0.5;
        }
        if (!isset($linkData['sort'])) {
            $linkData['sort'] = (int)round($linkData['score'] * 100);
        }
        
        $this->savedLinks[] = $linkData;

        return $this->saveSavedLinksToFile();
    }

    /**
     * Изменяет поля пользовательской связи (эталонные из links.json не меняются).
     * @inheritDoc
     */
    public function updateLink(int $linkId, array $fields): bool
    {
        if ($this->savedLinks === null) {
            $this->savedLinks = $this->loadData('saved_links.json');
        }
        foreach ($this->savedLinks as $i => $link) {
            if ((int)($link['id'] ?? 0) === $linkId) {
                unset($fields['id']);
                $this->savedLinks[$i] = array_merge($link, $fields, ['updated_at' => date('c')]);
                return $this->saveSavedLinksToFile();
            }
        }
        return false;
    }

    /**
     * Удаляет связь из saved_links.json.
     * Если связь из эталонного links.json — не удаляет, а возвращает false.
     * @inheritDoc
     */
    public function deleteLink(int $linkId): bool
    {
        if ($this->savedLinks === null) {
            $this->savedLinks = $this->loadData('saved_links.json');
        }

        // Проверяем, не эталонная ли это связь
        if ($this->etalonLinks === null) {
            $this->etalonLinks = $this->loadData('links.json');
        }

        foreach ($this->etalonLinks as $link) {
            if ((int)($link['id'] ?? 0) === $linkId) {
                return false; // эталонные связи не удаляем
            }
        }

        // Ищем и удаляем из пользовательских
        foreach ($this->savedLinks as $index => $link) {
            if ((int)($link['id'] ?? 0) === $linkId) {
                unset($this->savedLinks[$index]);
                $this->savedLinks = array_values($this->savedLinks);
                return $this->saveSavedLinksToFile();
            }
        }

        return false;
    }

    /**
     * Сохраняет пользовательские связи в saved_links.json
     * @return bool
     */
    private function saveSavedLinksToFile(): bool
    {
        $filePath = $this->dataDir . DIRECTORY_SEPARATOR . 'saved_links.json';
        $json = json_encode($this->savedLinks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        return file_put_contents($filePath, $json, LOCK_EX) !== false;
    }

    /** @var array|null кэш блоков перелинковки */
    private ?array $blocks = null;

    /**
     * @inheritDoc
     */
    public function getBlocks(array $filter = []): array
    {
        if ($this->blocks === null) {
            $this->blocks = $this->loadData('blocks.json');
        }
        return array_values($this->applyFilter($this->blocks, $filter));
    }

    /**
     * @inheritDoc
     */
    public function getBlock(string $code): ?array
    {
        foreach ($this->getBlocks() as $block) {
            if (($block['code'] ?? '') === $code) {
                return $block;
            }
        }
        return null;
    }

    /**
     * @inheritDoc
     */
    public function saveBlock(array $block): bool
    {
        $code = trim((string)($block['code'] ?? ''));
        if ($code === '' || !preg_match('/^[a-z0-9_]+$/i', $code)) {
            return false;
        }
        $this->getBlocks();
        $found = false;
        foreach ($this->blocks as $i => $b) {
            if (($b['code'] ?? '') === $code) {
                $this->blocks[$i] = array_merge($b, $block);
                $found = true;
                break;
            }
        }
        if (!$found) {
            $this->blocks[] = $block;
        }
        return $this->saveBlocksToFile();
    }

    /**
     * @inheritDoc
     */
    public function deleteBlock(string $code): bool
    {
        $this->getBlocks();
        $before = count($this->blocks);
        $this->blocks = array_values(array_filter($this->blocks, fn($b) => ($b['code'] ?? '') !== $code));
        return count($this->blocks) < $before && $this->saveBlocksToFile();
    }

    private function saveBlocksToFile(): bool
    {
        $filePath = $this->dataDir . DIRECTORY_SEPARATOR . 'blocks.json';
        $json = json_encode($this->blocks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        return file_put_contents($filePath, $json, LOCK_EX) !== false;
    }
}
