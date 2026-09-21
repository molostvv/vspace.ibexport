<?php

namespace Vspace\Ibexport\Tests\Import\Fake;

use Vspace\Ibexport\Import\ExistingRecordFinderInterface;

/** Целевой инфоблок в памяти: ключ "<kind>:<CODE|XML_ID>:<значение>" => ID. Запоминает, что у него спрашивали. */
final class FakeRecordFinder implements ExistingRecordFinderInterface
{
    /** @var string[] */
    public array $asked = [];

    /** @param array<string, int> $records */
    public function __construct(private array $records = [])
    {
    }

    public function findId(string $kind, int $iblockId, array $match): ?int
    {
        $key = $kind . ':' . array_key_first($match) . ':' . reset($match);
        $this->asked[] = $key;

        return $this->records[$key] ?? null;
    }
}
