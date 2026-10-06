<?php

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Vspace\Ibexport\Admin\AdminMessages;
use Vspace\Ibexport\Admin\ImportPageController;
use Vspace\Ibexport\Export\ExportStep;
use Vspace\Ibexport\Options;
use Vspace\Ibexport\YandexDisk\Settings;

Loader::includeModule('vspace.ibexport');
Loader::includeModule('iblock');

IncludeModuleLangFile(__FILE__);

/** Яндекс.Диск отдаёт "modified" в ISO 8601 (2026-09-18T17:48:45+00:00) — в списке файлов показываем в привычном для админки виде. */
function vibxFormatDiskDate(string $iso): string
{
    $dt = date_create($iso);
    return $dt ? $dt->format('d.m.Y H:i:s') : $iso;
}

$APPLICATION->SetTitle(GetMessage('IBIMPORT_TITLE'));
$APPLICATION->SetAdditionalCSS('/local/modules/vspace.ibexport/admin/css/vibx.css');

// Разбор запроса, валидация, права и шаги validate/disk_list/disk_import/run — в
// контроллере; он же делает редирект на страницу прогресса при STEP=run. Здесь —
// только вёрстка.
$page = (new ImportPageController())->handle(\Bitrix\Main\Context::getCurrent()->getRequest());
[
    'errors' => $errors,
    'prepared' => $prepared, // результат Importer::prepareUpload()/ImportSource::prepareFromDisk() либо null
    'preview' => $preview, // результат Importer::preview() — что будет создано/обновлено, либо null
    'diskFiles' => $diskFiles, // результат "Проверить Диск" — список файлов в папке обмена на Яндекс.Диске, либо null
    'iblocks' => $iblocksList,
    'iblockId' => $iblockId,
    'parentSectionRef' => $parentSectionRef,
    'updateByCode' => $updateByCode,
    'matchByXmlId' => $matchByXmlId,
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
    <tr>
        <td><?= GetMessage('IBIMPORT_FIELD_MATCH_BY_XML_ID') ?></td>
        <td>
            <input type="checkbox" name="MATCH_BY_XML_ID" value="Y" <?= $matchByXmlId ? 'checked' : '' ?>>
            <div style="color:#888;font-size:11px;"><?= GetMessage('IBIMPORT_MATCH_BY_XML_ID_HINT') ?></div>
            <div class="errortext" style="font-size:11px;"><?= GetMessage('IBIMPORT_MATCH_BY_XML_ID_RISK') ?></div>
        </td>
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
                <?php $formatVersion = (int)($prepared['format_version'] ?? 0); ?>
                <?php if ($formatVersion < ExportStep::FORMAT_VERSION_UNESCAPED): ?>
                    <div class="vibx-note errortext"><?= GetMessage('IBIMPORT_LEGACY_FORMAT') ?></div>
                <?php elseif ($formatVersion < ExportStep::FORMAT_VERSION_SEO): ?>
                    <div class="vibx-note"><?= GetMessage('IBIMPORT_NO_SEO') ?></div>
                <?php endif; ?>
                <?php if (!empty($prepared['ignored_entries'])): ?>
                    <div class="vibx-note"><?= GetMessage('IBIMPORT_IGNORED_ENTRIES', ['#COUNT#' => (int)$prepared['ignored_entries']]) ?></div>
                <?php endif; ?>
            </td>
        </tr>
        <input type="hidden" name="TMP_DIR" value="<?= htmlspecialcharsbx($prepared['tmp_dir']) ?>">
        <input type="hidden" name="SOURCE_FILE_NAME" value="<?= htmlspecialcharsbx($prepared['source_file_name']) ?>">
    <?php endif; ?>

    <?php $tabControl->Buttons(); ?>
    <?php if ($prepared !== null): ?>
        <?php // Первая кнопка формы — "Запустить импорт": именно её срабатывает Enter; STEP передаётся значением нажатой кнопки. ?>
        <button type="submit" name="STEP" value="run" class="adm-btn adm-btn-save"><?= GetMessage('IBIMPORT_BTN_RUN') ?></button>
        <button type="submit" name="STEP" value="recalc" class="adm-btn"><?= GetMessage('IBIMPORT_BTN_RECALC') ?></button>
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

<?php if ($preview !== null): ?>
    <?php
    // Предпросмотр импорта — штатный CAdminList (тот же, что в журналах модуля), данные — массив из
    // Importer::preview(), а не выборка из БД: без сортировки, фильтров и постраничности (список
    // усечён до ImportPreview::DEFAULT_LIMIT строк). Своего CSS нет.
    $previewList = new CAdminList('tbl_vspace_ibexport_import_preview');
    $previewList->AddHeaders([
        ['id' => 'KIND', 'content' => GetMessage('IBIMPORT_PREVIEW_COL_KIND'), 'default' => true],
        ['id' => 'SRC_ID', 'content' => GetMessage('IBIMPORT_PREVIEW_COL_SRC_ID'), 'default' => true],
        ['id' => 'XML_ID', 'content' => GetMessage('IBIMPORT_PREVIEW_COL_XML_ID'), 'default' => true],
        ['id' => 'CODE', 'content' => GetMessage('IBIMPORT_PREVIEW_COL_CODE'), 'default' => true],
        ['id' => 'NAME', 'content' => GetMessage('IBIMPORT_PREVIEW_COL_NAME'), 'default' => true],
        ['id' => 'ACTIVE', 'content' => GetMessage('IBIMPORT_PREVIEW_COL_ACTIVE'), 'default' => true],
        ['id' => 'CONTEXT', 'content' => GetMessage('IBIMPORT_PREVIEW_COL_CONTEXT'), 'default' => true],
        ['id' => 'FILES', 'content' => GetMessage('IBIMPORT_PREVIEW_COL_FILES'), 'default' => true],
        ['id' => 'PROPS', 'content' => GetMessage('IBIMPORT_PREVIEW_COL_PROPS'), 'default' => true],
        ['id' => 'MATCH', 'content' => GetMessage('IBIMPORT_PREVIEW_COL_MATCH'), 'default' => true],
        ['id' => 'FOUND', 'content' => GetMessage('IBIMPORT_PREVIEW_COL_FOUND'), 'default' => true],
        ['id' => 'NOTE', 'content' => GetMessage('IBIMPORT_PREVIEW_COL_NOTE'), 'default' => true],
        ['id' => 'ACTION', 'content' => GetMessage('IBIMPORT_PREVIEW_COL_ACTION'), 'default' => true],
    ]);
    $suspicious = 0;
    foreach ($preview['rows'] as $n => $r) {
        if ($r['target_id'] !== null) {
            $matchText = GetMessage($r['match_by'] === 'XML_ID' ? 'IBIMPORT_PREVIEW_MATCH_XML_ID' : 'IBIMPORT_PREVIEW_MATCH_CODE', ['#ID#' => $r['target_id']]);
        } else {
            $matchText = GetMessage($r['match_by'] === null ? 'IBIMPORT_PREVIEW_MATCH_NO_KEY' : 'IBIMPORT_PREVIEW_MATCH_NONE');
        }
        // Найденная запись — ссылкой на её страницу редактирования (штатные CIBlock::GetAdmin*EditLink), чтобы можно было проверить глазами.
        $foundHtml = '';
        if ($r['target_id'] !== null) {
            $editUrl = $r['kind'] === 'section'
                ? CIBlock::GetAdminSectionEditLink($iblockId, $r['target_id'])
                : CIBlock::GetAdminElementEditLink($iblockId, $r['target_id']);
            $foundHtml = '<a href="' . htmlspecialcharsbx($editUrl) . '" target="_blank">[' . (int)$r['target_id'] . '] ' . htmlspecialcharsbx($r['target_name']) . '</a>';
        }
        $noteHtml = '';
        if ($r['flags']) {
            $suspicious++;
            $noteHtml = '<span class="errortext">' . htmlspecialcharsbx(implode('; ', array_map(
                static fn(string $flag): string => GetMessage('IBIMPORT_PREVIEW_FLAG_' . strtoupper($flag)),
                $r['flags']
            ))) . '</span>';
        }
        if ($r['missing']) {
            // Не предупреждение о совпадении, а сведение: значения этих свойств/полей импорт пропустит.
            $noteHtml .= ($noteHtml !== '' ? '<br>' : '') . htmlspecialcharsbx(GetMessage('IBIMPORT_PREVIEW_MISSING_NOTE', ['#CODES#' => implode(', ', $r['missing'])]));
        }
        $row = &$previewList->AddRow($n + 1, $r);
        $row->AddViewField('KIND', htmlspecialcharsbx(GetMessage('IBIMPORT_PREVIEW_KIND_' . strtoupper($r['kind']))));
        $row->AddViewField('SRC_ID', (int)$r['src_id']);
        $row->AddViewField('XML_ID', htmlspecialcharsbx($r['xml_id']));
        $row->AddViewField('CODE', htmlspecialcharsbx($r['code']));
        $row->AddViewField('NAME', htmlspecialcharsbx($r['name']));
        $row->AddViewField('ACTIVE', GetMessage($r['active'] ? 'IBIMPORT_PREVIEW_YES' : 'IBIMPORT_PREVIEW_NO'));
        $row->AddViewField('CONTEXT', htmlspecialcharsbx($r['context']));
        $row->AddViewField('FILES', (int)$r['files']);
        $row->AddViewField('PROPS', (int)$r['props']);
        $row->AddViewField('MATCH', htmlspecialcharsbx($matchText));
        $row->AddViewField('FOUND', $foundHtml);
        $row->AddViewField('NOTE', $noteHtml);
        $row->AddViewField('ACTION', htmlspecialcharsbx(GetMessage('IBIMPORT_PREVIEW_ACTION_' . strtoupper($r['action']))));
        unset($row);
    }
    ?>
    <div class="vibx-list-heading"><?= GetMessage('IBIMPORT_PREVIEW_HEADING') ?></div>
    <?php if ($suspicious > 0): ?>
        <?php AdminMessages::showErrors([GetMessage('IBIMPORT_PREVIEW_SUSPICIOUS', ['#COUNT#' => $suspicious])]); ?>
    <?php endif; ?>
    <?php
    // Свойства элементов и UF-поля разделов, которых нет в целевом инфоблоке (их значения импорт пропустит).
    foreach (['missing_props' => 'IBIMPORT_PREVIEW_MISSING_PROPS', 'missing_uf' => 'IBIMPORT_PREVIEW_MISSING_UF'] as $missingKey => $missingMessage) {
        if (!$preview[$missingKey]) {
            continue;
        }
        $list = [];
        foreach ($preview[$missingKey] as $missingCode => $missingCount) {
            $list[] = $missingCode . ' (' . $missingCount . ')';
        }
        ?>
        <div class="vibx-note"><?= htmlspecialcharsbx(GetMessage($missingMessage, ['#LIST#' => implode(', ', $list)])) ?><?= $preview['truncated'] ? ' ' . htmlspecialcharsbx(GetMessage('IBIMPORT_PREVIEW_MISSING_PARTIAL')) : '' ?></div>
        <?php
    }
    ?>
    <div style="color:#888;font-size:11px;margin-bottom:6px;"><?= GetMessage('IBIMPORT_PREVIEW_HINT') ?></div>
    <?php if ($preview['truncated']): ?>
        <div class="vibx-note"><?= GetMessage('IBIMPORT_PREVIEW_TRUNCATED', ['#SHOWN#' => count($preview['rows']), '#TOTAL#' => (int)$prepared['sections'] + (int)$prepared['elements']]) ?></div>
    <?php endif; ?>
    <?php $previewList->DisplayList(); ?>
<?php endif; ?>

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
                        <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner"><?= htmlspecialcharsbx(vibxFormatDiskDate($file['modified'])) ?></div></td>
                        <td class="adm-list-table-cell">
                            <div class="adm-list-table-cell-inner">
                                <form method="post" action="<?= htmlspecialcharsbx($APPLICATION->GetCurPage()) ?>" style="display:inline;">
                                    <?= bitrix_sessid_post() ?>
                                    <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
                                    <input type="hidden" name="IBLOCK_ID" value="<?= (int)$iblockId ?>">
                                    <?php // Настройки формы выше переносим в следующий шаг: контроллер при наличии STEP читает чекбокс как "снят", если ключа нет ?>
                                    <input type="hidden" name="PARENT_SECTION_REF" value="<?= htmlspecialcharsbx($parentSectionRef) ?>">
                                    <?php if ($updateByCode): ?>
                                        <input type="hidden" name="UPDATE_BY_CODE" value="Y">
                                    <?php endif; ?>
                                    <?php if ($matchByXmlId): ?>
                                        <input type="hidden" name="MATCH_BY_XML_ID" value="Y">
                                    <?php endif; ?>
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
