<?php

namespace Vspace\Ibexport\YandexDisk;

use Bitrix\Main\Localization\Loc;
use Vspace\Ibexport\Importer;
use Vspace\Ibexport\TmpStorage;

Loc::loadMessages(__FILE__);

/**
 * Источник импорта "с Яндекс.Диска" — тот же результат, что и обычная
 * ручная загрузка .zip (Importer::prepareUpload()), но файл берётся не из
 * $_FILES, а из папки обмена на Диске (см. docs/yandex-disk.md, раздел 5
 * ТЗ "Экспорт в Яндекс.Диск": на проде это отдельная ручная кнопка
 * «Импортировать» после «Проверить Диск», не встроенная в обычный
 * процесс — недоступность Диска не мешает обычной ручной загрузке файла,
 * см. admin/import.php).
 */
class ImportSource
{
    /**
     * То же самое, что и "Проверить архив" при ручной загрузке, но источник
     * файла — папка обмена на Яндекс.Диске.
     */
    public static function prepareFromDisk(string $diskPath): array
    {
        $token = Settings::getToken();
        if ($token === '') {
            throw new \Exception(Loc::getMessage('IBX_YADISK_NOT_CONFIGURED'));
        }

        $tmpDirName = TmpStorage::create(TmpStorage::PREFIX_IMPORT);
        try {
            $tmpDir = TmpStorage::getPath($tmpDirName);
            $zipPath = $tmpDir . '/disk.zip';
            // Exception (YandexDisk\Exception) пробрасывается как есть —
            // вызывающая сторона (admin/import.php) сама решает, как
            // отформатировать сообщение, единообразно с ошибками listDiskFiles().
            $client = new Client($token);
            $client->downloadFile($diskPath, $zipPath);

            return Importer::extractAndValidate($zipPath, $tmpDir, $tmpDirName, basename($diskPath));
        } catch (\Throwable $e) {
            TmpStorage::delete($tmpDirName);
            throw $e;
        }
    }

    /** Список файлов в папке обмена на Яндекс.Диске — для кнопки «Проверить Диск» на странице импорта. */
    public static function listDiskFiles(): array
    {
        $token = Settings::getToken();
        if ($token === '') {
            throw new \Exception(Loc::getMessage('IBX_YADISK_NOT_CONFIGURED'));
        }
        $client = new Client($token);
        return $client->listFiles(Settings::getFolder());
    }
}
