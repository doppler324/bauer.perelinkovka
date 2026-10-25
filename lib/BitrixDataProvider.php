<?php
namespace Bauer\Perelinkovka;

use Bauer\Perelinkovka\Orm\AnchorTable;
use Bauer\Perelinkovka\Orm\BlockTable;
use Bauer\Perelinkovka\Orm\LinkTable;
use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\IblockTable;
use Bitrix\Iblock\SectionTable;
use Bitrix\Main\Config\Option;

/**
 * Провайдер данных на D7 ORM.
 * Ключевые слова элементов берутся из свойства, код которого задан в настройках модуля (по умолчанию KEYWORDS).
 * Тексты: 'text' отдаётся при фильтре with_text/has_text/id; для статей с расставленными ссылками это ИСХОДНЫЙ текст
 * (текст со ссылками хранится в таблице анкоров и записан в DETAIL_TEXT).
 */
class BitrixDataProvider implements DataProviderInterface
{
    private const MODULE = 'bauer.perelinkovka';

    private function elementRow(array $r, bool $withText): array
    {
        $text = (string)$r['DETAIL_TEXT'];
        $row = [
            'id' => (int)$r['ID'], 'iblock_id' => (int)$r['IBLOCK_ID'], 'section_id' => (int)$r['IBLOCK_SECTION_ID'],
            'name' => $r['NAME'], 'code' => $r['CODE'], 'active' => $r['ACTIVE'] === 'Y', 'keywords' => [],
            'description' => mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($r['PREVIEW_TEXT'] . ' ' . $text))), 0, 2000),
        ];
        if ($withText) {
            $row['text'] = $text;
        }
        return $row;
    }

    private function sectionRow(array $r): array
    {
        return [
            'id' => (int)$r['ID'], 'iblock_id' => (int)$r['IBLOCK_ID'], 'parent_id' => (int)$r['IBLOCK_SECTION_ID'],
            'name' => $r['NAME'], 'code' => $r['CODE'], 'active' => $r['ACTIVE'] === 'Y', 'keywords' => [],
            'description' => mb_substr(trim(strip_tags((string)$r['DESCRIPTION'])), 0, 2000),
        ];
    }

    public function getIBlocks(array $filter = []): array
    {
        $f = isset($filter['id']) ? ['=ID' => $filter['id']] : [];
        return array_map(fn($r) => [
            'id' => (int)$r['ID'], 'name' => $r['NAME'], 'code' => $r['CODE'],
            'detail_page_url' => (string)$r['DETAIL_PAGE_URL'], 'section_page_url' => (string)$r['SECTION_PAGE_URL'],
        ], IblockTable::getList(['filter' => $f, 'select' => ['ID', 'NAME', 'CODE', 'DETAIL_PAGE_URL', 'SECTION_PAGE_URL'], 'order' => ['SORT' => 'ASC', 'ID' => 'ASC']])->fetchAll());
    }

    public function getIBlock(int $id): ?array
    {
        return $this->getIBlocks(['id' => $id])[0] ?? null;
    }

    public function getSections(array $filter = []): array
    {
        $f = [];
        if (isset($filter['id'])) $f['=ID'] = $filter['id'];
        if (isset($filter['iblock_id'])) $f['=IBLOCK_ID'] = $filter['iblock_id'];
        if (isset($filter['active'])) $f['=ACTIVE'] = $filter['active'] ? 'Y' : 'N';
        return array_map([$this, 'sectionRow'], SectionTable::getList([
            'filter' => $f,
            'select' => ['ID', 'IBLOCK_ID', 'IBLOCK_SECTION_ID', 'NAME', 'CODE', 'ACTIVE', 'DESCRIPTION'],
            'order' => ['LEFT_MARGIN' => 'ASC', 'ID' => 'ASC'],
        ])->fetchAll());
    }

    public function getSection(int $id): ?array
    {
        return $this->getSections(['id' => $id])[0] ?? null;
    }

    private function elementFilter(array $filter): array
    {
        $f = [];
        if (isset($filter['id'])) $f['=ID'] = $filter['id'];
        if (isset($filter['iblock_id'])) $f['=IBLOCK_ID'] = $filter['iblock_id'];
        if (isset($filter['section_id'])) $f['=IBLOCK_SECTION_ID'] = $filter['section_id'];
        if (isset($filter['active'])) $f['=ACTIVE'] = $filter['active'] ? 'Y' : 'N';
        if (!empty($filter['has_text'])) {
            $f[] = ['!=DETAIL_TEXT' => null];
            $f[] = ['!=DETAIL_TEXT' => ''];
        }
        return $f;
    }

    public function getElements(array $filter = []): array
    {
        $withText = !empty($filter['with_text']) || !empty($filter['has_text']) || isset($filter['id']);
        $rows = ElementTable::getList([
            'filter' => $this->elementFilter($filter),
            'select' => ['ID', 'IBLOCK_ID', 'IBLOCK_SECTION_ID', 'NAME', 'CODE', 'ACTIVE', 'PREVIEW_TEXT', 'DETAIL_TEXT'],
            'order' => ['ID' => 'ASC'],
        ])->fetchAll();

        $originals = $this->originalTexts(array_column($rows, 'ID'));
        $result = [];
        foreach ($rows as $r) {
            if (isset($originals[(int)$r['ID']])) {
                $r['DETAIL_TEXT'] = $originals[(int)$r['ID']];
            }
            $result[(int)$r['ID']] = $this->elementRow($r, $withText);
        }
        $this->attachKeywords($result);
        return array_values($result);
    }

    public function getArticleList(int $iblockId): array
    {
        $rows = ElementTable::getList([
            'filter' => $this->elementFilter(['iblock_id' => $iblockId, 'has_text' => true]),
            'select' => ['ID', 'IBLOCK_ID', 'IBLOCK_SECTION_ID', 'NAME'],
            'order' => ['ID' => 'ASC'],
        ])->fetchAll();
        return array_map(fn($r) => ['id' => (int)$r['ID'], 'iblock_id' => (int)$r['IBLOCK_ID'],
            'section_id' => (int)$r['IBLOCK_SECTION_ID'], 'name' => (string)$r['NAME']], $rows);
    }

    public function countElements(array $filter = []): int
    {
        return (int)ElementTable::getCount($this->elementFilter($filter));
    }

    public function getElement(int $id): ?array
    {
        return $this->getElements(['id' => $id])[0] ?? null;
    }

    /** Исходные тексты статей, в которых уже расставлены ссылки: id => текст. */
    private function originalTexts(array $ids): array
    {
        $ids = array_map('intval', $ids);
        if (!$ids || count($ids) > 5000) {
            // большие выборки: берём только элементы, для которых есть запись анкоров
            $res = AnchorTable::getList(['select' => ['ELEMENT_ID', 'ORIGINAL_TEXT']]);
        } else {
            $res = AnchorTable::getList(['filter' => ['@ELEMENT_ID' => $ids], 'select' => ['ELEMENT_ID', 'ORIGINAL_TEXT']]);
        }
        $out = [];
        while ($r = $res->fetch()) {
            $out[(int)$r['ELEMENT_ID']] = (string)$r['ORIGINAL_TEXT'];
        }
        return $out;
    }

    /** Ключевые слова из свойства (код в настройках модуля), по одному запросу на инфоблок. */
    private function attachKeywords(array &$elements): void
    {
        $code = trim((string)Option::get(self::MODULE, 'keywords_property', 'KEYWORDS'));
        if ($code === '' || !$elements || !class_exists('CIBlockElement')) {
            return;
        }
        $byIblock = [];
        foreach ($elements as $id => $e) {
            $byIblock[$e['iblock_id']][] = $id;
        }
        foreach ($byIblock as $iblockId => $ids) {
            foreach (array_chunk($ids, 1000) as $chunk) {
                $res = \CIBlockElement::GetPropertyValues($iblockId, ['ID' => $chunk], false, ['CODE' => $code]);
                while ($row = $res->Fetch()) {
                    $val = $row[$code] ?? ($row[array_key_first(array_diff_key($row, ['IBLOCK_ELEMENT_ID' => 1]))] ?? null);
                    $words = [];
                    foreach ((array)$val as $v) {
                        if (is_array($v)) {
                            $v = $v['TEXT'] ?? '';
                        }
                        foreach (preg_split('/[,;\n]+/u', (string)$v) as $w) {
                            $w = trim($w);
                            if ($w !== '') {
                                $words[] = $w;
                            }
                        }
                    }
                    if (isset($elements[(int)$row['IBLOCK_ELEMENT_ID']])) {
                        $elements[(int)$row['IBLOCK_ELEMENT_ID']]['keywords'] = $words;
                    }
                }
            }
        }
    }

    private function linkRow(array $r): array
    {
        return [
            'id' => (int)$r['ID'], 'block_code' => $r['BLOCK_CODE'], 'source_type' => $r['SOURCE_TYPE'],
            'source_id' => (int)$r['SOURCE_ID'], 'target_type' => $r['TARGET_TYPE'], 'target_id' => (int)$r['TARGET_ID'],
            'source_iblock_id' => (int)$r['SOURCE_IBLOCK_ID'], 'target_iblock_id' => (int)$r['TARGET_IBLOCK_ID'],
            'type' => $r['TYPE'], 'strategy' => $r['STRATEGY'], 'score' => (float)$r['SCORE'],
            'sort' => (int)$r['SORT'], 'active' => $r['ACTIVE'] === 'Y',
        ];
    }

    public function getLinks(string $entityType, int $entityId, ?string $blockCode = null): array
    {
        $f = ['=SOURCE_TYPE' => $entityType, '=SOURCE_ID' => $entityId, '=ACTIVE' => 'Y'];
        if ($blockCode !== null) $f['=BLOCK_CODE'] = $blockCode;
        return array_map([$this, 'linkRow'], LinkTable::getList(['filter' => $f, 'order' => ['SORT' => 'DESC']])->fetchAll());
    }

    public function getAllLinks(array $filter = []): array
    {
        $f = [];
        if (isset($filter['block_code'])) $f['=BLOCK_CODE'] = $filter['block_code'];
        return array_map([$this, 'linkRow'], LinkTable::getList(['filter' => $f])->fetchAll());
    }

    public function saveLink(array $d): bool
    {
        $r = LinkTable::add([
            'BLOCK_CODE' => (string)($d['block_code'] ?? ''), 'SOURCE_TYPE' => $d['source_type'], 'SOURCE_ID' => $d['source_id'],
            'TARGET_TYPE' => $d['target_type'], 'TARGET_ID' => $d['target_id'],
            'SOURCE_IBLOCK_ID' => $d['source_iblock_id'] ?? 0, 'TARGET_IBLOCK_ID' => $d['target_iblock_id'] ?? 0,
            'TYPE' => $d['type'] ?? '', 'STRATEGY' => $d['strategy'] ?? 'auto', 'SCORE' => $d['score'] ?? 0.5,
            'SORT' => $d['sort'] ?? 100, 'ACTIVE' => !empty($d['active']) ? 'Y' : 'N',
        ]);
        return $r->isSuccess();
    }

    public function updateLink(int $linkId, array $fields): bool
    {
        $map = ['active' => 'ACTIVE', 'sort' => 'SORT', 'score' => 'SCORE', 'type' => 'TYPE'];
        $data = [];
        foreach ($map as $k => $col) {
            if (array_key_exists($k, $fields)) {
                $data[$col] = $k === 'active' ? ($fields[$k] ? 'Y' : 'N') : $fields[$k];
            }
        }
        return $data && LinkTable::update($linkId, $data)->isSuccess();
    }

    public function deleteLink(int $linkId): bool
    {
        return LinkTable::delete($linkId)->isSuccess();
    }

    private function blockRow(array $r): array
    {
        return [
            'code' => $r['CODE'], 'name' => $r['NAME'], 'active' => $r['ACTIVE'] === 'Y', 'sort' => (int)$r['SORT'],
            'source_type' => $r['SOURCE_TYPE'], 'source_iblock_id' => (int)$r['SOURCE_IBLOCK_ID'],
            'target_type' => $r['TARGET_TYPE'], 'target_iblock_ids' => (array)$r['TARGET_IBLOCK_IDS'],
            'max_links' => (int)$r['MAX_LINKS'], 'min_score' => (float)$r['MIN_SCORE'], 'balance' => (float)$r['BALANCE'], 'strategies' => (array)$r['STRATEGIES'],
        ];
    }

    public function getBlocks(array $filter = []): array
    {
        $f = isset($filter['active']) ? ['=ACTIVE' => $filter['active'] ? 'Y' : 'N'] : [];
        return array_map([$this, 'blockRow'], BlockTable::getList(['filter' => $f, 'order' => ['SORT' => 'ASC']])->fetchAll());
    }

    public function getBlock(string $code): ?array
    {
        $r = BlockTable::getList(['filter' => ['=CODE' => $code]])->fetch();
        return $r ? $this->blockRow($r) : null;
    }

    public function saveBlock(array $b): bool
    {
        $fields = [
            'CODE' => $b['code'], 'NAME' => $b['name'] ?? $b['code'], 'ACTIVE' => !empty($b['active']) ? 'Y' : 'N',
            'SORT' => $b['sort'] ?? 100, 'SOURCE_TYPE' => $b['source_type'] ?? 'element',
            'SOURCE_IBLOCK_ID' => $b['source_iblock_id'] ?? 0, 'TARGET_TYPE' => $b['target_type'] ?? 'element',
            'TARGET_IBLOCK_IDS' => $b['target_iblock_ids'] ?? [], 'MAX_LINKS' => $b['max_links'] ?? 5,
            'MIN_SCORE' => $b['min_score'] ?? 0.15, 'BALANCE' => $b['balance'] ?? 0.5, 'STRATEGIES' => $b['strategies'] ?? ['keyword'],
        ];
        $row = BlockTable::getList(['filter' => ['=CODE' => $b['code']], 'select' => ['ID']])->fetch();
        $res = $row ? BlockTable::update($row['ID'], $fields) : BlockTable::add($fields);
        return $res->isSuccess();
    }

    public function deleteBlock(string $code): bool
    {
        $row = BlockTable::getList(['filter' => ['=CODE' => $code], 'select' => ['ID']])->fetch();
        return $row ? BlockTable::delete($row['ID'])->isSuccess() : false;
    }
}
