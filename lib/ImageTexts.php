<?php

namespace Bauer\Perelinkovka;

use Bauer\Perelinkovka\Orm\AnchorTable;

/**
 * Подстановка картинок в тексты статей инфоблока Битрикс (DETAIL_TEXT).
 * Если в статье уже расставлены ссылки, картинки подставляются и в сохранённый исходный текст,
 * чтобы отмена расстановки ссылок не убирала картинки.
 */
class ImageTexts
{
    public const SAVE_DIR = '/upload/article_images';
    public const DEFAULT_MODEL = 'google/gemini-2.5-flash-image';

    /** Настройки вкладки: model, style. Ключ и прокси OpenRouter общие, из настроек модуля. */
    public static function settings(): array
    {
        $saved = Env::store()->read('image_settings');
        return array_replace([
            'model'   => self::DEFAULT_MODEL,
            'style'   => ArticleImages::DEFAULT_STYLE,
        ], is_array($saved) ? array_filter($saved, fn($v) => $v !== '') : []);
    }

    public static function saveSettings(array $s): bool
    {
        return Env::store()->write('image_settings', [
            'model'   => trim((string)($s['model'] ?? '')) ?: self::DEFAULT_MODEL,
            'style'   => trim((string)($s['style'] ?? '')) ?: ArticleImages::DEFAULT_STYLE,
        ]);
    }

    /** Статьи инфоблока с блоками «[Изображение: ...]»: [['id','name','blocks'], ...]. */
    public static function findArticles(int $iblockId): array
    {
        \Bitrix\Main\Loader::includeModule('iblock');
        $res = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            ['IBLOCK_ID' => $iblockId, '%DETAIL_TEXT' => '[Изображение:'],
            false,
            false,
            ['ID', 'NAME', 'DETAIL_TEXT']
        );
        $list = [];
        while ($r = $res->Fetch()) {
            $n = count(ArticleImages::findBlocks((string)$r['DETAIL_TEXT']));
            if ($n > 0) {
                $list[] = ['id' => (int)$r['ID'], 'name' => (string)$r['NAME'], 'blocks' => $n];
            }
        }
        return $list;
    }

    public static function generator(): ArticleImages
    {
        $s = self::settings();
        $key = Env::apiKey();
        if ($key === '') {
            throw new \RuntimeException('Не задан ключ OpenRouter: Настройки продукта → Модули → Перелинковка');
        }
        return new ArticleImages(
            new OpenRouterClient(['api_key' => $key, 'proxy' => Env::proxy()]),
            rtrim($_SERVER['DOCUMENT_ROOT'], '/') . self::SAVE_DIR,
            self::SAVE_DIR,
            ['model' => $s['model'], 'style' => $s['style']]
        );
    }

    /**
     * Обрабатывает одну статью, но не больше $limit картинок за вызов.
     * @return array{done: int, left: int, errors: string[]}
     */
    public static function processArticle(int $id, int $limit = 2): array
    {
        \Bitrix\Main\Loader::includeModule('iblock');
        $el = \CIBlockElement::GetList([], ['ID' => $id], false, false, ['ID', 'DETAIL_TEXT'])->Fetch();
        if (!$el) {
            throw new \RuntimeException('Статья не найдена');
        }
        $gen = self::generator();
        $r = $gen->process((string)$el['DETAIL_TEXT'], $limit);
        if ($r['done'] > 0) {
            $ok = (new \CIBlockElement())->Update($id, ['DETAIL_TEXT' => $r['text'], 'DETAIL_TEXT_TYPE' => 'html'], false, true, false);
            if (!$ok) {
                throw new \RuntimeException('Не удалось сохранить статью');
            }
            self::updateStoredAnchors($id, $gen);
        }
        return [
            'done'   => $r['done'],
            'left'   => count(ArticleImages::findBlocks($r['text'])),
            'errors' => $r['errors'],
        ];
    }

    private static function updateStoredAnchors(int $id, ArticleImages $gen): void
    {
        $row = AnchorTable::getList(['filter' => ['=ELEMENT_ID' => $id], 'select' => ['ID', 'ORIGINAL_TEXT', 'TEXT'], 'limit' => 1])->fetch();
        if (!$row) {
            return;
        }
        // картинки уже на диске (имя файла зависит от описания), поэтому повторных запросов к OpenRouter нет
        AnchorTable::update($row['ID'], [
            'ORIGINAL_TEXT' => $gen->process((string)$row['ORIGINAL_TEXT'])['text'],
            'TEXT'          => $gen->process((string)$row['TEXT'])['text'],
        ]);
    }
}
