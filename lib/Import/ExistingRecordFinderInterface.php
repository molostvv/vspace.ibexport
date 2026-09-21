<?php

namespace Vspace\Ibexport\Import;

/** Поиск записи целевого инфоблока по ключу сопоставления (граница с Bitrix для ImportPreview; образец — PropertySourceInterface). */
interface ExistingRecordFinderInterface
{
    public const KIND_SECTION = 'section';
    public const KIND_ELEMENT = 'element';

    /**
     * Не больше двух записей (по возрастанию ID): двух достаточно, чтобы отличить однозначное совпадение от неоднозначного.
     *
     * @param self::KIND_* $kind
     * @param array{CODE: string}|array{XML_ID: string} $match результат AbstractNodeImporter::matchFilter()
     * @return list<array{id: int, name: string}>
     */
    public function find(string $kind, int $iblockId, array $match): array;
}
