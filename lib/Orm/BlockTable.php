<?php
namespace Bauer\Perelinkovka\Orm;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;

/** Блоки перелинковки (правила): названия задаются пользователем. */
class BlockTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'b_bauer_perelinkovka_block';
    }

    public static function getMap(): array
    {
        return [
            new Fields\IntegerField('ID', ['primary' => true, 'autocomplete' => true]),
            new Fields\StringField('CODE', ['required' => true, 'unique' => true, 'size' => 100]),
            new Fields\StringField('NAME', ['required' => true, 'size' => 255]),
            new Fields\BooleanField('ACTIVE', ['values' => ['N', 'Y'], 'default_value' => 'Y']),
            new Fields\IntegerField('SORT', ['default_value' => 100]),
            new Fields\StringField('SOURCE_TYPE', ['default_value' => 'element', 'size' => 20]),
            new Fields\IntegerField('SOURCE_IBLOCK_ID'),
            new Fields\StringField('TARGET_TYPE', ['default_value' => 'element', 'size' => 20]),
            new Fields\TextField('TARGET_IBLOCK_IDS', ['serialized' => true]),
            new Fields\IntegerField('MAX_LINKS', ['default_value' => 5]),
            new Fields\FloatField('MIN_SCORE', ['default_value' => 0.15]),
            new Fields\FloatField('BALANCE', ['default_value' => 0.5]),
            new Fields\TextField('STRATEGIES', ['serialized' => true]),
        ];
    }
}
