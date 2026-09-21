<?php

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Vspace\Ibexport\Admin\AdminMessages;
use Vspace\Ibexport\Admin\ImportPageController;
use Vspace\Ibexport\Options;
use Vspace\Ibexport\YandexDisk\Settings;

Loader::includeModule('vspace.ibexport');
Loader::includeModule('iblock');

IncludeModuleLangFile(__FILE__);

$APPLICATION->SetTitle(GetMessage('IBIMPORT_TITLE'));
$APPLICATION->SetAdditionalCSS('/local/modules/vspace.ibexport/admin/css/vibx.css');

// Разбор запроса, валидация, права и шаги validate/disk_list/disk_import/run — в
// контроллере; он же делает редирект на страницу прогресса при STEP=run. Здесь —
// только вёрстка.
$page = (new ImportPageController())->handle(\Bitrix\Main\Context::getCurrent()->getRequest());
[
    'errors' => $errors,
    'prepared' => $prepared, // результат Importer::prepareUpload()/ImportSource::prepareFromDisk() либо null
    'diskFiles' => $diskFiles, // результат "Проверить Диск" — список файлов в папке обмена на Яндекс.Диске, либо null
    'iblocks' => $iblocksList,
    'iblockId' => $iblockId,
    'parentSectionRef' => $parentSectionRef,
    'updateByCode' => $updateByCode,
] = $page;

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

AdminMessages::showErrors($errors);

// Стандартная детальная форма админки Bitrix, как и на странице экспорта.
$tabControl = new CAdminTabControl('tabControl', [
    ['DIV' => 'edit1', 'TAB' => GetMessage('IBIMPORT_FORM_HEADING'), 'TITLE' => GetMessage('IBIMPORT_FORM_HEADING')],
]);
?>

