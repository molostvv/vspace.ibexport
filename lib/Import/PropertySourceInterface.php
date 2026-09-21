<?php

namespace Vspace\Ibexport\Import;

/**
 * Тонкая граница между разбором свойств элемента (PropertyResolver) и
 * определениями свойств в целевом инфоблоке — по образцу
 * YandexDisk\Http\TransportInterface: логику резолва можно покрыть
 * юнит-тестами с поддельным источником, без ядра Bitrix. Боевая
 * реализация — BitrixPropertySource поверх CIBlockProperty/CIBlockPropertyEnum.
 */
interface PropertySourceInterface
{
    /** @return array<string, array{ID:int, MULTIPLE:string}> код свойства => метаданные, для данного инфоблока */
    public function getDefinitions(int $iblockId): array;

    /** @return string[] коды пользовательских полей (UF_*) разделов данного инфоблока */
    public function getSectionUserFieldCodes(int $iblockId): array;

    /** @return array<string, int> отображаемое значение варианта списка => ID варианта, для данного свойства */
    public function getEnumMap(int $propertyId): array;
}
