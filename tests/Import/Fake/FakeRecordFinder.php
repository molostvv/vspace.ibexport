<?php

namespace Vspace\Ibexport\Tests\Import\Fake;

use Vspace\Ibexport\Import\ExistingRecordFinderInterface;

/**
 * Целевой инфоблок в памяти: ключ "<kind>:<CODE|XML_ID>:<значение>" => запись [ID, название] либо список таких записей
 * (несколько — неоднозначное совпадение). Запоминает, что у него спрашивали.
 */
final class FakeRecordFinder implements ExistingRecordFinderInterface
{
    /** @var string[] */
    public array $asked = [];

    /** @param array<string, array{0: int, 1: string}|list<array{0: int, 1: string}>> $records */
    public function __construct(private array $records = [])
    {
    }

    public function find(string $kind, int $iblockId, array $match): array
    {
        $key = $kind . ':' . array_key_first($match) . ':' . reset($match);
        $this->asked[] = $key;

        $records = $this->records[$key] ?? [];
        if ($records && is_int($records[0])) {
            $records = [$records]; // одна запись вместо списка
        }

        return array_map(static fn(array $r): array => ['id' => $r[0], 'name' => $r[1]], array_slice($records, 0, 2));
    }
}
