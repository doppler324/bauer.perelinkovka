<?php

namespace Bauer\Perelinkovka;

/**
 * Class AnchorEngine
 *
 * Расстановка ссылок-анкоров внутри текста статьи.
 * Фразы для цели: название, его части из 1–3 слов, ключевые слова, синонимы.
 * Поиск идёт по основам слов в текстовых узлах DOM. Лимиты: одна ссылка на цель
 * и одна на абзац; заголовки h1–h6, ссылки a и code пропускаются.
 */
class AnchorEngine
{
    private const BLOCK_TAGS = ['p', 'li', 'div', 'blockquote', 'td', 'th', 'dd', 'dt', 'section', 'article'];

    /** Настройки по умолчанию (см. AnchorSettings). */
    public const DEFAULTS = [
        'use_name'          => true,
        'use_parts'         => true,
        'use_keywords'      => true,
        'use_synonyms'      => true,
        'max_part_words'    => 3,
        'min_word_len'      => 4,
        'max_links_per_article' => 0, // 0 — без ограничения
        'one_link_per_block' => true,
        'manual_anchors'    => [],    // ручные анкоры: ["тип:id" => ["фраза", ...]]
        'unique_anchors'    => false, // один и тот же текст анкора не повторяется в статье
        'skip_first_block'  => false, // не ставить ссылку в первом абзаце
        'words_per_link'    => 0,     // не чаще одной ссылки на N слов текста (0 — без ограничения)
        'target_elements'   => true,
        'target_sections'   => true,
        'skip_tags'         => ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'a', 'code', 'pre', 'script', 'style'],
        'stop_words'        => ['для', 'при', 'без', 'над', 'под', 'или', 'как', 'что', 'это', 'про'],
    ];

    private SynonymProvider $synonyms;
    private array $opt;

    /** @var array[] инфоблоки по id (шаблоны detail_page_url / section_page_url) */
    private array $iblocks = [];
    /** @var array[] разделы по id (для #SECTION_CODE# элементов) */
    private array $sections = [];

    /**
     * @param array[] $iblocks инфоблоки (как из DataProvider::getIBlocks)
     * @param array[] $sections разделы (как из DataProvider::getSections)
     */
    public function __construct(SynonymProvider $synonyms, array $options = [], array $iblocks = [], array $sections = [])
    {
        $this->synonyms = $synonyms;
        $this->opt = array_replace(self::DEFAULTS, $options);
        foreach ($iblocks as $ib) {
            $this->iblocks[$ib['id']] = $ib;
        }
        foreach ($sections as $sec) {
            $this->sections[$sec['id']] = $sec;
        }
    }

    /**
     * Фразы для цели, длинные первыми.
     * @return array<int, array{phrase:string,source:string,stems:string[]}>
     */
    public function phrasesForTarget(array $target): array
    {
        $found = [];
        $add = function (string $phrase, string $source) use (&$found) {
            $phrase = trim($phrase);
            $words = TextProcessor::words($phrase);
            if (!$words || (count($words) === 1 && mb_strlen($words[0]['word']) < (int)$this->opt['min_word_len'])) {
                return;
            }
            $stems = array_column($words, 'stem');
            $key = implode(' ', $stems);
            if (!isset($found[$key])) {
                $found[$key] = ['phrase' => $phrase, 'source' => $source, 'stems' => $stems];
            }
        };

        $manualKey = ($target['type'] ?? 'element') . ':' . (int)$target['id'];
        foreach ((array)($this->opt['manual_anchors'][$manualKey] ?? []) as $phrase) {
            $add((string)$phrase, 'ручной');
        }

        $name = (string)($target['name'] ?? '');
        if ($this->opt['use_name']) {
            $add($name, 'название');
        }
        if ($this->opt['use_parts']) {
            $nameWords = array_values(array_filter(
                TextProcessor::words($name),
                fn($w) => !in_array(mb_strtolower($w['word']), $this->opt['stop_words'], true)
            ));
            for ($len = max(1, (int)$this->opt['max_part_words']); $len >= 1; $len--) {
                for ($i = 0; $i + $len <= count($nameWords); $i++) {
                    $slice = array_slice($nameWords, $i, $len);
                    $add(implode(' ', array_column($slice, 'word')), 'часть названия');
                }
            }
        }
        if ($this->opt['use_keywords']) {
            foreach ((array)($target['keywords'] ?? []) as $kw) {
                $add((string)$kw, 'ключ');
            }
        }
        if ($this->opt['use_synonyms']) {
            foreach ($found as $item) {
                foreach ($this->synonyms->variants($item['phrase']) as $v) {
                    $add($v, 'синоним');
                }
            }
        }

        $phrases = array_values($found);
        // ручные анкоры идут первыми, остальные — длинные вперёд
        usort($phrases, fn($a, $b) => [$a['source'] !== 'ручной', count($b['stems'])] <=> [$b['source'] !== 'ручной', count($a['stems'])]);
        return $phrases;
    }

    /**
     * Ищет места для ссылок. При $apply = true возвращает html с вставленными <a data-bp>.
     *
     * @param array $targets сущности-цели (поля id, name, code, keywords, type)
     * @param string[]|null $only если задан — только эти цели в виде "тип:id" (element:3, section:3)
     * @return array{html:string,matches:array}
     */
    public function process(string $html, array $targets, bool $apply = false, ?array $only = null): array
    {
        $dom = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><div id="bp-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $root = $dom->getElementById('bp-root') ?? $dom->documentElement;

        // существующие ссылки на цели не дублируем
        // абзацы, где уже есть ссылка, считаем занятыми (лимит: одна ссылка на абзац)
        $existing = [];
        $usedBlocks = [];
        foreach ($dom->getElementsByTagName('a') as $a) {
            $existing[$a->getAttribute('href')] = true;
            $n = $a->parentNode;
            while ($n && $n !== $root && !in_array(strtolower($n->nodeName), self::BLOCK_TAGS, true)) {
                $n = $n->parentNode;
            }
            if ($n) {
                $usedBlocks[$n->getNodePath()] = true;
            }
        }

        $textNodes = [];
        $this->collect($root, null, $textNodes);

        $matches = [];
        $usedAnchors = [];
        $limit = (int)$this->opt['max_links_per_article'];
        $perWords = (int)$this->opt['words_per_link'];
        if ($perWords > 0) {
            $words = count(TextProcessor::words(strip_tags($html)));
            $byDensity = max(1, intdiv($words, $perWords));
            $limit = $limit > 0 ? min($limit, $byDensity) : $byDensity;
        }
        if ($this->opt['skip_first_block'] && $textNodes) {
            $usedBlocks[$textNodes[0]['block']] = true;
        }
        foreach ($targets as $target) {
            if ($limit > 0 && count($matches) >= $limit) {
                break;
            }
            $isSection = ($target['type'] ?? 'element') === 'section';
            if (($isSection && !$this->opt['target_sections']) || (!$isSection && !$this->opt['target_elements'])) {
                continue;
            }
            $tid = (int)$target['id'];
            if ($only !== null && !in_array(($target['type'] ?? 'element') . ':' . $tid, $only, true)) {
                continue;
            }
            $href = $this->href($target);
            if (isset($existing[$href])) {
                continue;
            }
            foreach ($this->phrasesForTarget($target) as $ph) {
                $hit = $this->findPhrase($textNodes, $ph['stems'], $usedBlocks);
                if (!$hit) {
                    continue;
                }
                [$ni, $start, $end, $block] = $hit;
                $anchorKey = mb_strtolower(substr($textNodes[$ni]['node']->nodeValue, $start, $end - $start));
                if ($this->opt['unique_anchors'] && isset($usedAnchors[$anchorKey])) {
                    continue;
                }
                $usedAnchors[$anchorKey] = true;
                if ($this->opt['one_link_per_block']) {
                    $usedBlocks[$block] = true;
                }
                $node = $textNodes[$ni]['node'];
                $text = $node->nodeValue;
                $anchor = substr($text, $start, $end - $start);
                $matches[] = [
                    'target_id'   => $tid,
                    'target_type' => $target['type'] ?? 'element',
                    'target_name' => $target['name'] ?? '',
                    'href'        => $href,
                    'anchor'      => $anchor,
                    'phrase'      => $ph['phrase'],
                    'source'      => $ph['source'],
                    'context'     => [
                        'left'  => mb_strcut($text, max(0, $start - 50), $start - max(0, $start - 50), 'UTF-8'),
                        'match' => $anchor,
                        'right' => mb_strcut($text, $end, 50, 'UTF-8'),
                    ],
                ];
                // разбиваем узел: before + <a> + after
                $before = $dom->createTextNode(substr($text, 0, $start));
                $a = $dom->createElement('a');
                $a->setAttribute('href', $href);
                $a->setAttribute('data-bp', (string)$tid);
                $a->appendChild($dom->createTextNode($anchor));
                $afterNode = $dom->createTextNode(substr($text, $end));
                $parent = $node->parentNode;
                $parent->replaceChild($afterNode, $node);
                $parent->insertBefore($a, $afterNode);
                $parent->insertBefore($before, $a);
                array_splice($textNodes, $ni, 1, [
                    ['node' => $before, 'block' => $block],
                    ['node' => $afterNode, 'block' => $block],
                ]);
                break; // одна ссылка на цель
            }
        }

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }
        return ['html' => $apply ? $out : $html, 'matches' => $matches];
    }

    /**
     * Адрес цели по шаблону инфоблока (как в Битрикс: DETAIL_PAGE_URL / SECTION_PAGE_URL).
     * Поддержаны #SITE_DIR#, #IBLOCK_CODE#, #IBLOCK_ID#, #ELEMENT_CODE#, #ELEMENT_ID#,
     * #SECTION_CODE#, #SECTION_ID#, #CODE#, #ID#.
     */
    private function href(array $target): string
    {
        $isSection = ($target['type'] ?? 'element') === 'section';
        $ib = $this->iblocks[$target['iblock_id'] ?? 0] ?? [];
        $tpl = $isSection ? ($ib['section_page_url'] ?? '') : ($ib['detail_page_url'] ?? '');
        if ($tpl === '') {
            $tpl = $isSection ? '/#IBLOCK_CODE#/#SECTION_CODE#/' : '/#IBLOCK_CODE#/#ELEMENT_CODE#/';
        }
        $code = $target['code'] ?? $target['id'];
        $secId = $isSection ? (int)$target['id'] : (int)($target['section_id'] ?? 0);
        $secCode = $this->sections[$secId]['code'] ?? '';
        // цепочка кодов от корня раздела (для #SECTION_CODE_PATH#)
        $path = [];
        for ($cur = $secId, $guard = 0; $cur && isset($this->sections[$cur]) && $guard < 20; $guard++) {
            array_unshift($path, $this->sections[$cur]['code'] ?? '');
            $cur = (int)($this->sections[$cur]['parent_id'] ?? 0);
        }
        $url = strtr($tpl, [
            '#SITE_DIR#'     => defined('SITE_DIR') ? SITE_DIR : '/',
            '#SECTION_CODE_PATH#' => implode('/', array_filter($path, 'strlen')),
            '#IBLOCK_CODE#'  => $ib['code'] ?? '',
            '#IBLOCK_ID#'    => $target['iblock_id'] ?? '',
            '#ELEMENT_CODE#' => $code,
            '#ELEMENT_ID#'   => $target['id'],
            '#SECTION_CODE#' => $secCode,
            '#SECTION_ID#'   => $isSection ? $target['id'] : ($target['section_id'] ?? ''),
            '#CODE#'         => $code,
            '#ID#'           => $target['id'],
        ]);
        return preg_replace('#/{2,}#', '/', $url);
    }

    private function collect(\DOMNode $node, $block, array &$out): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                if (trim($child->nodeValue) !== '') {
                    $out[] = ['node' => $child, 'block' => $block ?? $node->getNodePath()];
                }
            } elseif ($child instanceof \DOMElement) {
                $tag = strtolower($child->tagName);
                if ($tag === 'a' || in_array($tag, $this->opt['skip_tags'], true)) {
                    continue;
                }
                $b = in_array($tag, self::BLOCK_TAGS, true) ? $child->getNodePath() : $block;
                $this->collect($child, $b, $out);
            }
        }
    }

    /**
     * @return array|null [индекс узла, начало, конец (байты), id блока]
     */
    private function findPhrase(array $textNodes, array $stems, array $usedBlocks): ?array
    {
        $n = count($stems);
        foreach ($textNodes as $ni => $info) {
            if (isset($usedBlocks[$info['block']])) {
                continue;
            }
            $text = $info['node']->nodeValue;
            $words = TextProcessor::words($text);
            for ($i = 0; $i + $n <= count($words); $i++) {
                for ($j = 0; $j < $n; $j++) {
                    if ($words[$i + $j]['stem'] !== $stems[$j]) {
                        break;
                    }
                    if ($j > 0) {
                        $prevEnd = $words[$i + $j - 1]['end'];
                        $gap = substr($text, $prevEnd, $words[$i + $j]['start'] - $prevEnd);
                        if (!preg_match('/^[\s\-–]+$/u', $gap)) {
                            break;
                        }
                    }
                }
                if ($j === $n) {
                    return [$ni, $words[$i]['start'], $words[$i + $n - 1]['end'], $info['block']];
                }
            }
        }
        return null;
    }
}
