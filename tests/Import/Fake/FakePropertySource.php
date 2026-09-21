<?php

namespace Vspace\Ibexport\Tests\Import\Fake;

use Vspace\Ibexport\Import\PropertySourceInterface;

/** Определения свойств и варианты списков, заданные в тесте, вместо CIBlockProperty/CIBlockPropertyEnum. */
final class FakePropertySource implements PropertySourceInterface
{
    /** @var int[] Инфоблоки, для которых запрашивали определения (проверка: определения читаются один раз на элемент) */
    public array $definitionRequests = [];

    /** @var int[] Свойства, для которых запрашивали варианты списка */
    public array $enumRequests = [];

    /**
     * @param array<int, array<string, array{ID:int, MULTIPLE:string}>> $definitions ID инфоблока => (код => метаданные)
     * @param array<int, array<string, int>> $enums ID свойства => (отображаемое значение => ID варианта)
     * @param array<int, string[]> $sectionUserFields ID инфоблока => коды UF-полей разделов
     */
    public function __construct(private array $definitions, private array $enums = [], private array $sectionUserFields = [])
    {
    }

    /** @return string[] */
    public function getSectionUserFieldCodes(int $iblockId): array
    {
        return $this->sectionUserFields[$iblockId] ?? [];
    }

    public function getDefinitions(int $iblockId): array
    {
        $this->definitionRequests[] = $iblockId;
        return $this->definitions[$iblockId] ?? [];
    }

    public function getEnumMap(int $propertyId): array
    {
        $this->enumRequests[] = $propertyId;
        return $this->enums[$propertyId] ?? [];
    }
}
