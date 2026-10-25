<?php

namespace Bauer\Perelinkovka;

/**
 * Class LinksComponent
 * 
 * Компонент для вывода связанных разделов и элементов на странице сайта.
 * Аналог bitrix:component, но без привязки к API Битрикс.
 * 
 * Использование:
 *   $component = new LinksComponent($dataProvider, $linker);
 *   $component->setEntity('element', 1);
 *   echo $component->render();
 */
class LinksComponent
{
    /** @var DataProviderInterface */
    private DataProviderInterface $dataProvider;

    /** @var Linker|null */
    private ?Linker $linker;

    /** @var string тип сущности ('element' или 'section') */
    private string $entityType = 'element';

    /** @var int ID сущности */
    private int $entityId = 0;

    /** @var array параметры отображения */
    private array $params = [];

    /** @var array загруженные связи */
    private array $links = [];

    /** @var array|null исходная сущность */
    private ?array $entity = null;

    public function __construct(
        DataProviderInterface $dataProvider,
        ?Linker $linker = null,
        array $params = []
    ) {
        $this->dataProvider = $dataProvider;
        $this->linker = $linker;
        $this->params = array_merge([
            'block_code'       => '',        // код блока перелинковки; при заданном блоке max_links берётся из блока
            'title'            => 'Связанные материалы',
            'max_links'        => 5,
            'show_weight'      => false,
            'show_type'        => true,
            'show_iblock'      => true,
            'show_empty'       => true,
            'empty_text'       => 'Связанные материалы не найдены.',
            'template'         => 'list',    // list | inline | cards
            'css_class'        => 'bx-perelinkovka-links',
            'target_blank'     => true,
            'link_template'    => '/#entity_type#/#entity_code#/', // для формирования URL
        ], $params);
    }

    /**
     * Устанавливает сущность, для которой ищем связи
     * 
     * @param string $entityType 'element' или 'section'
     * @param int $entityId
     * @return self
     */
    public function setEntity(string $entityType, int $entityId): self
    {
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        return $this;
    }

    /**
     * Загружает связи для текущей сущности
     * @return array
     */
    private function loadLinks(): array
    {
        if ($this->entityType === 'element') {
            $this->entity = $this->dataProvider->getElement($this->entityId);
        } else {
            $this->entity = $this->dataProvider->getSection($this->entityId);
        }

        if (!$this->entity) {
            return [];
        }

        // Сначала загружаем сохранённые связи из DataProvider
        $blockCode = $this->params['block_code'] !== '' ? $this->params['block_code'] : null;
        $block = $blockCode !== null ? $this->dataProvider->getBlock($blockCode) : null;
        if ($blockCode !== null && (!$block || empty($block['active']))) {
            return $this->links = [];
        }
        $maxLinks = (int)($block['max_links'] ?? $this->params['max_links']);
        $savedLinks = $this->dataProvider->getLinks($this->entityType, $this->entityId, $blockCode);

        // Затем — автоматически найденные через Linker (если доступен)
        $autoLinks = [];
        if ($this->linker) {
            $autoOptions = ['limit' => $maxLinks];
            if ($block) {
                $autoOptions['rule'] = $block;
            }
            $autoCandidates = $this->linker->findRelevantLinks($this->entityType, $this->entityId, $autoOptions);

            foreach ($autoCandidates as $ac) {
                // Не дублируем уже сохранённые
                $alreadySaved = false;
                foreach ($savedLinks as $sl) {
                    if ($sl['target_type'] === $ac['target_type']
                        && (int)$sl['target_id'] === (int)$ac['target_id']) {
                        $alreadySaved = true;
                        break;
                    }
                }
                if (!$alreadySaved) {
                    $autoLinks[] = $ac;
                }
            }
        }

        // Сначала ручные, затем по sort; обрезаем после сортировки
        usort($savedLinks, fn($a, $b) => [($b['strategy'] ?? '') === 'manual', $b['sort'] ?? 0]
            <=> [($a['strategy'] ?? '') === 'manual', $a['sort'] ?? 0]);
        $this->links = array_slice(array_merge($savedLinks, $autoLinks), 0, $maxLinks);

        return $this->links;
    }

    /**
     * Формирует URL для целевой сущности
     * @param string $type
     * @param array $entity
     * @return string
     */
    private function buildUrl(string $type, array $entity): string
    {
        $code = $entity['code'] ?? $entity['id'];
        return str_replace(
            ['#entity_type#', '#entity_code#', '#entity_id#'],
            [$type === 'element' ? 'products' : 'sections', $code, $entity['id']],
            $this->params['link_template']
        );
    }

