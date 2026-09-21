<?php

namespace Vspace\Ibexport\Admin;

use Bitrix\Main\HttpRequest;
use Vspace\Ibexport\Exporter;
use Vspace\Ibexport\IblockListProvider;
use Vspace\Ibexport\Importer;
use Vspace\Ibexport\Options;
use Vspace\Ibexport\Rights;
use Vspace\Ibexport\YandexDisk\Exception as YandexDiskException;
use Vspace\Ibexport\YandexDisk\ImportSource;

/**
 * Обработка запроса страницы "Импорт" (admin/import.php). Шаги (параметр STEP):
 *  - validate    — принять загруженный архив и посчитать объём;
 *  - recalc      — пересчитать предпросмотр уже принятого архива по текущим отметкам формы (без повторной загрузки);
 *  - disk_list   — показать список файлов в папке обмена на Яндекс.Диске;
 *  - disk_import — принять выбранный файл с Диска (то же, что validate, но источник — Диск);
 *  - run         — создать задание импорта по уже провалидированному архиву и перейти к странице прогресса.
 * HTML не формирует — возвращает данные для отрисовки формы.
 *
 * Тексты ошибок берутся из языкового файла страницы (lang/ru/admin/import.php):
 * страница обязана подключить его через IncludeModuleLangFile() до handle().
 */
final class ImportPageController
{
    /**
     * @return array{
     *     errors: string[],
     *     prepared: array|null,
     *     preview: array{rows: array[], truncated: bool}|null,
     *     diskFiles: array[]|null,
     *     iblocks: array[],
     *     iblockId: int,
     *     parentSectionRef: string,
     *     updateByCode: bool,
     *     matchByXmlId: bool
     * }
     *  prepared — результат Importer::prepareUpload()/ImportSource::prepareFromDisk(), null — архив ещё не принят;
     *  preview — результат Importer::preview() для принятого архива (что будет создано/обновлено), null — архив не принят;
     *  diskFiles — результат "Проверить Диск", null — шаг не выполнялся.
     */
    public function handle(HttpRequest $request): array
    {
        $errors = [];
        $prepared = null;
        $preview = null;
        $diskFiles = null;

        $iblockId = (int)($request->get('IBLOCK_ID') ?? 0);
        $parentSectionRef = trim((string)($request->get('PARENT_SECTION_REF') ?? ''));
        $step = $request->get('STEP') ?? '';
        // Снятый чекбокс браузер вообще не передаёт в POST — isset() тут не отличит
        // "форму ещё не отправляли" от "пользователь снял галку". Различаем по
        // факту отправки формы (наличию STEP): при первом заходе — значение по
        // умолчанию, при реальном сабмите — ровно то, что пришло (отсутствие ключа
        // после отправки формы означает "снято").
        $updateByCode = $step !== '' ? (($request->get('UPDATE_BY_CODE') ?? '') === 'Y') : Options::getDefaultUpdateByCode();
        // Запасной ключ сопоставления для записей без символьного кода: всегда выключен по умолчанию, включается вручную.
        $matchByXmlId = $step !== '' && (($request->get('MATCH_BY_XML_ID') ?? '') === 'Y');

        // Инфоблоки, в которые текущий пользователь может импортировать данные.
        $iblocks = IblockListProvider::getAvailable(Rights::canImport(...));

        if (in_array($step, ['validate', 'recalc', 'run', 'disk_list', 'disk_import'], true)) {
            try {
                if ($iblockId <= 0) {
                    throw new \Exception(GetMessage('IBIMPORT_ERR_NO_IBLOCK'));
                }
                if (!Rights::canImport($iblockId)) {
                    throw new \Exception(GetMessage('IBIMPORT_ERR_NO_RIGHTS'));
                }

                if ($step === 'validate') {
                    $prepared = Importer::prepareUpload($request->getFile('ARCHIVE') ?? []);
                } elseif ($step === 'recalc') {
                    // Тот же уже принятый архив (скрытые поля формы) — заново считаем предпросмотр по текущим отметкам, файл не перезагружается.
                    $prepared = Importer::reopen((string)($request->get('TMP_DIR') ?? ''), (string)($request->get('SOURCE_FILE_NAME') ?? ''));
                } elseif ($step === 'disk_list') {
                    // Отдельный, полностью ручной шаг (ТЗ "Экспорт в Яндекс.Диск",
                    // раздел 3) — недоступность Диска здесь не мешает обычной
                    // ручной загрузке архива веткой STEP=validate выше.
                    try {
                        $diskFiles = ImportSource::listDiskFiles();
                    } catch (YandexDiskException $e) {
                        $errors[] = GetMessage('IBYADISK_LIST_ERROR', ['#MESSAGE#' => $e->getMessage()]);
                    }
                } elseif ($step === 'disk_import') {
                    $diskPath = (string)($request->get('DISK_PATH') ?? '');
                    if ($diskPath === '') {
                        throw new \Exception(GetMessage('IBYADISK_ERR_NO_PATH'));
                    }
                    try {
                        $prepared = ImportSource::prepareFromDisk($diskPath);
                    } catch (YandexDiskException $e) {
                        throw new \Exception(GetMessage('IBYADISK_DOWNLOAD_ERROR', ['#MESSAGE#' => $e->getMessage()]));
                    }
                } else { // STEP=run — уже провалидированный на предыдущем шаге архив
                    if (!check_bitrix_sessid()) {
                        throw new \Exception(GetMessage('IBIMPORT_ERR_SESSID'));
                    }

                    $tmpDirName = (string)($request->get('TMP_DIR') ?? '');
                    Importer::resolveTmpDir($tmpDirName); // бросит исключение, если архив не найден/устарел

                    $parentSectionId = 0;
                    if ($parentSectionRef !== '') {
                        $parentSectionId = Exporter::resolveSectionId($iblockId, $parentSectionRef);
                        if (!$parentSectionId) {
                            throw new \Exception(GetMessage('IBIMPORT_ERR_PARENT_NOT_FOUND'));
                        }
                    }

                    $jobId = Importer::createJob([
                        'TARGET_IBLOCK_ID' => $iblockId,
                        'PARENT_SECTION_ID' => $parentSectionId,
                        'UPDATE_BY_CODE' => $updateByCode,
                        'MATCH_BY_XML_ID' => $matchByXmlId,
                        'TMP_DIR' => $tmpDirName,
                        'SOURCE_FILE_NAME' => (string)($request->get('SOURCE_FILE_NAME') ?? ''),
                    ]);
                    LocalRedirect('/bitrix/admin/vspace_ibexport_import_progress.php?lang=' . LANGUAGE_ID . '&JOB_ID=' . $jobId);
                }

                if ($prepared !== null) {
                    $preview = Importer::preview($prepared['tmp_dir'], $iblockId, $updateByCode, $matchByXmlId);
                }
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        return [
            'errors' => $errors,
            'prepared' => $prepared,
            'preview' => $preview,
            'diskFiles' => $diskFiles,
            'iblocks' => $iblocks,
            'iblockId' => $iblockId,
            'parentSectionRef' => $parentSectionRef,
            'updateByCode' => $updateByCode,
            'matchByXmlId' => $matchByXmlId,
        ];
    }
}
