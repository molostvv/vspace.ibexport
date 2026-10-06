<?php

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Vspace\Ibexport\Admin\AdminMessages;
use Vspace\Ibexport\Admin\ExportPageController;

Loader::includeModule('vspace.ibexport');
Loader::includeModule('iblock');

IncludeModuleLangFile(__FILE__);

$APPLICATION->SetTitle(GetMessage('IBEXPORT_EXPORT_TITLE'));
$APPLICATION->SetAdditionalCSS('/local/modules/vspace.ibexport/admin/css/vibx.css');

// Разбор запроса, валидация, права, расчёт объёма и запуск экспорта — в контроллере;
// он же делает редирект на страницу прогресса при STEP=run. Здесь — только вёрстка.
$page = (new ExportPageController())->handle(\Bitrix\Main\Context::getCurrent()->getRequest());
[
    'errors' => $errors,
    'estimate' => $estimate,
    'iblocks' => $iblocksList,
    'iblockId' => $iblockId,
    'entityType' => $entityType,
    'entityRef' => $entityRef,
    'mode' => $mode,
    'withFiles' => $withFiles,
    'activeOnly' => $activeOnly,
    'recent' => $recent, // последние добавленные элементы и разделы выбранного инфоблока, либо null
] = $page;

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

AdminMessages::showErrors($errors);

// Стандартная детальная форма админки Bitrix (тот же движок, что рисует
// формы редактирования элементов/разделов) — вместо голой <table> без рамки
// и заголовка.
$tabControl = new CAdminTabControl('tabControl', [
    ['DIV' => 'edit1', 'TAB' => GetMessage('IBEXPORT_FORM_HEADING'), 'TITLE' => GetMessage('IBEXPORT_FORM_HEADING')],
]);
?>

<form method="post" action="<?= htmlspecialcharsbx($APPLICATION->GetCurPage()) ?>" name="vibx_export_form" id="vibx_export_form">
    <?php $tabControl->Begin(); ?>
    <?= bitrix_sessid_post() ?>
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
    <?php $tabControl->BeginNextTab(); ?>

    <tr>
        <td width="40%"><?= GetMessage('IBEXPORT_FIELD_IBLOCK') ?></td>
        <td>
            <?php // Смена инфоблока перезагружает страницу: списки последних элементов/разделов ниже — по выбранному инфоблоку ?>
            <select name="IBLOCK_ID" onchange="window.location = '<?= \CUtil::JSEscape($APPLICATION->GetCurPage() . '?lang=' . LANGUAGE_ID . '&IBLOCK_ID=') ?>' + encodeURIComponent(this.value);">
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
        <td><?= GetMessage('IBEXPORT_FIELD_ENTITY_TYPE') ?></td>
        <td>
            <label><input type="radio" name="ENTITY_TYPE" value="element" <?= $entityType === 'element' ? 'checked' : '' ?>> <?= GetMessage('IBEXPORT_ENTITY_ELEMENT') ?></label>
            &nbsp;
            <label><input type="radio" name="ENTITY_TYPE" value="section" <?= $entityType === 'section' ? 'checked' : '' ?>> <?= GetMessage('IBEXPORT_ENTITY_SECTION') ?></label>
        </td>
    </tr>
    <tr>
        <td><?= GetMessage('IBEXPORT_FIELD_ID') ?></td>
        <td><input type="text" size="30" name="ENTITY_REF" value="<?= htmlspecialcharsbx($entityRef) ?>"></td>
    </tr>
    <tr>
        <td><?= GetMessage('IBEXPORT_FIELD_MODE') ?></td>
        <td>
            <label><input type="radio" name="MODE" value="section_single" <?= $mode === 'section_single' ? 'checked' : '' ?>> <?= GetMessage('IBEXPORT_MODE_SINGLE') ?></label>
            <br>
            <label><input type="radio" name="MODE" value="section_tree" <?= $mode === 'section_tree' ? 'checked' : '' ?>> <?= GetMessage('IBEXPORT_MODE_TREE') ?></label>
            <div style="color:#888;font-size:11px;"><?= GetMessage('IBEXPORT_MODE_HINT') ?></div>
        </td>
    </tr>
    <tr>
        <td><?= GetMessage('IBEXPORT_FIELD_FILES') ?></td>
        <td><input type="checkbox" name="WITH_FILES" value="Y" <?= $withFiles ? 'checked' : '' ?>></td>
    </tr>
    <tr>
        <td><?= GetMessage('IBEXPORT_FIELD_ACTIVE_ONLY') ?></td>
        <td><input type="checkbox" name="ACTIVE_ONLY" value="Y" <?= $activeOnly ? 'checked' : '' ?>></td>
    </tr>

    <?php $tabControl->Buttons(); ?>
    <?php if ($estimate !== null): ?>
        <input type="hidden" name="STEP" value="run">
        <input type="submit" class="adm-btn adm-btn-save" value="<?= GetMessage('IBEXPORT_BTN_RUN') ?>">
    <?php else: ?>
        <input type="hidden" name="STEP" value="estimate">
        <input type="submit" class="adm-btn adm-btn-save" value="<?= GetMessage('IBEXPORT_BTN_ESTIMATE') ?>">
    <?php endif; ?>
    <?php $tabControl->End(); ?>
</form>

