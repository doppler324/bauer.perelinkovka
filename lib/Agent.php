<?php
namespace Bauer\Perelinkovka;

use Bitrix\Main\Config\Option;

/**
 * Агент пересчёта связей. Работает порциями, чтобы не упираться во время выполнения хита:
 * на каждом запуске (раз в 5 минут) обрабатывает блоки по очереди в рамках бюджета времени,
 * запоминает позицию и после полного круга ждёт «интервал пересчёта» из настроек модуля.
 */
class Agent
{
    private const MODULE = 'bauer.perelinkovka';
    private const BUDGET = 20;   // секунд на один запуск
    private const CHUNK = 20;    // страниц источника за один вызов Linker

    public static function run(): string
    {
        $self = '\\Bauer\\Perelinkovka\\Agent::run();';
        if (!\Bitrix\Main\Loader::includeModule(self::MODULE)) {
            return $self;
        }
        if (Option::get(self::MODULE, 'agent_enabled', 'Y') !== 'Y') {
            return $self;
        }
        $state = json_decode(Option::get(self::MODULE, 'agent_state', ''), true);
        $state = is_array($state) ? $state : [];
        if (empty($state['running'])) {
            if ((int)($state['next_run'] ?? 0) > time()) {
                return $self;
            }
            $state = ['running' => true, 'block' => 0, 'offset' => 0];
        }

        $provider = new BitrixDataProvider();
        $linker = new Linker($provider);
        $blocks = $provider->getBlocks(['active' => true]);
        $started = microtime(true);

        while ($state['block'] < count($blocks) && microtime(true) - $started < self::BUDGET) {
            $res = $linker->autoLinkBlock($blocks[$state['block']], ['save' => true, 'offset' => $state['offset'], 'limit' => self::CHUNK]);
            if ($res['next'] >= $res['total'] || $res['next'] <= $state['offset']) {
                $state['block']++;
                $state['offset'] = 0;
            } else {
                $state['offset'] = $res['next'];
            }
        }

        if ($state['block'] >= count($blocks)) {
            $hours = max(1, (int)Option::get(self::MODULE, 'agent_interval_hours', 6));
            $state = ['running' => false, 'next_run' => time() + $hours * 3600];
            global $CACHE_MANAGER;
            if (is_object($CACHE_MANAGER)) {
                $CACHE_MANAGER->ClearByTag('bauer_perelinkovka');
            }
        }
        Option::set(self::MODULE, 'agent_state', json_encode($state));
        return $self;
    }
}
