<?php

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Vspace\Ibexport\Exporter;
use Vspace\Ibexport\JobTable;
use Vspace\Ibexport\Options;
use Vspace\Ibexport\YandexDiskClient;
use Vspace\Ibexport\YandexDiskException;

Loader::includeModule('vspace.ibexport');

IncludeModuleLangFile(__FILE__);

global $USER;

$jobId = (int)($_REQUEST['JOB_ID'] ?? 0);
$job = $jobId ? JobTable::getJobById($jobId) : null;
$isOwner = $job && ((int)$job['USER_ID'] === (int)$USER->GetID() || $USER->IsAdmin());

$redirectBack = static function (string $status, string $message = '') use ($jobId): void {
    $url = '/bitrix/admin/vspace_ibexport_progress.php?lang=' . LANGUAGE_ID . '&JOB_ID=' . $jobId
        . '&DISK_UPLOAD=' . $status;
    if ($message !== '') {
        $url .= '&DISK_ERROR=' . urlencode($message);
    }
    LocalRedirect($url);
};

// Выгрузка на Диск — отдельное, полностью ручное действие поверх уже
// готового результата обычного экспорта (ТЗ, раздел 3): ошибка здесь
// никак не может повлиять на уже сформированный архив и его обычное
// скачивание — тот путь этот файл вообще не трогает.
if (!$job || !$isOwner || $job['STATUS'] !== JobTable::STATUS_DONE || !$job['ARCHIVE_FILE']) {
    $redirectBack('error', GetMessage('IBYADISK_ERR_ARCHIVE_NOT_FOUND'));
}

if (!check_bitrix_sessid()) {
    $redirectBack('error', GetMessage('IBYADISK_ERR_SESSID'));
}

if (!Options::isYandexDiskEnabled() || !Options::hasYandexDiskToken()) {
    $redirectBack('error', GetMessage('IBYADISK_NOT_CONFIGURED'));
}

try {
    $localPath = Exporter::getTmpDir($jobId) . '/' . $job['ARCHIVE_FILE'];
    $folder = Options::getYandexDiskFolder();
    $diskPath = rtrim($folder, '/') . '/' . $job['ARCHIVE_FILE'];

    $client = new YandexDiskClient(Options::getYandexDiskToken());
    $client->ensureFolder($folder);
    $client->uploadFile($diskPath, $localPath, true);

    $redirectBack('ok');
} catch (YandexDiskException $e) {
    $redirectBack('error', $e->getMessage());
} catch (\Throwable $e) {
    $redirectBack('error', $e->getMessage());
}
