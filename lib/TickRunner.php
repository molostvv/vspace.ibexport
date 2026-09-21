<?php

namespace Vspace\Ibexport;

use Bitrix\Main\Type\DateTime;

/**
 * Общий движок фонового задания (раздел 8 ТЗ модуля): один ограниченный по
 * времени тик работы, защита от параллельных тиков, самопланирующийся агент
 * CAgent и единый вид прогресса. Одинаков для экспорта и импорта — вид
 * задания задают таблица заданий, класс-владелец агента, журнал событий и
 * два колбэка: сам шаг обработки ($tick) и добавочные поля прогресса
 * ($progressExtras).
 *
 * Не хранит состояния между вызовами — всё в записи задания (AbstractJobTable),
 * поэтому фасады Exporter/Importer могут создавать экземпляр на каждый вызов.
 */
final class TickRunner
{
    /**
     * @param class-string<AbstractJobTable> $tableClass Таблица заданий этого вида
     * @param class-string $agentClass Класс со статическим agentTick(int $jobId): string — имя попадает в запись агента CAgent
     * @param JobEventLog $eventLog Журнал событий этого вида задания (сюда же пишет завершение шаг обработки)
     * @param \Closure(array, float): void $tick Один тик работы над заданием: получает строку задания и крайний срок (microtime); бросает исключение при ошибке
     * @param \Closure(array): array|null $progressExtras Поля прогресса, специфичные для вида задания
     */
    public function __construct(
        private string $tableClass,
        private string $agentClass,
        private JobEventLog $eventLog,
        private string $notFoundMessage,
        private \Closure $tick,
        private ?\Closure $progressExtras = null
    ) {
    }

    /**
     * Регистрирует самопланирующийся агент для задания — для больших
     * объёмов, которые не укладываются в один запрос (см. agentTick()).
     */
    public function scheduleAgent(int $jobId): void
    {
        \CAgent::AddAgent(
            $this->agentCall($jobId),
            'vspace.ibexport',
            'N',
            5,
            '',
            'Y',
            DateTime::createFromTimestamp(time())->toString()
        );
    }

    /** Колбэк CAgent для одного задания. Возвращает себя же для перепланирования, либо '' при завершении. */
    public function agentTick(int $jobId): string
    {
        $table = $this->tableClass;
        $call = $this->agentCall($jobId);

        $job = $table::getJobById($jobId);
        if (!$job || $table::isFinished($job)) {
            \CAgent::RemoveAgent($call, 'vspace.ibexport');
            return '';
        }

        $this->runStep($jobId);

        $job = $table::getJobById($jobId);
        if (!$job || $table::isFinished($job)) {
            \CAgent::RemoveAgent($call, 'vspace.ibexport');
            return '';
        }

        return $call;
    }

    /**
     * Выполняет один ограниченный по времени тик работы над заданием
     * (батчами, с бюджетом времени, без разрастания памяти). Безопасно
     * вызывать повторно и "параллельно" — из AJAX-опроса и/или фонового
     * агента одновременно (см. блокировку AbstractJobTable::tryLock()).
     */
    public function runStep(int $jobId): array
    {
        $table = $this->tableClass;

        $job = $table::getJobById($jobId);
        if (!$job) {
            throw new \Exception($this->notFoundMessage);
        }

        if ($table::isFinished($job)) {
            return $this->toProgress($job);
        }

        // Другой тик (агент или второй опрос из браузера) уже работает с
        // этим заданием — просто отдаём текущий прогресс, файл не трогаем.
        if (!$table::tryLock($jobId, Options::getTickBudgetSeconds() * 4)) {
            return $this->toProgress($job);
        }

        try {
            $table::update($jobId, ['STATUS' => $table::STATUS_RUNNING]);
            $job['STATUS'] = $table::STATUS_RUNNING;

            $deadline = microtime(true) + Options::getTickBudgetSeconds();

            ($this->tick)($job, $deadline);

            $job = $table::getJobById($jobId);
        } catch (\Throwable $e) {
            $table::update($jobId, [
                'STATUS' => $table::STATUS_ERROR,
                'ERROR_MESSAGE' => $e->getMessage(),
                'DATE_FINISH' => new DateTime(),
            ]);
            $this->eventLog->error($jobId, $e->getMessage());
            $job = $table::getJobById($jobId);
        } finally {
            $table::unlock($jobId);
        }

        return $this->toProgress($job);
    }

    /** Общая часть прогресса + поля, специфичные для вида задания ($progressExtras). */
    public function toProgress(array $job): array
    {
        $table = $this->tableClass;

        $progress = [
            'id' => (int)$job['ID'],
            'status' => $job['STATUS'],
            'stage' => $job['STAGE'],
            'progress' => $table::calculateProgress($job),
            'processed_sections' => (int)$job['PROCESSED_SECTIONS'],
            'processed_elements' => (int)$job['PROCESSED_ELEMENTS'],
            'total_sections' => (int)$job['TOTAL_SECTIONS'],
            'total_elements' => (int)$job['TOTAL_ELEMENTS'],
            'error_message' => $job['ERROR_MESSAGE'],
        ];

        return $this->progressExtras ? $progress + ($this->progressExtras)($job) : $progress;
    }

    /**
     * Имя агента в том же виде, в каком его хранит таблица агентов CAgent:
     * уже запланированные до рефакторинга агенты ссылаются именно на
     * Exporter::agentTick()/Importer::agentTick(), поэтому вид строки менять нельзя.
     */
    private function agentCall(int $jobId): string
    {
        return '\\' . $this->agentClass . '::agentTick(' . $jobId . ');';
    }
}