    /**
     * Возвращает CSS класс для типа связи
     * @param array $link
     * @return string
     */
    private function getTypeClass(array $link): string
    {
        $type = $this->normalizeType($link['type'] ?? 'weak');
        return match ($type) {
            'strong' => 'type-strong',
            'medium' => 'type-medium',
            default  => 'type-weak',
        };
    }

    /** Убирает префикс auto_ у авто-найденных связей */
    private function normalizeType(string $type): string
    {
        return str_starts_with($type, 'auto_') ? substr($type, 5) : $type;
    }

    /**
     * Возвращает русскую подпись типа связи
     * @param array $link
     * @return string
     */
    private function getTypeLabel(array $link): string
    {
        return match ($this->normalizeType($link['type'] ?? 'weak')) {
            'strong'    => 'сильная',
            'medium'    => 'средняя',
            'manual'    => 'ручная',
            'related'   => 'связанная',
            'accessory' => 'аксессуар',
            'section'   => 'раздел',
            default     => 'слабая',
        };
    }

    /**
     * Рендерит компонент в HTML
     * @return string
     */
    public function render(): string
    {
        $links = $this->loadLinks();
        $template = $this->params['template'];

        if (empty($links) && !$this->params['show_empty']) {
            return '';
        }

        $html = '<div class="' . htmlspecialchars($this->params['css_class']) . '">';
        $html .= '<div class="bx-pere-header">';
        $html .= '<h3>' . htmlspecialchars($this->params['title']) . '</h3>';
        if ($this->entity) {
            $html .= '<span class="bx-pere-entity">'
                . htmlspecialchars($this->entity['name'] ?? '')
                . '</span>';
        }
        $html .= '</div>';

        if (empty($links)) {
            $html .= '<p class="bx-pere-empty">'
                . htmlspecialchars($this->params['empty_text'])
                . '</p>';
        } else {
            $html .= '<ul class="bx-pere-list bx-pere-list-' . $template . '">';
            foreach ($links as $link) {
                $html .= $this->renderLink($link, $template);
            }
            $html .= '</ul>';
        }

        $html .= '</div>';

        // Добавляем базовые стили (один раз)
        $html .= $this->getStyles();

        return $html;
    }

    /**
     * Рендерит одну ссылку
     * @param array $link
     * @param string $template
     * @return string
     */
    private function renderLink(array $link, string $template): string
    {
        // Определяем целевую сущность
        $targetType = $link['target_type'] ?? 'element';
        $targetId = $link['target_id'] ?? 0;

        $target = $targetType === 'element'
            ? $this->dataProvider->getElement($targetId)
            : $this->dataProvider->getSection($targetId);

        if (!$target) {
            return '';
        }

        $url = $this->buildUrl($targetType, $target);
        $targetAttr = $this->params['target_blank'] ? ' target="_blank" rel="noopener"' : '';
        $typeClass = $this->getTypeClass($link);
        $typeLabel = $this->getTypeLabel($link);

        // Иконка типа сущности
        $entityIcon = $targetType === 'element' ? '📄' : '📁';

        if ($template === 'inline') {
            return sprintf(
                '<li class="bx-pere-item bx-pere-inline %s">'
                . '<a href="%s"%s>%s %s</a>'
                . '%s%s'
                . '</li>',
                $typeClass,
                htmlspecialchars($url),
                $targetAttr,
                $entityIcon,
                htmlspecialchars($target['name'] ?? '#' . $targetId),
                $this->params['show_type']
                    ? ' <span class="bx-pere-badge">' . $typeLabel . '</span>'
                    : '',
                $this->params['show_weight'] && isset($link['weight'])
                    ? ' <span class="bx-pere-weight">(' . number_format($link['weight'], 2) . ')</span>'
                    : ''
            );
        }

        if ($template === 'cards') {
            $desc = mb_substr($target['description'] ?? '', 0, 100);
            $iblock = $this->dataProvider->getIBlock($target['iblock_id'] ?? 0);

            return sprintf(
                '<li class="bx-pere-item bx-pere-card %s">'
                . '<a href="%s"%s>'
                . '<div class="bx-pere-card-header">'
                . '<span class="bx-pere-icon">%s</span>'
                . '<span class="bx-pere-name">%s</span>'
                . '</div>'
                . '<div class="bx-pere-card-body">'
                . '<p>%s</p>'
                . '</div>'
                . '<div class="bx-pere-card-footer">'
                . '%s%s%s'
                . '</div>'
                . '</a>'
                . '</li>',
                $typeClass,
                htmlspecialchars($url),
                $targetAttr,
                $entityIcon,
                htmlspecialchars($target['name'] ?? '#' . $targetId),
                htmlspecialchars($desc) . (mb_strlen($target['description'] ?? '') > 100 ? '...' : ''),
                $this->params['show_iblock'] && $iblock
                    ? '<span class="bx-pere-iblock">' . htmlspecialchars($iblock['name']) . '</span>'
                    : '',
                $this->params['show_type']
                    ? '<span class="bx-pere-badge">' . $typeLabel . '</span>'
                    : '',
                $this->params['show_weight'] && isset($link['weight'])
                    ? '<span class="bx-pere-weight">вес: ' . number_format($link['weight'], 2) . '</span>'
                    : ''
            );
        }

        // По умолчанию — list
        $iblock = $this->dataProvider->getIBlock($target['iblock_id'] ?? 0);

        return sprintf(
            '<li class="bx-pere-item bx-pere-list-item %s">'
            . '<a href="%s"%s>'
            . '<span class="bx-pere-icon">%s</span> '
            . '<span class="bx-pere-name">%s</span>'
            . '</a>'
            . '<div class="bx-pere-meta">'
            . '%s%s%s'
            . '</div>'
            . '</li>',
            $typeClass,
            htmlspecialchars($url),
            $targetAttr,
            $entityIcon,
            htmlspecialchars($target['name'] ?? '#' . $targetId),
            $this->params['show_type']
                ? '<span class="bx-pere-badge">' . $typeLabel . '</span>'
                : '',
            $this->params['show_iblock'] && $iblock
                ? '<span class="bx-pere-iblock">' . htmlspecialchars($iblock['name']) . '</span>'
                : '',
            $this->params['show_weight'] && isset($link['weight'])
                ? '<span class="bx-pere-weight">вес: ' . number_format($link['weight'], 2) . '</span>'
                : ''
        );
    }

