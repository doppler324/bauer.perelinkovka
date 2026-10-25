<?php
namespace Bauer\Perelinkovka\Orm;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;
use Bitrix\Main\Type\DateTime;

/** Тексты статей с расставленными ссылками: исходный текст хранится, чтобы расстановку можно было отменить. */
class AnchorTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'b_bauer_perelinkovka_anchor';
    }

    public static function getMap(): array
    {
        return [
            new Fields\IntegerField('ID', ['primary' => true, 'autocomplete' => true]),
            new Fields\IntegerField('ELEMENT_ID', ['required' => true]),
            new Fields\TextField('ORIGINAL_TEXT'),
            new Fields\TextField('TEXT'),
            new Fields\TextField('LINKS', ['serialized' => true]),
            new Fields\DatetimeField('UPDATED_AT', ['default_value' => fn() => new DateTime()]),
        ];
    }
}
