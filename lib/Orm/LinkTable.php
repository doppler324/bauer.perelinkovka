<?php
namespace Bauer\Perelinkovka\Orm;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;
use Bitrix\Main\Type\DateTime;

/** Готовые связи. Считаются агентом/кнопкой, на странице только читаются. */
class LinkTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'b_bauer_perelinkovka_link';
    }

    public static function getMap(): array
    {
        return [
            new Fields\IntegerField('ID', ['primary' => true, 'autocomplete' => true]),
            new Fields\StringField('BLOCK_CODE', ['required' => true, 'size' => 100]),
            new Fields\StringField('SOURCE_TYPE', ['size' => 20]),
            new Fields\IntegerField('SOURCE_ID'),
            new Fields\StringField('TARGET_TYPE', ['size' => 20]),
            new Fields\IntegerField('TARGET_ID'),
            new Fields\IntegerField('SOURCE_IBLOCK_ID'),
            new Fields\IntegerField('TARGET_IBLOCK_ID'),
            new Fields\StringField('TYPE', ['size' => 50]),
            new Fields\StringField('STRATEGY', ['size' => 100]),
            new Fields\FloatField('SCORE'),
            new Fields\IntegerField('SORT', ['default_value' => 100]),
            new Fields\BooleanField('ACTIVE', ['values' => ['N', 'Y'], 'default_value' => 'Y']),
            new Fields\DatetimeField('CREATED_AT', ['default_value' => fn() => new DateTime()]),
        ];
    }
}
