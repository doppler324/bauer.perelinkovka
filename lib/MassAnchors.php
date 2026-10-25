<?php

namespace Bauer\Perelinkovka;

/**
 * Class MassAnchors
 *
 * Массовая расстановка ссылок внутри текстов: по статье, разделу или всем статьям инфоблока.
 * Работает порциями (planChunk / applyChunk), чтобы админка могла показывать прогресс.
 * Цели с меньшим числом входящих ссылок идут первыми, поэтому ссылки распределяются равномернее.
 */
class MassAnchors
{
    private DataProviderInterface $dp;
    private int $iblockId;
    private string $pool;
    /** @var bool|null одна ссылка на абзац; null — как в настройках анкоров */
    private ?bool $onePerBlock;
    private AnchorStoreInterface $store;
    private SynonymProvider $synonyms;
    private array $anchorOptions;
    private array $iblocks;
    private array $sections;
    /** @var array[] статьи инфоблока (элементы с полем text) */
    private array $articles = [];
    /** @var array<int,array> */
    private array $articleById = [];
    /** @var array[] цели ссылок */
    private array $targets = [];

    /**
     * @param string $pool 'articles' — только статьи инфоблока, 'all' — статьи, товары и разделы
     */
    public function __construct(DataProviderInterface $dp, int $iblockId, string $pool = 'articles', ?bool $onePerBlock = null)
    {
        $this->onePerBlock = $onePerBlock;
        $this->dp = $dp;
        $this->iblockId = $iblockId;
        $this->pool = $pool === 'all' ? 'all' : 'articles';
        $this->store = Env::anchorStore();
        $this->synonyms = Env::synonyms();
        $this->anchorOptions = Env::anchorOptions();
        $this->iblocks = $dp->getIBlocks();
        $this->sections = $dp->getSections();

        // список статей без текстов (на больших сайтах тексты не держим в памяти), текст берём по одной статье
        foreach ($dp->getElements(['iblock_id' => $iblockId, 'has_text' => true]) as $e) {
            unset($e['text']);
            $this->articles[] = $e;
            $this->articleById[(int)$e['id']] = $e;
        }
        if ($this->pool === 'articles') {
            foreach ($this->articles as $a) {
                $this->targets[] = $a + ['type' => 'element'];
            }
        } else {
            $this->targets = Env::anchorTargets($this->anchorOptions);
        }
    }

    public function articles(): array
    {
        return $this->articles;
    }

    public function article(int $id): ?array
    {
        return $this->articleById[$id] ?? null;
    }

    /** Текст статьи: сохранённый вариант со ссылками или исходный. */
    private function textOf(int $id): string
    {
        $st = $this->store->get($id);
        if (isset($st['text'])) {
            return (string)$st['text'];
        }
        return (string)($this->dp->getElement($id)['text'] ?? '');
    }

    public function store(): AnchorStoreInterface
    {
        return $this->store;
    }

    private static function key(array $t): string
    {
        return ($t['type'] ?? 'element') . ':' . (int)$t['id'];
    }

    private function engine(int $limit): AnchorEngine
    {
        return new CachedAnchorEngine(
            $this->synonyms,
            array_replace(
                $this->anchorOptions,
                ['max_links_per_article' => $limit],
                $this->onePerBlock === null ? [] : ['one_link_per_block' => $this->onePerBlock]
            ),
            $this->iblocks,
            $this->sections
        );
    }

    /** Сколько ссылок уже вставлено на каждую цель ("тип:id" => число), по сохранённым текстам. */
    public function inbound(): array
    {
        $this->store = Env::anchorStore();
        $inbound = [];
        foreach ($this->articles as $a) {
            $st = $this->store->get((int)$a['id']);
            foreach ($st['links'] ?? [] as $l) {
                $k = 'element:' . (int)$l['target_id'];
                $inbound[$k] = ($inbound[$k] ?? 0) + 1;
            }
        }
        return $inbound;
    }

