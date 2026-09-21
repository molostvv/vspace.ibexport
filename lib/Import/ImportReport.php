<?php

namespace Vspace\Ibexport\Import;

/**
 * Накопитель итогов импорта в пределах тика: счётчики созданных/обновлённых/
 * пропущенных записей и предупреждения. Счётчики переживают тики через
 * STATE_JSON (toCounts()/withCounts()), предупреждения сбрасываются в
 * WARNINGS_JSON задания в конце каждого тика.
 */
final class ImportReport
{
    public int $created = 0;
    public int $updated = 0;
    public int $skipped = 0;

    /** @var string[] */
    private array $warnings = [];

    /** Восстанавливает счётчики из STATE_JSON (ключи created/updated/skipped). */
    public static function withCounts(array $counts): self
    {
        $report = new self();
        $report->created = (int)($counts['created'] ?? 0);
        $report->updated = (int)($counts['updated'] ?? 0);
        $report->skipped = (int)($counts['skipped'] ?? 0);

        return $report;
    }

    /** @return array{created: int, updated: int, skipped: int} */
    public function toCounts(): array
    {
        return ['created' => $this->created, 'updated' => $this->updated, 'skipped' => $this->skipped];
    }

    public function addWarning(string $message): void
    {
        $this->warnings[] = $message;
    }

    /** @return string[] */
    public function getWarnings(): array
    {
        return $this->warnings;
    }
}
