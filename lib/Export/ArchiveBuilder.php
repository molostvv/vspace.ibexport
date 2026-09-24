<?php

namespace Vspace\Ibexport\Export;

use Bitrix\Main\Localization\Loc;
use CIBlockElement;
use CIBlockSection;

Loc::loadMessages(__FILE__);

/** Собирает итоговый ZIP экспорта (export.xml + files/) и подбирает ему человекочитаемое имя. */
final class ArchiveBuilder
{
    /**
     * @param array $job Строка JobTable (нужны ID, IBLOCK_ID, ENTITY_TYPE, ENTITY_ID, MODE)
     * @return array{name: string, size: int}
     */
    public function build(array $job, string $tmpDir): array
    {
        $archiveName = $this->buildArchiveName($job);
        $archivePath = $tmpDir . '/' . $archiveName;

        $zip = new \ZipArchive();
        if ($zip->open($archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \Exception(Loc::getMessage('IBX_ARCHIVE_CREATE_FAILED'));
        }

        $zip->addFile($tmpDir . '/export.xml', 'export.xml');

        $filesDir = $tmpDir . '/files';
        if (is_dir($filesDir)) {
            foreach (scandir($filesDir) as $f) {
                if ($f === '.' || $f === '..') {
                    continue;
                }
                $zip->addFile($filesDir . '/' . $f, 'files/' . $f);
            }
        }
        $zip->close();

        return ['name' => $archiveName, 'size' => filesize($archivePath) ?: 0];
    }

    /**
     * Человекочитаемое имя архива вместо голого "export_<ID>.zip": инфоблок,
     * тип и код/название сущности, режим для разделов — чтобы по имени файла
     * в списке скачанных архивов было понятно, что внутри, без открытия XML.
     * ID задания остаётся в конце как гарантия уникальности имени.
     */
    private function buildArchiveName(array $job): string
    {
        $iblock = \CIBlock::GetArrayByID((int)$job['IBLOCK_ID']) ?: [];
        $iblockSlug = $this->slug($iblock['CODE'] ?? '', 'iblock' . $job['IBLOCK_ID']);

        $entityType = $job['ENTITY_TYPE'];
        $entityId = (int)$job['ENTITY_ID'];
        $entitySlug = $this->slug($this->resolveEntityName($entityType, $entityId), (string)$entityId);

        $parts = [$iblockSlug, $entityType, $entitySlug, $entityId];

        if ($job['MODE'] === 'section_tree') {
            $parts[] = 'tree';
        }

        $parts[] = 'job' . $job['ID'];

        return implode('_', $parts) . '.zip';
    }

    private function resolveEntityName(string $entityType, int $entityId): string
    {
        if ($entityType === 'element') {
            $el = CIBlockElement::GetList([], ['ID' => $entityId, 'CHECK_PERMISSIONS' => 'N'])->Fetch();
            return $el ? (string)($el['CODE'] ?: $el['NAME']) : '';
        }

        $section = CIBlockSection::GetList([], ['ID' => $entityId, 'CHECK_PERMISSIONS' => 'N'])->Fetch();
        return $section ? (string)($section['CODE'] ?: $section['NAME']) : '';
    }

    private function slug(string $value, string $fallback): string
    {
        $value = trim($value);
        if ($value === '') {
            return $fallback;
        }

        $lang = defined('LANGUAGE_ID') ? LANGUAGE_ID : 'ru';
        $slug = \CUtil::translit($value, $lang, [
            'max_len' => 40,
            'change_case' => 'L',
            'replace_space' => '-',
            'replace_other' => '-',
        ]);

        return $slug !== '' ? $slug : $fallback;
    }
}
