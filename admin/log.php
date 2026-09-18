<?php

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Bitrix\Main\UI\PageNavigation;
use Vspace\Ibexport\JobTable;

Loader::includeModule('vspace.ibexport');
Loader::includeModule('iblock');

IncludeModuleLangFile(__FILE__);

global $USER, $APPLICATION;

$APPLICATION->SetTitle(GetMessage('IBEXPORT_LOG_TITLE'));

$isAdmin = $USER->IsAdmin();
$filter = $isAdmin ? [] : ['=USER_ID' => (int)$USER->GetID()];

// Стандартный список админки Bitrix (раздел 9 ТЗ): та же таблица, шапка,
// чередование строк и пагинация, что и во всех остальных списках админки —
// вместо самодельной HTML-таблицы без разметки, которую распознаёт CSS темы.
$sTableID = 'tbl_vspace_ibexport_log';
$lAdmin = new CAdminList($sTableID);

$nav = new PageNavigation($sTableID);
$nav->allowAllRecords(true)->setPageSize(30)->initFromUri();

$totalCount = JobTable::getList([
    'filter' => $filter,
    'select' => ['ID'],
    'count_total' => true,
    'limit' => 1,
])->getCount();
$nav->setRecordCount($totalCount);

$rows = JobTable::getList([
    'filter' => $filter,
    'order' => ['ID' => 'DESC'],
    'limit' => $nav->getLimit(),
    'offset' => $nav->getOffset(),
])->fetchAll();

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

$statusLabels = [
    JobTable::STATUS_NEW => GetMessage('IBEXPORT_STATUS_NEW'),
    JobTable::STATUS_RUNNING => GetMessage('IBEXPORT_STATUS_RUNNING'),
    JobTable::STATUS_DONE => GetMessage('IBEXPORT_STATUS_DONE'),
    JobTable::STATUS_ERROR => GetMessage('IBEXPORT_STATUS_ERROR'),
];

$lAdmin->AddHeaders([
    ['id' => 'ID', 'content' => GetMessage('IBEXPORT_LOG_COL_ID'), 'default' => true],
    ['id' => 'DATE_CREATE', 'content' => GetMessage('IBEXPORT_LOG_COL_DATE'), 'default' => true],
    ['id' => 'IBLOCK', 'content' => GetMessage('IBEXPORT_LOG_COL_IBLOCK'), 'default' => true],
    ['id' => 'ENTITY', 'content' => GetMessage('IBEXPORT_LOG_COL_ENTITY'), 'default' => true],
    ['id' => 'MODE', 'content' => GetMessage('IBEXPORT_LOG_COL_MODE'), 'default' => true],
    ['id' => 'STATUS', 'content' => GetMessage('IBEXPORT_LOG_COL_STATUS'), 'default' => true],
    ['id' => 'COUNT', 'content' => GetMessage('IBEXPORT_LOG_COL_COUNT'), 'default' => true],
    ['id' => 'SIZE', 'content' => GetMessage('IBEXPORT_LOG_COL_SIZE'), 'default' => true],
    ['id' => 'ACTIONS', 'content' => GetMessage('IBEXPORT_LOG_COL_ACTIONS'), 'default' => true],
]);

foreach ($rows as $jobRow) {
    $ib = \CIBlock::GetArrayByID((int)$jobRow['IBLOCK_ID']);

    if ($jobRow['STATUS'] === JobTable::STATUS_DONE) {
        $actionsHtml = '<a href="/bitrix/admin/vspace_ibexport_download.php?lang=' . LANGUAGE_ID
            . '&JOB_ID=' . (int)$jobRow['ID'] . '&sessid=' . bitrix_sessid() . '">'
            . htmlspecialcharsbx(GetMessage('IBEXPORT_BTN_DOWNLOAD')) . '</a>';
    } elseif (in_array($jobRow['STATUS'], [JobTable::STATUS_NEW, JobTable::STATUS_RUNNING], true)) {
        $actionsHtml = '<a href="/bitrix/admin/vspace_ibexport_progress.php?lang=' . LANGUAGE_ID
            . '&JOB_ID=' . (int)$jobRow['ID'] . '">'
            . htmlspecialcharsbx(GetMessage('IBEXPORT_LOG_VIEW_PROGRESS')) . '</a>';
    } else {
        $actionsHtml = '<span title="' . htmlspecialcharsbx((string)$jobRow['ERROR_MESSAGE']) . '">'
            . htmlspecialcharsbx(GetMessage('IBEXPORT_LOG_HAS_ERROR')) . '</span>';
    }

    $row = &$lAdmin->AddRow($jobRow['ID'], $jobRow);
    $row->AddViewField('ID', (int)$jobRow['ID']);
    $row->AddViewField('DATE_CREATE', htmlspecialcharsbx($jobRow['DATE_CREATE']));
    $row->AddViewField('IBLOCK', htmlspecialcharsbx($ib['NAME'] ?? ('#' . $jobRow['IBLOCK_ID'])));
    $row->AddViewField('ENTITY', htmlspecialcharsbx($jobRow['ENTITY_TYPE']) . ' #' . (int)$jobRow['ENTITY_ID']);
    $row->AddViewField('MODE', htmlspecialcharsbx($jobRow['MODE']));
    $row->AddViewField('STATUS', htmlspecialcharsbx($statusLabels[$jobRow['STATUS']] ?? $jobRow['STATUS']));
    $row->AddViewField('COUNT', (int)$jobRow['PROCESSED_SECTIONS'] . ' / ' . (int)$jobRow['PROCESSED_ELEMENTS']);
    $row->AddViewField('SIZE', $jobRow['ARCHIVE_SIZE'] ? round($jobRow['ARCHIVE_SIZE'] / 1024, 1) . ' KB' : '-');
    $row->AddViewField('ACTIONS', $actionsHtml);
    unset($row);
}

$lAdmin->setNavigation($nav, GetMessage('IBEXPORT_LOG_TITLE'));

$lAdmin->CheckListMode();
$lAdmin->DisplayList();

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