<form method="post" action="<?= htmlspecialcharsbx($APPLICATION->GetCurPage()) ?>" name="vibx_import_form" enctype="multipart/form-data">
    <?php $tabControl->Begin(); ?>
    <?= bitrix_sessid_post() ?>
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
    <?php $tabControl->BeginNextTab(); ?>

    <tr>
        <td width="40%"><?= GetMessage('IBIMPORT_FIELD_IBLOCK') ?></td>
        <td>
            <select name="IBLOCK_ID">
                <option value="0">...</option>
                <?php foreach ($iblocksList as $ib): ?>
                    <option value="<?= (int)$ib['ID'] ?>" <?= $iblockId === (int)$ib['ID'] ? 'selected' : '' ?>>
                        [<?= (int)$ib['ID'] ?>] <?= htmlspecialcharsbx($ib['NAME']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
    </tr>
    <tr>
        <td><?= GetMessage('IBIMPORT_FIELD_FILE') ?></td>
        <td>
            <input type="file" name="ARCHIVE" accept=".zip">
            <?php if ($prepared !== null): ?>
                <div style="color:#888;font-size:11px;"><?= GetMessage('IBIMPORT_FILE_LOADED', ['#NAME#' => htmlspecialcharsbx($prepared['source_file_name'])]) ?></div>
            <?php endif; ?>
        </td>
    </tr>
    <tr>
        <td><?= GetMessage('IBIMPORT_FIELD_PARENT_SECTION') ?></td>
        <td>
            <input type="text" size="30" name="PARENT_SECTION_REF" value="<?= htmlspecialcharsbx($parentSectionRef) ?>">
            <div style="color:#888;font-size:11px;"><?= GetMessage('IBIMPORT_PARENT_SECTION_HINT') ?></div>
        </td>
    </tr>
    <tr>
        <td><?= GetMessage('IBIMPORT_FIELD_UPDATE_BY_CODE') ?></td>
        <td><input type="checkbox" name="UPDATE_BY_CODE" value="Y" <?= $updateByCode ? 'checked' : '' ?>></td>
    </tr>

    <?php if ($prepared !== null): ?>
        <tr>
            <td colspan="2">
                <div class="vibx-note">
                    <?= GetMessage('IBIMPORT_ESTIMATE', [
                        '#MODE#' => GetMessage('IBIMPORT_MODE_' . strtoupper($prepared['mode'])),
                        '#SECTIONS#' => $prepared['sections'],
                        '#ELEMENTS#' => $prepared['elements'],
                    ]) ?>
                </div>
            </td>
        </tr>
        <input type="hidden" name="TMP_DIR" value="<?= htmlspecialcharsbx($prepared['tmp_dir']) ?>">
        <input type="hidden" name="SOURCE_FILE_NAME" value="<?= htmlspecialcharsbx($prepared['source_file_name']) ?>">
    <?php endif; ?>

    <?php $tabControl->Buttons(); ?>
    <?php if ($prepared !== null): ?>
        <input type="hidden" name="STEP" value="run">
        <input type="submit" class="adm-btn adm-btn-save" value="<?= GetMessage('IBIMPORT_BTN_RUN') ?>">
    <?php else: ?>
        <button type="submit" name="STEP" value="validate" class="adm-btn adm-btn-save"><?= GetMessage('IBIMPORT_BTN_VALIDATE') ?></button>
        <?php if (Options::isYandexDiskEnabled() && Settings::hasToken()): ?>
            <?php
            // Отдельная, независимая от загрузки файла кнопка (ТЗ "Экспорт
            // в Яндекс.Диск", раздел 3) — формметод GET, чтобы не заходить
            // в основную ветку STEP=validate; недоступность Диска в момент
            // нажатия не мешает обычной загрузке .zip выше.
            ?>
            <button type="submit" name="STEP" value="disk_list" formmethod="get" class="adm-btn"><?= GetMessage('IBYADISK_BTN_CHECK') ?></button>
        <?php endif; ?>
    <?php endif; ?>
    <?php $tabControl->End(); ?>
</form>

<?php if ($diskFiles !== null): ?>
    <?php
    // Список файлов на Диске — превью-список для выбора файла перед
    // импортом, а не постоянная сущность с сортировкой/фильтрами/БД, для
    // которой создан CAdminList/CAdminUiList — поэтому здесь не полноценный
    // список ядра, а обычная таблица. Но именно вложенная разметка
    // (adm-list-table-wrap снаружи, adm-list-table-cell-inner в каждой
    // ячейке) — это то, что и в ядровом CAdminList реально даёт рамку,
    // скругление и внутренние отступы (см. bitrix/modules/main/interface/
    // admin_list.php) — без неё классы adm-list-table* остаются почти без
    // видимого эффекта, только сами имена классов.
    ?>
    <div class="vibx-list-heading"><?= GetMessage('IBYADISK_LIST_HEADING') ?></div>
    <?php if (!$diskFiles): ?>
        <div class="vibx-note"><?= GetMessage('IBYADISK_LIST_EMPTY') ?></div>
    <?php else: ?>
        <div class="adm-list-table-wrap">
            <table class="adm-list-table vibx-disk-list-table" style="width: 100%;">
                <thead>
                <tr class="adm-list-table-header">
                    <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner"><?= GetMessage('IBYADISK_COL_NAME') ?></div></td>
                    <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner"><?= GetMessage('IBYADISK_COL_SIZE') ?></div></td>
                    <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner"><?= GetMessage('IBYADISK_COL_MODIFIED') ?></div></td>
                    <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">&nbsp;</div></td>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($diskFiles as $file): ?>
                    <tr class="adm-list-table-row">
                        <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner"><?= htmlspecialcharsbx($file['name']) ?></div></td>
                        <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner"><?= number_format($file['size'] / 1024, 1, '.', ' ') ?> <?= GetMessage('IBYADISK_KB') ?></div></td>
                        <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner"><?= htmlspecialcharsbx($file['modified']) ?></div></td>
                        <td class="adm-list-table-cell">
                            <div class="adm-list-table-cell-inner">
                                <form method="get" action="<?= htmlspecialcharsbx($APPLICATION->GetCurPage()) ?>" style="display:inline;">
                                    <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
                                    <input type="hidden" name="IBLOCK_ID" value="<?= (int)$iblockId ?>">
                                    <input type="hidden" name="STEP" value="disk_import">
                                    <input type="hidden" name="DISK_PATH" value="<?= htmlspecialcharsbx($file['path']) ?>">
                                    <input type="submit" class="adm-btn" value="<?= GetMessage('IBYADISK_BTN_IMPORT_FILE') ?>">
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
