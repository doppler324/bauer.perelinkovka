<?php
namespace Bauer\Perelinkovka;

use Bauer\Perelinkovka\Orm\AnchorTable;
use Bauer\Perelinkovka\Orm\LinkTable;

/**
 * Обработчики событий инфоблока: при удалении элемента или раздела связи на него убираются,
 * при изменении - сбрасывается тегированный кэш компонента.
 */
class EventHandler
{
    public static function onElementDelete($id): void
    {
        self::removeLinks('element', (int)$id);
        $res = AnchorTable::getList(['filter' => ['=ELEMENT_ID' => (int)$id], 'select' => ['ID']]);
        while ($row = $res->fetch()) {
            AnchorTable::delete($row['ID']);
        }
    }

    public static function onSectionDelete($id): void
    {
        self::removeLinks('section', (int)$id);
    }

    private static function removeLinks(string $type, int $id): void
    {
        $res = LinkTable::getList(['filter' => [
            'LOGIC' => 'OR',
            ['=SOURCE_TYPE' => $type, '=SOURCE_ID' => $id],
            ['=TARGET_TYPE' => $type, '=TARGET_ID' => $id],
        ], 'select' => ['ID']]);
        while ($row = $res->fetch()) {
            LinkTable::delete($row['ID']);
        }
    }

    public static function onElementChange(&$fields): void
    {
        // Кэш компонента зависит от общего тега: связи пересчитает агент
        global $CACHE_MANAGER;
        if (is_object($CACHE_MANAGER)) {
            $CACHE_MANAGER->ClearByTag('bauer_perelinkovka');
        }
    }
}