    /**
     * id статей области запуска.
     * @param string $scope all | section | article
     */
    public function scopeIds(string $scope, int $id = 0, bool $withChildren = true): array
    {
        $list = $this->articles;
        if ($scope === 'article') {
            $list = array_filter($list, fn($a) => (int)$a['id'] === $id);
        } elseif ($scope === 'section') {
            $ids = [$id];
            if ($withChildren) {
                for ($grew = true; $grew;) {
                    $grew = false;
                    foreach ($this->sections as $sec) {
                        $sid = (int)$sec['id'];
                        if (!in_array($sid, $ids, true) && in_array((int)($sec['parent_id'] ?? 0), $ids, true)) {
                            $ids[] = $sid;
                            $grew = true;
                        }
                    }
                }
            }
            $list = array_filter($list, fn($a) => in_array((int)($a['section_id'] ?? 0), $ids, true));
        }
        return array_values(array_map(fn($a) => (int)$a['id'], $list));
    }

    /**
     * Подбирает ссылки для порции статей. Ничего не сохраняет.
     *
     * @param int[] $ids статьи-источники
     * @param array $inbound счётчики входящих (переходят между порциями)
     * @param array $o max_links, max_inbound, only_low, low_threshold, balance
     * @param float $budget секунд на порцию: когда время вышло, остальные статьи не берём (0 — без ограничения)
     * @return array{plan:array,inbound:array,processed:int}
     */
    public function planChunk(array $ids, array $inbound, array $o, float $budget = 0): array
    {
        $t0 = microtime(true);
        $processed = 0;
        $maxLinks = max(0, (int)($o['max_links'] ?? 3)); // 0 — без ограничения
        $maxInbound = max(0, (int)($o['max_inbound'] ?? 0));
        $onlyLow = !empty($o['only_low']);
        $low = max(0, (int)($o['low_threshold'] ?? 1));
        $balance = !array_key_exists('balance', $o) || !empty($o['balance']);

        $plan = [];
        foreach ($ids as $sid) {
            if ($budget > 0 && $processed > 0 && microtime(true) - $t0 > $budget) {
                break;
            }
            $processed++;
            $src = $this->articleById[(int)$sid] ?? null;
            if (!$src) {
                continue;
            }
            $text = $this->textOf((int)$sid);
            $targets = [];
            foreach ($this->targets as $t) {
                if (($t['type'] ?? 'element') === 'element' && (int)$t['id'] === (int)$sid) {
                    continue;
                }
                $cnt = $inbound[self::key($t)] ?? 0;
                if (($maxInbound > 0 && $cnt >= $maxInbound) || ($onlyLow && $cnt > $low)) {
                    continue;
                }
                $t['_cnt'] = $cnt;
                $targets[] = $t;
            }
            if ($balance) {
                usort($targets, fn($x, $y) => [$x['_cnt'], (int)$x['id']] <=> [$y['_cnt'], (int)$y['id']]);
            }
            $matches = $this->engine($maxLinks)->process($text, $targets)['matches'];
            if ($matches) {
                $plan[(int)$sid] = $matches;
                foreach ($matches as $m) {
                    $k = $m['target_type'] . ':' . $m['target_id'];
                    $inbound[$k] = ($inbound[$k] ?? 0) + 1;
                }
            }
        }
        return ['plan' => $plan, 'inbound' => $inbound, 'processed' => $processed];
    }

    /**
     * Вставляет выбранные ссылки в тексты и сохраняет.
     * @param array<int,string[]> $byArticle id статьи => ["тип:id", ...]
     * @return array{applied:int,articles:int}
     */
    public function applyChunk(array $byArticle): array
    {
        $applied = 0;
        $touched = 0;
        foreach ($byArticle as $aid => $only) {
            if (!isset($this->articleById[(int)$aid])) {
                continue;
            }
            $aid = (int)$aid;
            $st = $this->store->get($aid);
            $text = $this->textOf($aid);
            $targets = array_values(array_filter(
                $this->targets,
                fn($t) => !(($t['type'] ?? 'element') === 'element' && (int)$t['id'] === $aid)
            ));
            $res = $this->engine(0)->process($text, $targets, true, array_map('strval', $only));
            if ($res['matches']) {
                $links = array_merge($st['links'] ?? [], array_map(
                    fn($m) => ['target_id' => $m['target_id'], 'anchor' => $m['anchor'], 'href' => $m['href']],
                    $res['matches']
                ));
                $this->store->save($aid, $res['html'], $links);
                $applied += count($res['matches']);
                $touched++;
            }
        }
        return ['applied' => $applied, 'articles' => $touched];
    }
}
