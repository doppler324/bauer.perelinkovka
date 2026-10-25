<?php
namespace Bauer\Perelinkovka\Orm;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;
use Bitrix\Main\Type\DateTime;

/** Векторы (эмбеддинги) целей: ключ "тип:id", хэш текста и модели, вектор в base64 (float32). */
class VectorTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'b_bauer_perelinkovka_vector';
    }

    public static function getMap(): array
    {
        return [
            new Fields\IntegerField('ID', ['primary' => true, 'autocomplete' => true]),
            new Fields\StringField('VKEY', ['required' => true, 'size' => 64]),
            new Fields\StringField('HASH', ['required' => true, 'size' => 32]),
            new Fields\TextField('VECTOR'),
            new Fields\DatetimeField('UPDATED_AT', ['default_value' => fn() => new DateTime()]),
        ];
    }

    /** Создаёт таблицу, если модуль обновлён без переустановки. */
    public static function ensure(): void
    {
        $conn = \Bitrix\Main\Application::getConnection();
        if (!$conn->isTableExists(static::getTableName())) {
            static::getEntity()->createDbTable();
            try {
                $conn->queryExecute('CREATE UNIQUE INDEX ux_bauer_pere_vector ON ' . static::getTableName() . ' (VKEY)');
            } catch (\Throwable $e) {
            }
        }
    }
}
