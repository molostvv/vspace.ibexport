<?php

namespace Vspace\Ibexport\Import;

/** Поиск записи целевого инфоблока по ключу сопоставления (граница с Bitrix для ImportPreview; образец — PropertySourceInterface). */
interface ExistingRecordFinderInterface
{
    public const KIND_SECTION = 'section';
    public const KIND_ELEMENT = 'element';

    /**
     * @param self::KIND_* $kind
     * @param array{CODE: string}|array{XML_ID: string} $match результат AbstractNodeImporter::matchFilter()
     * @return int|null ID найденной записи либо null
     */
    public function findId(string $kind, int $iblockId, array $match): ?int;
}
