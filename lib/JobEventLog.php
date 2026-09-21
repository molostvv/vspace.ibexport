<?php

namespace Vspace\Ibexport;

/**
 * Запись событий заданий в штатный журнал Bitrix (\CEventLog). Префикс
 * AUDIT_TYPE_ID различает экспорт (VSPACE_IBEXPORT_) и импорт (VSPACE_IBIMPORT_).
 */
final class JobEventLog
{
    public function __construct(private string $auditPrefix)
    {
    }

    public function error(int $jobId, string $message): void
    {
        $this->log('ERROR', $jobId, $message);
    }

    public function done(int $jobId): void
    {
        $this->log('DONE', $jobId, '');
    }

    /** $type — ERROR либо DONE (суффикс AUDIT_TYPE_ID). */
    private function log(string $type, int $jobId, string $message): void
    {
        \CEventLog::Add([
            'SEVERITY' => $type === 'ERROR' ? 'ERROR' : 'INFO',
            'AUDIT_TYPE_ID' => $this->auditPrefix . $type,
            'MODULE_ID' => 'vspace.ibexport',
            'ITEM_ID' => $jobId,
            'DESCRIPTION' => $message,
        ]);
    }
}
