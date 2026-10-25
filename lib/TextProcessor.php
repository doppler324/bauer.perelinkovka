<?php

namespace Bauer\Perelinkovka;

/**
 * Class TextProcessor
 *
 * Разбор текста на слова с позициями и упрощённый стеммер русских окончаний.
 */
class TextProcessor
{
    /** @var string[] окончания, от длинных к коротким */
    private const ENDINGS = [
        'иями', 'ями', 'ами', 'ого', 'его', 'ому', 'ему', 'ыми', 'ими', 'ией',
        'ая', 'яя', 'ое', 'ее', 'ые', 'ие', 'ой', 'ей', 'ий', 'ый', 'ом', 'ем', 'ах', 'ях',
        'ов', 'ев', 'ою', 'ею', 'ую', 'юю', 'их', 'ых', 'ам', 'ям', 'ть',
        'а', 'я', 'ы', 'и', 'е', 'у', 'ю', 'о', 'ь', 'й',
    ];

    /**
     * Слова текста с позициями (в байтах, как у preg_match_all).
     * @return array<int, array{word:string,stem:string,start:int,end:int}>
     */
    public static function words(string $text): array
    {
        if (!preg_match_all('/[\p{L}\p{N}]+/u', $text, $m, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $result = [];
        foreach ($m[0] as [$word, $pos]) {
            $result[] = [
                'word'  => $word,
                'stem'  => self::stem($word),
                'start' => $pos,
                'end'   => $pos + strlen($word),
            ];
        }
        return $result;
    }

    /**
     * Основа слова: нижний регистр, ё→е, отбрасывание окончания.
     */
    public static function stem(string $word): string
    {
        $w = str_replace('ё', 'е', mb_strtolower($word));
        if (!preg_match('/^[а-я]+$/u', $w) || mb_strlen($w) < 5) {
            return $w;
        }
        foreach (self::ENDINGS as $end) {
            $len = mb_strlen($end);
            if (mb_strlen($w) - $len >= 3 && mb_substr($w, -$len) === $end) {
                return mb_substr($w, 0, mb_strlen($w) - $len);
            }
        }
        return $w;
    }
}