    /**
     * Базовые CSS-стили
     * @return string
     */
    private function getStyles(): string
    {
        static $stylesLoaded = false;
        if ($stylesLoaded) return '';

        $stylesLoaded = true;
        return '<style>
.bx-perelinkovka-links {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
    margin: 16px 0;
    padding: 16px;
    background: #fff;
    border: 1px solid #e8e8e8;
    border-radius: 8px;
}
.bx-pere-header {
    display: flex;
    align-items: baseline;
    gap: 8px;
    margin-bottom: 12px;
    padding-bottom: 8px;
    border-bottom: 1px solid #f0f0f0;
}
.bx-pere-header h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    color: #333;
}
.bx-pere-entity {
    font-size: 12px;
    color: #999;
}
.bx-pere-empty {
    color: #999;
    font-size: 13px;
    padding: 8px 0;
}
.bx-pere-list {
    list-style: none;
    margin: 0;
    padding: 0;
}
.bx-pere-item {
    padding: 6px 0;
    border-bottom: 1px solid #f5f5f5;
}
.bx-pere-item:last-child {
    border-bottom: none;
}
.bx-pere-item a {
    text-decoration: none;
    color: #2a5885;
    font-size: 14px;
}
.bx-pere-item a:hover {
    text-decoration: underline;
    color: #1a3a5a;
}
.bx-pere-meta {
    display: flex;
    gap: 8px;
    margin-top: 2px;
    font-size: 11px;
    color: #888;
}
.bx-pere-badge {
    display: inline-block;
    padding: 1px 6px;
    border-radius: 3px;
    font-size: 10px;
    font-weight: 500;
    text-transform: lowercase;
}
.type-strong .bx-pere-badge {
    background: #e8f5e9;
    color: #2e7d32;
}
.type-medium .bx-pere-badge {
    background: #fff3e0;
    color: #e65100;
}
.type-weak .bx-pere-badge {
    background: #f5f5f5;
    color: #999;
}
.type-strong { border-left: 3px solid #4caf50; padding-left: 8px; }
.type-medium { border-left: 3px solid #ff9800; padding-left: 8px; }
.type-weak { border-left: 3px solid #e0e0e0; padding-left: 8px; }
.bx-pere-iblock {
    color: #aaa;
}
.bx-pere-weight {
    color: #bbb;
}
.bx-pere-icon {
    font-size: 14px;
}

/* Карточки */
.bx-pere-card {
    border: 1px solid #eee;
    border-radius: 6px;
    padding: 0;
    margin-bottom: 8px;
    overflow: hidden;
}
.bx-pere-card a {
    display: block;
    padding: 10px;
}
.bx-pere-card-header {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 4px;
}
.bx-pere-card-body p {
    margin: 4px 0;
    font-size: 12px;
    color: #666;
    line-height: 1.4;
}
.bx-pere-card-footer {
    display: flex;
    gap: 8px;
    margin-top: 6px;
    font-size: 11px;
    color: #888;
}

/* Inline */
.bx-pere-inline {
    display: inline-block;
    margin-right: 8px;
    padding: 4px 10px;
    background: #f8f9fa;
    border-radius: 4px;
    border-left: none;
}
</style>';
    }
}