<?php if ($estimate !== null): ?>
    <?php
    // Результат — отдельным блоком ПОСЛЕ панели формы (штатная область под
    // ней), а не строкой внутри таблицы параметров вперемешку с полями
    // ввода. Оформление своё, без классов ядра: "-gray" без парного
    // "adm-info-message-icon" в этой теме не имеет собственных отступов и
    // выравнивания (см. предыдущую правку), а других нейтральных
    // (не success/error) типов сообщений ядро не предоставляет.
    ?>
    <div class="vibx-note">
        <?= GetMessage('IBEXPORT_ESTIMATE', [
            '#SECTIONS#' => $estimate['sections'],
            '#ELEMENTS#' => $estimate['elements'],
        ]) ?>
    </div>
<?php endif; ?>

<?php
// Последние добавленные элементы и разделы выбранного инфоблока — для быстрого экспорта без поиска ID. Списки —
// штатный CAdminList (данные — массив из контроллера, без сортировки/фильтров/постраничности). Кнопки отправляют
// основную форму (атрибут form): галки и режим — те, что в форме сейчас; сущность и действие — в formaction
// (QUICK_ENTITY, QUICK_ACTION, см. ExportPageController). "Рассчитать объём" (только у разделов — элемент всегда
// выгружается один) подставляет раздел в форму и считает объём, "Экспортировать" сразу запускает выгрузку.
if ($recent === null): ?>
    <div class="vibx-note"><?= GetMessage('IBEXPORT_RECENT_SELECT_IBLOCK') ?></div>
<?php else:
    $quickButton = static fn(string $type, int $id, string $action, string $title): string =>
        '<input type="submit" form="vibx_export_form" class="adm-btn"'
        . ' formaction="' . htmlspecialcharsbx($APPLICATION->GetCurPage() . '?' . http_build_query([
            'lang' => LANGUAGE_ID,
            'QUICK_ENTITY' => $type . ':' . $iblockId . ':' . $id,
            'QUICK_ACTION' => $action,
        ])) . '"'
        . ' value="' . htmlspecialcharsbx($title) . '">';
    foreach (['element' => $recent['elements'], 'section' => $recent['sections']] as $kind => $rows):
        $list = new CAdminList('tbl_vspace_ibexport_recent_' . $kind);
        $list->AddHeaders([
            ['id' => 'ID', 'content' => 'ID', 'default' => true],
            ['id' => 'NAME', 'content' => GetMessage('IBEXPORT_RECENT_COL_NAME'), 'default' => true],
            ['id' => 'CODE', 'content' => GetMessage('IBEXPORT_RECENT_COL_CODE'), 'default' => true],
            ['id' => 'SECTION', 'content' => GetMessage($kind === 'element' ? 'IBEXPORT_RECENT_COL_SECTION' : 'IBEXPORT_RECENT_COL_PARENT'), 'default' => true],
            ['id' => 'ACTIVE', 'content' => GetMessage('IBEXPORT_RECENT_COL_ACTIVE'), 'default' => true],
            ['id' => 'DATE_CREATE', 'content' => GetMessage('IBEXPORT_RECENT_COL_CREATED'), 'default' => true],
            ['id' => 'EXPORT', 'content' => '', 'default' => true],
        ]);
        foreach ($rows as $r) {
            $id = (int)$r['ID'];
            $editUrl = $kind === 'element' ? CIBlock::GetAdminElementEditLink($iblockId, $id) : CIBlock::GetAdminSectionEditLink($iblockId, $id);
            $row = &$list->AddRow($id, $r);
            $row->AddViewField('ID', $id);
            $row->AddViewField('NAME', '<a href="' . htmlspecialcharsbx($editUrl) . '" target="_blank">' . htmlspecialcharsbx($r['NAME']) . '</a>');
            $row->AddViewField('CODE', htmlspecialcharsbx((string)$r['CODE']));
            $row->AddViewField('SECTION', $r['SECTION_ID'] ? '[' . (int)$r['SECTION_ID'] . '] ' . htmlspecialcharsbx($r['SECTION_NAME']) : '');
            $row->AddViewField('ACTIVE', GetMessage($r['ACTIVE'] === 'Y' ? 'IBEXPORT_RECENT_YES' : 'IBEXPORT_RECENT_NO'));
            $row->AddViewField('DATE_CREATE', $r['DATE_CREATE'] ? htmlspecialcharsbx($r['DATE_CREATE']->toString()) : '');
            $row->AddViewField('EXPORT', '<span class="vibx-nowrap">'
                . ($kind === 'section' ? $quickButton($kind, $id, 'estimate', GetMessage('IBEXPORT_RECENT_BTN_ESTIMATE')) . ' ' : '')
                . $quickButton($kind, $id, 'run', GetMessage('IBEXPORT_RECENT_BTN'))
                . '</span>');
            unset($row);
        }
        ?>
        <div class="vibx-list-heading"><?= GetMessage($kind === 'element' ? 'IBEXPORT_RECENT_ELEMENTS' : 'IBEXPORT_RECENT_SECTIONS', ['#COUNT#' => ExportPageController::RECENT_LIMIT]) ?></div>
        <?php if ($kind === 'element'): ?>
            <div class="vibx-list-hint"><?= GetMessage('IBEXPORT_RECENT_HINT') ?></div>
        <?php endif; ?>
        <?php if (!$rows): ?>
            <div class="vibx-note"><?= GetMessage($kind === 'element' ? 'IBEXPORT_RECENT_NO_ELEMENTS' : 'IBEXPORT_RECENT_NO_SECTIONS') ?></div>
        <?php else: ?>
            <?php $list->DisplayList(); ?>
        <?php endif; ?>
    <?php endforeach; ?>
<?php endif; ?>

<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
