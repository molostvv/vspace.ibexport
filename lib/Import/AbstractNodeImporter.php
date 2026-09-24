<?php

namespace Vspace\Ibexport\Import;

use Bitrix\Main\Localization\Loc;
use SimpleXMLElement;

Loc::loadMessages(__FILE__);

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
        return isset($match['CODE'])
            ? Loc::getMessage('IBX_NODE_MATCH_CODE', ['#VALUE#' => $match['CODE']])
            : Loc::getMessage('IBX_NODE_MATCH_XML_ID', ['#VALUE#' => $match['XML_ID']]);
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
        return $this->findMatch($ormClass, $iblockId, $match)['id'];
    }

    /**
     * То же с признаком неоднозначности: в инфоблоке несколько записей с таким XML_ID (XML_ID не уникален в Bitrix,
     * и брать "первую попавшуюся" значило бы обновить чужую запись). Для CODE поведение прежнее — берётся одна запись.
     *
     * @param class-string<\Bitrix\Main\ORM\Data\DataManager> $ormClass ElementTable либо SectionTable
     * @param array{CODE: string}|array{XML_ID: string}|null $match результат matchFilter()
     * @return array{id: int|null, ambiguous: bool} id — запись с наименьшим ID
     */
    protected function findMatch(string $ormClass, int $iblockId, ?array $match): array
    {
        if ($match === null) {
            return ['id' => null, 'ambiguous' => false];
        }

        $rows = $ormClass::getList([
            'filter' => ['IBLOCK_ID' => $iblockId] + $match,
            'select' => ['ID'],
            'order' => ['ID' => 'ASC'],
            'limit' => isset($match['XML_ID']) ? 2 : 1,
        ])->fetchAll();

        return ['id' => $rows ? (int)$rows[0]['ID'] : null, 'ambiguous' => count($rows) > 1];
    }

    /**
     * Прямые файловые поля (PICTURE/PREVIEW_PICTURE/DETAIL_PICTURE) — самозакрывающийся тег с атрибутом file_ref.
     * Ссылка принимается только на files/… внутри архива (ArchiveFileRef) — иначе поддельный export.xml
     * мог бы указать на произвольный файл сервера.
     */
    protected function applyFileField(array &$fields, string $fieldName, SimpleXMLElement $node, ImportContext $ctx, ImportReport $report): void
    {
        $fileRef = (string)$node['file_ref'];
        if ($fileRef === '') {
            return; // файлы не выгружались либо исходный файл отсутствовал — не трогаем поле
        }
        if (!ArchiveFileRef::isValid($fileRef)) {
            $report->addWarning(Loc::getMessage('IBX_NODE_BAD_FILE_REF', ['#REF#' => $fileRef, '#FIELD#' => $fieldName]));
            return;
        }

        $absPath = ArchiveFileRef::path($ctx->tmpDir, $fileRef);
        if (!is_file($absPath)) {
            $report->addWarning(Loc::getMessage('IBX_NODE_NO_FILE', ['#REF#' => $fileRef, '#FIELD#' => $fieldName]));
            return;
        }

        $fileArr = $this->files->make($absPath);
        if ($fileArr) {
            $description = (string)$node['description'];
            if ($description !== '') {
                $fileArr['description'] = $description;
            }
            $fields[$fieldName] = $fileArr;
        }
    }
}
