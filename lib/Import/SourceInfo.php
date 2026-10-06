<?php

namespace Vspace\Ibexport\Import;

/**
 * MD5 и размер принятого архива — в файле source.json рабочего каталога импорта: сам ZIP после распаковки удаляется,
 * а по MD5 история импортов (ImportedFileTable) узнаёт архив в списке файлов Яндекс.Диска. Из архива этот файл
 * прийти не может: распаковываются только export.xml и files/<имя> (ArchiveFileRef).
 */
final class SourceInfo
{
    public const FILE = 'source.json';

    /** Записывает сведения об архиве $zipPath в каталог $tmpDir (до удаления самого архива). */
    public static function write(string $tmpDir, string $zipPath): void
    {
        $md5 = md5_file($zipPath);
        $size = filesize($zipPath);
        if ($md5 !== false && $size !== false) {
            file_put_contents($tmpDir . '/' . self::FILE, json_encode(['md5' => $md5, 'size' => $size]));
        }
    }

    /** @return array{md5: string, size: int}|null null — архив принят версией модуля, которая этот файл не писала */
    public static function read(string $tmpDir): ?array
    {
        $path = $tmpDir . '/' . self::FILE;
        $data = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
        if (!is_array($data) || !is_string($data['md5'] ?? null) || !preg_match('/^[0-9a-f]{32}$/', $data['md5'])) {
            return null;
        }

        return ['md5' => $data['md5'], 'size' => (int)($data['size'] ?? 0)];
    }
}
