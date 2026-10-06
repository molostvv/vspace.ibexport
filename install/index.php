<?php

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

Loader::includeModule('main');
Loc::loadMessages(__FILE__);

class vspace_ibexport extends CModule
{
    public $MODULE_ID = 'vspace.ibexport';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME;
    public $MODULE_DESCRIPTION;
    public $MODULE_GROUP_RIGHTS = 'Y';
    public $PARTNER_NAME = 'vspace';

    public function __construct()
    {
        $arModuleVersion = [];
        include __DIR__ . '/version.php';
        $this->MODULE_VERSION = $arModuleVersion['VERSION'];
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];

        $this->MODULE_NAME = Loc::getMessage('IBEXPORT_MODULE_NAME');
        $this->MODULE_DESCRIPTION = Loc::getMessage('IBEXPORT_MODULE_DESC');
    }

    /**
     * Уровни доступа к модулю (вкладка "Доступ" в настройках модуля, options.php). Операции проверяет
     * lib/Rights.php: vspace_ibexport_export / vspace_ibexport_import разрешают экспорт/импорт любого
     * инфоблока независимо от прав на сам инфоблок. Названия — admin/task_description.php.
     */
    public function GetModuleTasks()
    {
        return [
            'vspace_ibexport_denied' => [
                'LETTER' => 'D',
                'BINDING' => 'module',
                'OPERATIONS' => [],
            ],
            'vspace_ibexport_export' => [
                'LETTER' => 'R',
                'BINDING' => 'module',
                'OPERATIONS' => ['vspace_ibexport_export'],
            ],
            'vspace_ibexport_full' => [
                'LETTER' => 'W',
                'BINDING' => 'module',
                'OPERATIONS' => ['vspace_ibexport_export', 'vspace_ibexport_import'],
            ],
        ];
    }

    public function InstallDB()
    {
        Loader::includeModule($this->MODULE_ID);

        foreach ([\Vspace\Ibexport\JobTable::class, \Vspace\Ibexport\ImportJobTable::class, \Vspace\Ibexport\ImportedFileTable::class] as $tableClass) {
            $entity = $tableClass::getEntity();
            if (!$entity->getConnection()->isTableExists($entity->getDbTableName())) {
                $entity->createDbTable();
            }
        }

        // Таблица импорта, созданная до появления MATCH_BY_XML_ID, — добавляем колонку (идемпотентно).
        $connection = Application::getConnection();
        $table = \Vspace\Ibexport\ImportJobTable::getTableName();
        $helper = $connection->getSqlHelper();
        // getTableFields() кэширует список колонок в рамках запроса, поэтому проверяем напрямую (константный литерал, без пользовательского ввода)
        if ($connection->isTableExists($table) && !$connection->query('SHOW COLUMNS FROM ' . $helper->quote($table) . " LIKE 'MATCH_BY_XML_ID'")->fetch()) {
            $type = $helper->getColumnTypeByField(\Vspace\Ibexport\ImportJobTable::getEntity()->getField('MATCH_BY_XML_ID'));
            $connection->queryExecute('ALTER TABLE ' . $helper->quote($table) . ' ADD ' . $helper->quote('MATCH_BY_XML_ID') . ' ' . $type . " NOT NULL DEFAULT 'N'");
        }

        return true;
    }

    public function UnInstallDB()
    {
        Loader::includeModule($this->MODULE_ID);

        $connection = Application::getConnection();
        foreach ([\Vspace\Ibexport\JobTable::class, \Vspace\Ibexport\ImportJobTable::class, \Vspace\Ibexport\ImportedFileTable::class] as $tableClass) {
            $entity = $tableClass::getEntity();
            if ($entity->getConnection()->isTableExists($entity->getDbTableName())) {
                $connection->dropTable($entity->getDbTableName());
            }
        }

        return true;
    }

    public function InstallFiles()
    {
        CopyDirFiles(
            __DIR__ . '/admin',
            $_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin',
            true,
            true
        );

        return true;
    }

    public function UnInstallFiles()
    {
        $files = [
            'vspace_ibexport_export.php',
            'vspace_ibexport_progress.php',
            'vspace_ibexport_download.php',
            'vspace_ibexport_log.php',
            'vspace_ibexport_context.php',
            'vspace_ibexport_import.php',
            'vspace_ibexport_import_progress.php',
            'vspace_ibexport_import_log.php',
            'vspace_ibexport_yandex_disk.php',
            'vspace_ibexport_yandex_disk_upload.php',
        ];
        foreach ($files as $file) {
            $path = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin/' . $file;
            if (file_exists($path)) {
                unlink($path);
            }
        }

        return true;
    }

    public function InstallEvents()
    {
        $eventManager = \Bitrix\Main\EventManager::getInstance();
        $eventManager->registerEventHandler(
            'main',
            'OnAdminListDisplay',
            $this->MODULE_ID,
            '\\Vspace\\Ibexport\\Integration\\AdminListIntegration',
            'onAdminListDisplay'
        );

        // Периодическая очистка просроченных временных файлов и записей заданий (раздел 8 ТЗ).
        CAgent::AddAgent(
            '\\Vspace\\Ibexport\\Exporter::cleanupAgent();',
            $this->MODULE_ID,
            'N',
            3600,
            '',
            'Y',
            \Bitrix\Main\Type\DateTime::createFromTimestamp(time() + 3600)->toString(),
            50
        );

        // Та же периодическая очистка, но для заданий и временных каталогов импорта.
        CAgent::AddAgent(
            '\\Vspace\\Ibexport\\Importer::cleanupAgent();',
            $this->MODULE_ID,
            'N',
            3600,
            '',
            'Y',
            \Bitrix\Main\Type\DateTime::createFromTimestamp(time() + 3600)->toString(),
            50
        );

        return true;
    }

    public function UnInstallEvents()
    {
        $eventManager = \Bitrix\Main\EventManager::getInstance();
        $eventManager->unRegisterEventHandler(
            'main',
            'OnAdminListDisplay',
            $this->MODULE_ID,
            '\\Vspace\\Ibexport\\Integration\\AdminListIntegration',
            'onAdminListDisplay'
        );

        CAgent::RemoveModuleAgents($this->MODULE_ID);

        return true;
    }

    public function DoInstall()
    {
        global $APPLICATION;

        if (!Loader::includeModule('iblock')) {
            $APPLICATION->ThrowException(Loc::getMessage('IBEXPORT_INSTALL_NEED_IBLOCK'));
            return false;
        }

        // Классы lib/ подключает автозагрузчик ядра: Loader::includeModule() регистрирует для модуля
        // пространство имён Vspace\Ibexport -> lib/, отдельная регистрация классов не нужна.
        ModuleManager::registerModule($this->MODULE_ID);

        $this->InstallFiles();
        $this->InstallDB();
        $this->InstallEvents();
        $this->InstallTasks();

        return true;
    }

    /**
     * Удаление в два шага, как у модулей ядра: шаг 1 — форма подтверждения с галкой "Сохранить таблицы"
     * (install/unstep1.php), шаг 2 — само удаление. С сохранением остаются таблицы заданий (журналы) и
     * настройки модуля (в том числе токен Яндекс.Диска); временные файлы заданий удаляются в любом случае.
     */
    public function DoUninstall()
    {
        global $APPLICATION;

        $request = Application::getInstance()->getContext()->getRequest();
        if ((int)$request->get('step') < 2) {
            $APPLICATION->IncludeAdminFile(Loc::getMessage('IBEXPORT_UNINSTALL_TITLE'), __DIR__ . '/unstep1.php');
        }
        if (!check_bitrix_sessid()) {
            return false;
        }

        if (Loader::includeModule($this->MODULE_ID)) {
            \Vspace\Ibexport\TmpStorage::deleteAll();
        }

        $this->UnInstallEvents();
        $this->UnInstallFiles();
        $this->UnInstallTasks();
        if ($request->get('savedata') !== 'Y') {
            $this->UnInstallDB();
            Option::delete($this->MODULE_ID);
        }

        ModuleManager::unRegisterModule($this->MODULE_ID);

        $APPLICATION->IncludeAdminFile(Loc::getMessage('IBEXPORT_UNINSTALL_TITLE'), __DIR__ . '/unstep2.php');

        return true;
    }
}
