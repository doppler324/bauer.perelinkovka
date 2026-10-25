<?php

namespace Bauer\Perelinkovka;

/**
 * AnchorEngine с кэшем фраз цели в пределах запроса.
 * При массовой расстановке одни и те же цели проверяются в сотнях текстов, а фразы цели от текста не зависят.
 */
class CachedAnchorEngine extends AnchorEngine
{
    /** @var array<string,array> */
    private static array $phraseCache = [];

    public function phrasesForTarget(array $target): array
    {
        $key = ($target['type'] ?? 'element') . ':' . (int)$target['id'];
        return self::$phraseCache[$key] ??= parent::phrasesForTarget($target);
    }
}
