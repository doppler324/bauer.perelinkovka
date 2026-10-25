<?php

namespace Bauer\Perelinkovka;

use Bauer\Perelinkovka\Orm\AnchorTable;

/**
 * Хранилище расставленных ссылок в Битриксе.
 * save() записывает текст со ссылками в DETAIL_TEXT элемента, исходный текст сохраняет в таблице модуля;
 * reset() возвращает исходный текст.
 */
class BitrixAnchorStore implements AnchorStoreInterface
{
    /** @var array<int,array>|null id элемента => ['id','links','updated'] (без текстов) */
    private static ?array $meta = null;

    private function loadMeta(): array
    {
        if (self::$meta === null) {
            self::$meta = [];
            $res = AnchorTable::getList(['select' => ['ID', 'ELEMENT_ID', 'LINKS', 'UPDATED_AT']]);
            while ($r = $res->fetch()) {
                self::$meta[(int)$r['ELEMENT_ID']] = [
                    'row_id'  => (int)$r['ID'],
                    'links'   => (array)$r['LINKS'],
                    'updated' => $r['UPDATED_AT'] ? $r['UPDATED_AT']->format('Y-m-d H:i:s') : '',
                ];
            }
        }
        return self::$meta;
    }

    public function get(int $articleId): ?array
    {
        $meta = $this->loadMeta()[$articleId] ?? null;
        if (!$meta) {
            return null;
        }
        $row = AnchorTable::getByPrimary($meta['row_id'], ['select' => ['TEXT']])->fetch();
        return ['text' => (string)($row['TEXT'] ?? ''), 'links' => $meta['links'], 'updated' => $meta['updated']];
    }

    public function save(int $articleId, string $html, array $links): bool
    {
        \Bitrix\Main\Loader::includeModule('iblock');
        $meta = $this->loadMeta()[$articleId] ?? null;
        $original = null;
        if (!$meta) {
            $el = \CIBlockElement::GetList([], ['ID' => $articleId], false, false, ['ID', 'DETAIL_TEXT'])->Fetch();
            if (!$el) {
                return false;
            }
            $original = (string)$el['DETAIL_TEXT'];
        }

        $fields = ['TEXT' => $html, 'LINKS' => $links];
        if ($meta) {
            $res = AnchorTable::update($meta['row_id'], $fields);
            $rowId = $meta['row_id'];
        } else {
            $res = AnchorTable::add($fields + ['ELEMENT_ID' => $articleId, 'ORIGINAL_TEXT' => $original]);
            $rowId = (int)$res->getId();
        }
        if (!$res->isSuccess()) {
            return false;
        }
        self::$meta[$articleId] = ['row_id' => $rowId, 'links' => $links, 'updated' => date('Y-m-d H:i:s')];
        return $this->writeText($articleId, $html);
    }

    public function reset(int $articleId): bool
    {
        $meta = $this->loadMeta()[$articleId] ?? null;
        if (!$meta) {
            return true;
        }
        $row = AnchorTable::getByPrimary($meta['row_id'], ['select' => ['ORIGINAL_TEXT']])->fetch();
        $ok = $this->writeText($articleId, (string)($row['ORIGINAL_TEXT'] ?? ''));
        if ($ok) {
            AnchorTable::delete($meta['row_id']);
            unset(self::$meta[$articleId]);
        }
        return $ok;
    }

    private function writeText(int $articleId, string $html): bool
    {
        $el = new \CIBlockElement();
        // без обработчиков/индексации поиска не нужно; обычный Update сбросит кэш инфоблока
        return (bool)$el->Update($articleId, ['DETAIL_TEXT' => $html, 'DETAIL_TEXT_TYPE' => 'html'], false, true, false);
    }
}
