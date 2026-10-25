<?php
use Bitrix\Main\Application;
use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;

class bauer_perelinkovka extends CModule
{
    public $MODULE_ID = 'bauer.perelinkovka';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME = 'Перелинковка';
    public $MODULE_DESCRIPTION = 'Перелинковка блоков и ссылки-анкоры в текстах для инфоблоков';
    public $PARTNER_NAME = 'Bauer';
    public $PARTNER_URI = '';

    private const TABLES = [
        'b_bauer_perelinkovka_link', 'b_bauer_perelinkovka_block', 'b_bauer_perelinkovka_anchor', 'b_bauer_perelinkovka_vector',
    ];

    public function __construct()
    {
        $arModuleVersion = [];
        include __DIR__ . '/version.php';
        $this->MODULE_VERSION = $arModuleVersion['VERSION'];
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
    }

    /** Проверка окружения до установки: возвращает текст ошибки или ''. */
    private function checkRequirements(): string
    {
        if (PHP_VERSION_ID < 80000) {
            return 'Нужен PHP 8.0 или новее (сейчас ' . PHP_VERSION . ').';
        }
        if (!defined('BX_UTF') || BX_UTF !== true) {
            return 'Сайт должен работать в кодировке UTF-8 (модуль хранит русские тексты и названия). Кодировка windows-1251 не поддерживается.';
        }
        if (!ModuleManager::isModuleInstalled('iblock')) {
            return 'Не установлен модуль «Информационные блоки».';
        }
        if (!extension_loaded('dom') || !extension_loaded('mbstring')) {
            return 'Нужны расширения PHP: dom и mbstring.';
        }
        return '';
    }

    public function DoInstall()
    {
        global $APPLICATION;
        $error = $this->checkRequirements();
        if ($error === '') {
            try {
                ModuleManager::registerModule($this->MODULE_ID);
                Loader::includeModule($this->MODULE_ID);
                $this->InstallDB();
                $this->InstallEvents();
                $this->InstallFiles();
                \CAgent::AddAgent('\\Bauer\\Perelinkovka\\Agent::run();', $this->MODULE_ID, 'N', 300);
            } catch (\Throwable $e) {
                ModuleManager::unRegisterModule($this->MODULE_ID);
                $error = 'Ошибка установки: ' . $e->getMessage();
            }
        }
        if ($error !== '') {
            $APPLICATION->ThrowException($error);
        }
        $APPLICATION->IncludeAdminFile('Установка модуля «Перелинковка»', __DIR__ . '/step.php');
    }

    public function DoUninstall()
    {
        global $APPLICATION;
        $request = Application::getInstance()->getContext()->getRequest();
        if ((int)$request->get('step') < 2) {
            $APPLICATION->IncludeAdminFile('Удаление модуля «Перелинковка»', __DIR__ . '/unstep.php');
            return;
        }
        \CAgent::RemoveModuleAgents($this->MODULE_ID);
        $this->UnInstallEvents();
        $this->UnInstallFiles();
        if ($request->get('savedata') !== 'Y') {
            Loader::includeModule($this->MODULE_ID);
            $this->UnInstallDB();
            \Bitrix\Main\Config\Option::delete($this->MODULE_ID);
        }
        ModuleManager::unRegisterModule($this->MODULE_ID);
        $APPLICATION->IncludeAdminFile('Удаление модуля «Перелинковка»', __DIR__ . '/unstep2.php');
    }

    public function InstallDB()
    {
        $conn = Application::getConnection();
        foreach ([
            \Bauer\Perelinkovka\Orm\BlockTable::class,
            \Bauer\Perelinkovka\Orm\LinkTable::class,
            \Bauer\Perelinkovka\Orm\AnchorTable::class,
            \Bauer\Perelinkovka\Orm\VectorTable::class,
        ] as $table) {
            if (!$conn->isTableExists($table::getTableName())) {
                $table::getEntity()->createDbTable();
            }
        }
        $indexes = [
            'CREATE UNIQUE INDEX ux_bauer_pere_block_code ON b_bauer_perelinkovka_block (CODE)',
            'CREATE UNIQUE INDEX ux_bauer_pere_link ON b_bauer_perelinkovka_link (BLOCK_CODE, SOURCE_TYPE, SOURCE_ID, TARGET_TYPE, TARGET_ID)',
            'CREATE INDEX ix_bauer_pere_src ON b_bauer_perelinkovka_link (SOURCE_TYPE, SOURCE_ID)',
            'CREATE INDEX ix_bauer_pere_tgt ON b_bauer_perelinkovka_link (TARGET_TYPE, TARGET_ID)',
            'CREATE UNIQUE INDEX ux_bauer_pere_anchor_el ON b_bauer_perelinkovka_anchor (ELEMENT_ID)',
            'CREATE UNIQUE INDEX ux_bauer_pere_vector ON b_bauer_perelinkovka_vector (VKEY)',
        ];
        foreach ($indexes as $sql) {
            try {
                $conn->queryExecute($sql);
            } catch (\Throwable $e) {
                // индекс уже есть (повторная установка) — не критично
            }
        }
        return true;
    }

    public function UnInstallDB()
    {
        $conn = Application::getConnection();
        foreach (self::TABLES as $table) {
            if ($conn->isTableExists($table)) {
                $conn->dropTable($table);
            }
        }
        return true;
    }

    private function handlers(): array
    {
        $h = '\\Bauer\\Perelinkovka\\EventHandler';
        return [
            ['iblock', 'OnBeforeIBlockElementDelete', $h, 'onElementDelete'],
            ['iblock', 'OnBeforeIBlockSectionDelete', $h, 'onSectionDelete'],
            ['iblock', 'OnAfterIBlockElementUpdate', $h, 'onElementChange'],
        ];
    }

    public function InstallEvents()
    {
        $em = EventManager::getInstance();
        foreach ($this->handlers() as [$module, $event, $class, $method]) {
            $em->registerEventHandler($module, $event, $this->MODULE_ID, $class, $method);
        }
        return true;
    }

    public function UnInstallEvents()
    {
        $em = EventManager::getInstance();
        foreach ($this->handlers() as [$module, $event, $class, $method]) {
            $em->unRegisterEventHandler($module, $event, $this->MODULE_ID, $class, $method);
        }
        return true;
    }

    public function InstallFiles()
    {
        $root = $_SERVER['DOCUMENT_ROOT'];
        CopyDirFiles(__DIR__ . '/admin', $root . '/bitrix/admin', true, true);
        CopyDirFiles(__DIR__ . '/js', $root . '/bitrix/js/' . $this->MODULE_ID, true, true);
        CopyDirFiles(__DIR__ . '/css', $root . '/bitrix/css/' . $this->MODULE_ID, true, true);
        CopyDirFiles(__DIR__ . '/components', $root . '/bitrix/components', true, true);
        return true;
    }

    public function UnInstallFiles()
    {
        $root = $_SERVER['DOCUMENT_ROOT'];
        DeleteDirFiles(__DIR__ . '/admin', $root . '/bitrix/admin');
        DeleteDirFilesEx('/bitrix/js/' . $this->MODULE_ID);
        DeleteDirFilesEx('/bitrix/css/' . $this->MODULE_ID);
        DeleteDirFilesEx('/bitrix/components/bauer/perelinkovka.links');
        return true;
    }
}
