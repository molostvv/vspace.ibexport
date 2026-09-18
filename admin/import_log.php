<?php

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Bitrix\Main\UI\PageNavigation;
use Vspace\Ibexport\ImportJobTable;

Loader::includeModule('vspace.ibexport');
Loader::includeModule('iblock');

IncludeModuleLangFile(__FILE__);

global $USER, $APPLICATION;

$APPLICATION->SetTitle(GetMessage('IBIMPORT_LOG_TITLE'));

$isAdmin = $USER->IsAdmin();
$filter = $isAdmin ? [] : ['=USER_ID' => (int)$USER->GetID()];

// Тот же CAdminList, что и в admin/log.php.
$sTableID = 'tbl_vspace_ibexport_import_log';
$lAdmin = new CAdminList($sTableID);

$nav = new PageNavigation($sTableID);
$nav->allowAllRecords(true)->setPageSize(30)->initFromUri();

$totalCount = ImportJobTable::getList([
    'filter' => $filter,
    'select' => ['ID'],
    'count_total' => true,
    'limit' => 1,
])->getCount();
$nav->setRecordCount($totalCount);

$rows = ImportJobTable::getList([
    'filter' => $filter,
    'order' => ['ID' => 'DESC'],
    'limit' => $nav->getLimit(),
    'offset' => $nav->getOffset(),
])->fetchAll();

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

$statusLabels = [
    ImportJobTable::STATUS_NEW => GetMessage('IBIMPORT_STATUS_NEW'),
    ImportJobTable::STATUS_RUNNING => GetMessage('IBIMPORT_STATUS_RUNNING'),
    ImportJobTable::STATUS_DONE => GetMessage('IBIMPORT_STATUS_DONE'),
    ImportJobTable::STATUS_ERROR => GetMessage('IBIMPORT_STATUS_ERROR'),
];

$lAdmin->AddHeaders([
    ['id' => 'ID', 'content' => GetMessage('IBIMPORT_LOG_COL_ID'), 'default' => true],
    ['id' => 'DATE_CREATE', 'content' => GetMessage('IBIMPORT_LOG_COL_DATE'), 'default' => true],
    ['id' => 'IBLOCK', 'content' => GetMessage('IBIMPORT_LOG_COL_IBLOCK'), 'default' => true],
    ['id' => 'MODE', 'content' => GetMessage('IBIMPORT_LOG_COL_MODE'), 'default' => true],
    ['id' => 'STATUS', 'content' => GetMessage('IBIMPORT_LOG_COL_STATUS'), 'default' => true],
    ['id' => 'COUNT', 'content' => GetMessage('IBIMPORT_LOG_COL_COUNT'), 'default' => true],
    ['id' => 'RESULT', 'content' => GetMessage('IBIMPORT_LOG_COL_RESULT'), 'default' => true],
    ['id' => 'ACTIONS', 'content' => GetMessage('IBIMPORT_LOG_COL_ACTIONS'), 'default' => true],
]);

$modeLabels = [
    'element' => GetMessage('IBIMPORT_MODE_ELEMENT'),
    'section_single' => GetMessage('IBIMPORT_MODE_SECTION_SINGLE'),
    'section_tree' => GetMessage('IBIMPORT_MODE_SECTION_TREE'),
];

foreach ($rows as $jobRow) {
    $ib = \CIBlock::GetArrayByID((int)$jobRow['TARGET_IBLOCK_ID']);

    if (in_array($jobRow['STATUS'], [ImportJobTable::STATUS_NEW, ImportJobTable::STATUS_RUNNING], true)) {
        $actionsHtml = '<a href="/bitrix/admin/vspace_ibexport_import_progress.php?lang=' . LANGUAGE_ID
            . '&JOB_ID=' . (int)$jobRow['ID'] . '">'
            . htmlspecialcharsbx(GetMessage('IBIMPORT_LOG_VIEW_PROGRESS')) . '</a>';
    } elseif ($jobRow['STATUS'] === ImportJobTable::STATUS_ERROR) {
        $actionsHtml = '<span title="' . htmlspecialcharsbx((string)$jobRow['ERROR_MESSAGE']) . '">'
            . htmlspecialcharsbx(GetMessage('IBIMPORT_LOG_HAS_ERROR')) . '</span>';
    } else {
        $actionsHtml = '-';
    }

    $row = &$lAdmin->AddRow($jobRow['ID'], $jobRow);
    $row->AddViewField('ID', (int)$jobRow['ID']);
    $row->AddViewField('DATE_CREATE', htmlspecialcharsbx($jobRow['DATE_CREATE']));
    $row->AddViewField('IBLOCK', htmlspecialcharsbx($ib['NAME'] ?? ('#' . $jobRow['TARGET_IBLOCK_ID'])));
    $row->AddViewField('MODE', htmlspecialcharsbx($modeLabels[$jobRow['MODE']] ?? $jobRow['MODE']));
    $row->AddViewField('STATUS', htmlspecialcharsbx($statusLabels[$jobRow['STATUS']] ?? $jobRow['STATUS']));
    $row->AddViewField('COUNT', (int)$jobRow['PROCESSED_SECTIONS'] . ' / ' . (int)$jobRow['PROCESSED_ELEMENTS']);
    $row->AddViewField('RESULT', GetMessage('IBIMPORT_SUMMARY', [
        '#CREATED#' => (int)$jobRow['CREATED_COUNT'],
        '#UPDATED#' => (int)$jobRow['UPDATED_COUNT'],
        '#SKIPPED#' => (int)$jobRow['SKIPPED_COUNT'],
    ]));
    $row->AddViewField('ACTIONS', $actionsHtml);
    unset($row);
}

$lAdmin->setNavigation($nav, GetMessage('IBIMPORT_LOG_TITLE'));

$lAdmin->CheckListMode();
$lAdmin->DisplayList();

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
