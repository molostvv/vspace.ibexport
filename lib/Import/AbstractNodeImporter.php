<?php

namespace Vspace\Ibexport\Import;

use SimpleXMLElement;

/** Общее для импортёров разделов и элементов: чтение атрибутов узла, поиск существующей записи по CODE, файловые поля. */
abstract class AbstractNodeImporter
{
    public function __construct(protected FileArrayFactoryInterface $files)
    {
    }

    /** ?: тут не годится — SORT=0 валиден и не должен подменяться значением по умолчанию. */
    protected static function readSort(SimpleXMLElement $node): int
    {
        $raw = (string)$node['sort'];
        return $raw !== '' ? (int)$raw : 500;
    }

    /**
     * Ищет существующую запись по CODE в целевом инфоблоке (для сопоставления при повторном импорте).
     *
     * @param class-string<\Bitrix\Main\ORM\Data\DataManager> $ormClass ElementTable либо SectionTable
     */
    protected function findByCode(string $ormClass, int $iblockId, string $code): ?int
    {
        $row = $ormClass::getList([
            'filter' => ['IBLOCK_ID' => $iblockId, 'CODE' => $code],
            'select' => ['ID'],
            'limit' => 1,
        ])->fetch();

        return $row ? (int)$row['ID'] : null;
    }

    /** Прямые файловые поля (PICTURE/PREVIEW_PICTURE/DETAIL_PICTURE) — самозакрывающийся тег с атрибутом file_ref. */
    protected function applyFileField(array &$fields, string $fieldName, SimpleXMLElement $node, ImportContext $ctx, ImportReport $report): void
    {
        $fileRef = (string)$node['file_ref'];
        if ($fileRef === '') {
            return; // файлы не выгружались либо исходный файл отсутствовал — не трогаем поле
        }

        $absPath = $ctx->tmpDir . '/' . $fileRef;
        if (!is_file($absPath)) {
            $report->addWarning('Файл "' . $fileRef . '" не найден в архиве, поле ' . $fieldName . ' пропущено.');
            return;
        }

        $fileArr = $this->files->make($absPath);
        if ($fileArr) {
            $fields[$fieldName] = $fileArr;
        }
    }
}
