<?php

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Vspace\Ibexport\Exporter;
use Vspace\Ibexport\JobTable;

Loader::includeModule('vspace.ibexport');

IncludeModuleLangFile(__FILE__);

global $USER;

$jobId = (int)($_REQUEST['JOB_ID'] ?? 0);
$job = $jobId ? JobTable::getJobById($jobId) : null;

$isOwner = $job && ((int)$job['USER_ID'] === (int)$USER->GetID() || $USER->IsAdmin());

if (!$job || !$isOwner || $job['STATUS'] !== JobTable::STATUS_DONE || !$job['ARCHIVE_FILE']) {
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
    CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => GetMessage('IBEXPORT_ERR_ARCHIVE_NOT_FOUND')]);
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
    die();
}

if (!check_bitrix_sessid()) {
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
    CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => GetMessage('IBEXPORT_ERR_SESSID')]);
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
    die();
}

$path = Exporter::getTmpDir($jobId) . '/' . $job['ARCHIVE_FILE'];

if (!is_file($path)) {
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
    CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => GetMessage('IBEXPORT_ERR_ARCHIVE_NOT_FOUND')]);
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
    die();
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Description: File Transfer');
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $job['ARCHIVE_FILE'] . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private');
readfile($path);
die();
