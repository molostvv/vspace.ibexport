<?php

namespace Vspace\Ibexport\Admin;

use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\SectionTable;
use Bitrix\Main\HttpRequest;
use Vspace\Ibexport\Exporter;
use Vspace\Ibexport\IblockListProvider;
use Vspace\Ibexport\Options;
use Vspace\Ibexport\Rights;

/**
 * Обработка запроса страницы "Новая выгрузка" (admin/export.php): разбор
 * параметров, валидация, проверка прав, расчёт объёма (STEP=estimate) и
 * запуск экспорта (STEP=run). Кнопки в списках последних элементов/разделов — параметры QUICK_ENTITY
 * (что выгружать) и QUICK_ACTION (estimate — подставить в форму и рассчитать объём, иначе — сразу
 * запустить экспорт). HTML не формирует — возвращает данные
 * для отрисовки формы; при запуске задания делает редирект на страницу прогресса.
 *
 * Тексты ошибок берутся из языкового файла страницы (lang/ru/admin/export.php):
 * страница обязана подключить его через IncludeModuleLangFile() до handle().
 */
final class ExportPageController
{
    /** Сколько последних изменённых (в том числе новых) элементов и разделов показывать под формой. */
    public const RECENT_LIMIT = 10;

    /**
     * @return array{
     *     errors: string[],
     *     estimate: array|null,
     *     iblocks: array[],
     *     iblockId: int,
     *     entityType: string,
     *     entityRef: string,
     *     mode: string,
     *     withFiles: bool,
     *     activeOnly: bool,
     *     recent: array{elements: array[], sections: array[]}|null
     * }
     *  recent — последние изменённые (в том числе новые) элементы и разделы выбранного инфоблока (null — инфоблок
     *  не выбран или недоступен): строки ID, NAME, CODE, ACTIVE, TIMESTAMP_X и раздел (SECTION_ID, SECTION_NAME —
     *  основной раздел элемента либо родитель раздела).
     */
    public function handle(HttpRequest $request): array
    {
        $errors = [];
        $estimate = null;

        $iblockId = (int)($request->get('IBLOCK_ID') ?? 0);
        $entityType = in_array($request->get('ENTITY_TYPE') ?? '', ['element', 'section'], true) ? $request->get('ENTITY_TYPE') : 'element';
        $entityRef = trim((string)($request->get('ENTITY_REF') ?? ''));
        $mode = in_array($request->get('MODE') ?? '', ['section_single', 'section_tree'], true) ? $request->get('MODE') : 'section_single';
        $step = $request->get('STEP') ?? '';
        // Снятый чекбокс браузер вообще не передаёт в POST — isset() тут не отличит
        // "форму ещё не отправляли" от "пользователь снял галку" (см. тот же фикс
        // в ImportPageController). Различаем по факту отправки формы (наличию STEP).
        $withFiles = $step !== '' ? (($request->get('WITH_FILES') ?? '') === 'Y') : Options::getDefaultWithFiles();
        $activeOnly = $step !== '' ? (($request->get('ACTIVE_ONLY') ?? '') === 'Y') : Options::getDefaultActiveOnly();

        // Кнопки "Рассчитать объём" и "Экспортировать" в списках последних элементов/разделов: отправляют основную
        // форму (галки и режим — текущие), а сущность и действие — в formaction (QUICK_ENTITY=тип:инфоблок:ID,
        // QUICK_ACTION; в POST формы таких полей нет, поэтому значения из адреса не перекрываются). Дальше — обычный
        // расчёт объёма (сущность при этом остаётся в форме для "Запустить экспорт") либо сразу запуск.
        if (preg_match('/^(element|section):(\d+):(\d+)$/', (string)($request->get('QUICK_ENTITY') ?? ''), $quick)) {
            [, $entityType, $quickIblockId, $entityRef] = $quick;
            $iblockId = (int)$quickIblockId;
            $step = ($request->get('QUICK_ACTION') ?? '') === 'estimate' ? 'estimate' : 'run';
        }

        // Инфоблоки, из которых текущий пользователь может выгружать данные (раздел 10 ТЗ).
        $iblocks = IblockListProvider::getAvailable(Rights::canExport(...));

        if ($step === 'estimate' || $step === 'run') {
            try {
                if ($iblockId <= 0) {
                    throw new \Exception(GetMessage('IBEXPORT_ERR_NO_IBLOCK'));
                }
                if (!Rights::canExport($iblockId)) {
                    throw new \Exception(GetMessage('IBEXPORT_ERR_NO_RIGHTS'));
                }
                if ($entityRef === '') {
                    throw new \Exception(GetMessage('IBEXPORT_ERR_NO_ID'));
                }

                if ($entityType === 'element') {
                    $entityId = Exporter::resolveElementId($iblockId, $entityRef);
                    if (!$entityId) {
                        throw new \Exception(GetMessage('IBEXPORT_ERR_ELEMENT_NOT_FOUND'));
                    }
                    $mode = 'element';
                } else {
                    $entityId = Exporter::resolveSectionId($iblockId, $entityRef);
                    if (!$entityId) {
                        throw new \Exception(GetMessage('IBEXPORT_ERR_SECTION_NOT_FOUND'));
                    }
                }

                $params = [
                    'IBLOCK_ID' => $iblockId,
                    'ENTITY_TYPE' => $entityType,
                    'ENTITY_ID' => $entityId,
                    'MODE' => $mode,
                    'WITH_FILES' => $withFiles,
                    'ACTIVE_ONLY' => $activeOnly,
                ];

                if ($step === 'estimate') {
                    $estimate = Exporter::estimate($params);
                } else { // STEP=run — фактический запуск экспорта
                    if (!check_bitrix_sessid()) {
                        throw new \Exception(GetMessage('IBEXPORT_ERR_SESSID'));
                    }
                    $jobId = Exporter::createJob($params);
                    LocalRedirect('/bitrix/admin/vspace_ibexport_progress.php?lang=' . LANGUAGE_ID . '&JOB_ID=' . $jobId);
                }
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        return [
            'errors' => $errors,
            'estimate' => $estimate,
            'iblocks' => $iblocks,
            'iblockId' => $iblockId,
            'entityType' => $entityType,
            'entityRef' => $entityRef,
            'mode' => $mode,
            'withFiles' => $withFiles,
            'activeOnly' => $activeOnly,
            'recent' => $iblockId > 0 && Rights::canExport($iblockId) ? $this->recent($iblockId) : null,
        ];
    }

    /**
     * Последние изменённые элементы и разделы инфоблока (по дате изменения: новые записи туда тоже попадают) — для
     * быстрого экспорта без поиска: переносить обычно нужно как раз то, что только что создали или поправили.
     */
    private function recent(int $iblockId): array
    {
        $elements = ElementTable::getList([
            'filter' => ['=IBLOCK_ID' => $iblockId],
            'select' => ['ID', 'NAME', 'CODE', 'ACTIVE', 'TIMESTAMP_X', 'SECTION_ID' => 'IBLOCK_SECTION_ID'],
            'order' => ['TIMESTAMP_X' => 'DESC', 'ID' => 'DESC'],
            'limit' => self::RECENT_LIMIT,
        ])->fetchAll();
        $sections = SectionTable::getList([
            'filter' => ['=IBLOCK_ID' => $iblockId],
            'select' => ['ID', 'NAME', 'CODE', 'ACTIVE', 'TIMESTAMP_X', 'SECTION_ID' => 'IBLOCK_SECTION_ID'],
            'order' => ['TIMESTAMP_X' => 'DESC', 'ID' => 'DESC'],
            'limit' => self::RECENT_LIMIT,
        ])->fetchAll();

        // Названия разделов (основной раздел элемента, родитель раздела) — одним запросом.
        $sectionIds = array_values(array_unique(array_filter(array_map('intval', array_merge(
            array_column($elements, 'SECTION_ID'),
            array_column($sections, 'SECTION_ID')
        )))));
        $names = $sectionIds
            ? array_column(SectionTable::getList(['filter' => ['@ID' => $sectionIds], 'select' => ['ID', 'NAME']])->fetchAll(), 'NAME', 'ID')
            : [];
        $withSectionName = static function (array $row) use ($names): array {
            $row['SECTION_NAME'] = (string)($names[(int)$row['SECTION_ID']] ?? '');
            return $row;
        };

        return ['elements' => array_map($withSectionName, $elements), 'sections' => array_map($withSectionName, $sections)];
    }
}
