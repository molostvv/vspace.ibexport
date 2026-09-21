<?php

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

Loader::includeModule('main');

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

        $langFile = __DIR__ . '/../lang/' . LANGUAGE_ID . '/install/index.php';
        include_once file_exists($langFile) ? $langFile : __DIR__ . '/../lang/en/install/index.php';

        $this->MODULE_NAME = isset($MESS['IBEXPORT_MODULE_NAME']) ? $MESS['IBEXPORT_MODULE_NAME'] : 'IBlock Export';
        $this->MODULE_DESCRIPTION = isset($MESS['IBEXPORT_MODULE_DESC']) ? $MESS['IBEXPORT_MODULE_DESC'] : 'Export of iblock elements and sections';
    }

    public function InstallDB()
    {
        Loader::includeModule($this->MODULE_ID);

        foreach ([\Vspace\Ibexport\JobTable::class, \Vspace\Ibexport\ImportJobTable::class] as $tableClass) {
            $entity = $tableClass::getEntity();
            if (!$entity->getConnection()->isTableExists($entity->getDbTableName())) {
                $entity->createDbTable();
            }
        }

        return true;
    }

    public function UnInstallDB()
    {
        Loader::includeModule($this->MODULE_ID);

        $connection = Application::getConnection();
        foreach ([\Vspace\Ibexport\JobTable::class, \Vspace\Ibexport\ImportJobTable::class] as $tableClass) {
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
            $APPLICATION->ThrowException('Для работы модуля необходим установленный модуль "Информационные блоки" (iblock).');
            return false;
        }

        ModuleManager::registerModule($this->MODULE_ID);

        Loader::registerAutoLoadClasses($this->MODULE_ID, [
            'Vspace\\Ibexport\\Exporter' => 'lib/Exporter.php',
            'Vspace\\Ibexport\\Importer' => 'lib/Importer.php',
            'Vspace\\Ibexport\\AbstractJobTable' => 'lib/AbstractJobTable.php',
            'Vspace\\Ibexport\\JobTable' => 'lib/JobTable.php',
            'Vspace\\Ibexport\\ImportJobTable' => 'lib/ImportJobTable.php',
            'Vspace\\Ibexport\\TickRunner' => 'lib/TickRunner.php',
            'Vspace\\Ibexport\\JobEventLog' => 'lib/JobEventLog.php',
            'Vspace\\Ibexport\\IblockListProvider' => 'lib/IblockListProvider.php',
            'Vspace\\Ibexport\\Admin\\AdminMessages' => 'lib/Admin/AdminMessages.php',
            'Vspace\\Ibexport\\WalkResult' => 'lib/WalkResult.php',
            'Vspace\\Ibexport\\TraversalFrame' => 'lib/TraversalFrame.php',
            'Vspace\\Ibexport\\Export\\ExportFrame' => 'lib/Export/ExportFrame.php',
            'Vspace\\Ibexport\\Import\\ImportFrame' => 'lib/Import/ImportFrame.php',
            'Vspace\\Ibexport\\Export\\ExportContext' => 'lib/Export/ExportContext.php',
            'Vspace\\Ibexport\\Export\\ExportStep' => 'lib/Export/ExportStep.php',
            'Vspace\\Ibexport\\Export\\SectionTreeWalker' => 'lib/Export/SectionTreeWalker.php',
            'Vspace\\Ibexport\\Export\\TreeSourceInterface' => 'lib/Export/TreeSourceInterface.php',
            'Vspace\\Ibexport\\Export\\BitrixTreeSource' => 'lib/Export/BitrixTreeSource.php',
            'Vspace\\Ibexport\\Export\\SectionWriter' => 'lib/Export/SectionWriter.php',
            'Vspace\\Ibexport\\Export\\ElementWriter' => 'lib/Export/ElementWriter.php',
            'Vspace\\Ibexport\\Export\\FileRefWriter' => 'lib/Export/FileRefWriter.php',
            'Vspace\\Ibexport\\Export\\ArchiveBuilder' => 'lib/Export/ArchiveBuilder.php',
            'Vspace\\Ibexport\\Import\\ImportContext' => 'lib/Import/ImportContext.php',
            'Vspace\\Ibexport\\Import\\ImportReport' => 'lib/Import/ImportReport.php',
            'Vspace\\Ibexport\\Import\\ImportStep' => 'lib/Import/ImportStep.php',
            'Vspace\\Ibexport\\Import\\SectionTreeWalker' => 'lib/Import/SectionTreeWalker.php',
            'Vspace\\Ibexport\\Import\\SectionImporter' => 'lib/Import/SectionImporter.php',
            'Vspace\\Ibexport\\Import\\ElementImporter' => 'lib/Import/ElementImporter.php',
            'Vspace\\Ibexport\\Import\\AbstractNodeImporter' => 'lib/Import/AbstractNodeImporter.php',
            'Vspace\\Ibexport\\Import\\PropertyResolver' => 'lib/Import/PropertyResolver.php',
            'Vspace\\Ibexport\\Import\\PropertySourceInterface' => 'lib/Import/PropertySourceInterface.php',
            'Vspace\\Ibexport\\Import\\BitrixPropertySource' => 'lib/Import/BitrixPropertySource.php',
            'Vspace\\Ibexport\\Import\\FileArrayFactoryInterface' => 'lib/Import/FileArrayFactoryInterface.php',
            'Vspace\\Ibexport\\Import\\BitrixFileArrayFactory' => 'lib/Import/BitrixFileArrayFactory.php',
            'Vspace\\Ibexport\\Rights' => 'lib/Rights.php',
            'Vspace\\Ibexport\\Options' => 'lib/Options.php',
            'Vspace\\Ibexport\\XmlStreamWriter' => 'lib/XmlStreamWriter.php',
            'Vspace\\Ibexport\\Integration\\AdminListIntegration' => 'lib/Integration/AdminListIntegration.php',
            'Vspace\\Ibexport\\YandexDisk\\Client' => 'lib/YandexDisk/Client.php',
            'Vspace\\Ibexport\\YandexDisk\\Exception' => 'lib/YandexDisk/Exception.php',
            'Vspace\\Ibexport\\YandexDisk\\Settings' => 'lib/YandexDisk/Settings.php',
            'Vspace\\Ibexport\\YandexDisk\\ImportSource' => 'lib/YandexDisk/ImportSource.php',
            'Vspace\\Ibexport\\YandexDisk\\Http\\TransportInterface' => 'lib/YandexDisk/Http/TransportInterface.php',
            'Vspace\\Ibexport\\YandexDisk\\Http\\BitrixHttpTransport' => 'lib/YandexDisk/Http/BitrixHttpTransport.php',
        ]);

        $this->InstallFiles();
        $this->InstallDB();
        $this->InstallEvents();

        return true;
    }

    public function DoUninstall()
    {
        $this->UnInstallEvents();
        $this->UnInstallFiles();
        $this->UnInstallDB();

        if (method_exists(Loader::class, 'unRegisterAutoLoadClasses')) {
            Loader::unRegisterAutoLoadClasses($this->MODULE_ID);
        }

        ModuleManager::unRegisterModule($this->MODULE_ID);

        return true;
    }
}
