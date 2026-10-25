<?php

namespace Bauer\Perelinkovka;

/**
 * Interface DataProviderInterface
 * 
 * Абстракция для получения данных инфоблоков, разделов и элементов.
 * Позволяет работать как с тестовыми JSON-данными, так и с БД Битрикс.
 */
interface DataProviderInterface
{
    /**
     * Получить список инфоблоков
     * @param array $filter - фильтр (id, code и т.д.)
     * @return array
     */
    public function getIBlocks(array $filter = []): array;

    /**
     * Получить инфоблок по ID
     * @param int $id
     * @return array|null
     */
    public function getIBlock(int $id): ?array;

    /**
     * Получить список разделов
     * @param array $filter - фильтр (iblock_id, parent_id, id и т.д.)
     * @return array
     */
    public function getSections(array $filter = []): array;

    /**
     * Получить раздел по ID
     * @param int $id
     * @return array|null
     */
    public function getSection(int $id): ?array;

    /**
     * Получить список элементов
     * @param array $filter - фильтр (iblock_id, section_id, id, active и т.д.)
     * @return array
     */
    public function getElements(array $filter = []): array;

    /**
     * Сколько элементов подходит под фильтр (те же ключи, что у getElements)
     * @param array $filter
     * @return int
     */
    public function countElements(array $filter = []): int;

    /**
     * Получить элемент по ID
     * @param int $id
     * @return array|null
     */
    public function getElement(int $id): ?array;

    /**
     * Получить связанные ссылки для сущности
     * @param string $entityType - 'element' или 'section'
     * @param int $entityId
     * @param string|null $blockCode - код блока перелинковки (null = все блоки)
     * @return array
     */
    public function getLinks(string $entityType, int $entityId, ?string $blockCode = null): array;

    /**
     * Получить все связи с опциональным фильтром (для проверки дублей, списка, статистики)
     * @param array $filter
     * @return array
     */
    public function getAllLinks(array $filter = []): array;

    /**
     * Получить блоки перелинковки (правила)
     * @param array $filter - например ['active' => true]
     * @return array
     */
    public function getBlocks(array $filter = []): array;

    /**
     * Получить блок по символьному коду
     * @param string $code
     * @return array|null
     */
    public function getBlock(string $code): ?array;

    /**
     * Создать или обновить блок (ключ - code)
     * @param array $block
     * @return bool
     */
    public function saveBlock(array $block): bool;

    /**
     * Удалить блок по коду
     * @param string $code
     * @return bool
     */
    public function deleteBlock(string $code): bool;

    /**
     * Сохранить связь
     * @param array $linkData - данные связи
     * @return bool
     */
    public function saveLink(array $linkData): bool;

    /**
     * Изменить поля связи (active, sort и т.д.)
     * @param int $linkId
     * @param array $fields
     * @return bool false, если связь не найдена или эталонная
     */
    public function updateLink(int $linkId, array $fields): bool;

    /**
     * Удалить связь
     * @param int $linkId
     * @return bool
     */
    public function deleteLink(int $linkId): bool;
}
