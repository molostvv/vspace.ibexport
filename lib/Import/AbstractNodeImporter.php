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
     * Ключ сопоставления записи из архива с записью целевого инфоблока: CODE, а если он пуст и
     * пользователь включил "сопоставлять по XML_ID" — XML_ID. Иначе сопоставлять не с чем (null).
     *
     * @return array{CODE: string}|array{XML_ID: string}|null
     */
    public static function matchFilter(string $code, string $xmlId, bool $matchByXmlId): ?array
    {
        if ($code !== '') {
            return ['CODE' => $code];
        }

        return ($matchByXmlId && $xmlId !== '') ? ['XML_ID' => $xmlId] : null;
    }

    /** Подпись ключа сопоставления для текста предупреждений: "код news" / "XML_ID 1715". */
    protected static function matchLabel(array $match): string
    {
        return isset($match['CODE']) ? 'код ' . $match['CODE'] : 'XML_ID ' . $match['XML_ID'];
    }

    /**
     * Ищет существующую запись по CODE в целевом инфоблоке (для сопоставления при повторном импорте).
     *
     * @param class-string<\Bitrix\Main\ORM\Data\DataManager> $ormClass ElementTable либо SectionTable
     */
    protected function findByCode(string $ormClass, int $iblockId, string $code): ?int
    {
        return $this->findByMatch($ormClass, $iblockId, ['CODE' => $code]);
    }

    /**
     * @param class-string<\Bitrix\Main\ORM\Data\DataManager> $ormClass ElementTable либо SectionTable
     * @param array{CODE: string}|array{XML_ID: string}|null $match результат matchFilter()
     */
    protected function findByMatch(string $ormClass, int $iblockId, ?array $match): ?int
    {
        if ($match === null) {
            return null;
        }

        $row = $ormClass::getList([
            'filter' => ['IBLOCK_ID' => $iblockId] + $match,
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
