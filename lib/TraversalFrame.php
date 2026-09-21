<?php

namespace Vspace\Ibexport;

/**
 * Кадр стека возобновляемого DFS-обхода дерева разделов — один раздел в
 * обработке. Стек кадров между тиками хранится в STATE_JSON задания
 * (['stack' => [...]]), поэтому у кадра есть toArray()/fromArray(): формат
 * массива — тот самый, что всегда лежал в STATE_JSON (внутренний технический
 * формат, не документирован в docs/xml-format.md), так что незавершённые до
 * рефакторинга задания читаются как есть.
 *
 * Здесь — общее для экспорта и импорта: открыт ли раздел, текущая фаза
 * (элементы -> подразделы -> завершён) и индекс текущего подраздела.
 * Остальное различается (экспорт идёт по ID раздела и страницам SQL-выборки,
 * импорт — по пути индексов в XML), поэтому конкретные поля — в
 * Export\ExportFrame и Import\ImportFrame.
 */
abstract class TraversalFrame
{
    public const PHASE_ELEMENTS = 'elements';
    public const PHASE_CHILDREN = 'children';
    public const PHASE_DONE = 'done';

    /** Раздел уже открыт (записан/создан) — при возобновлении повторно не открывать. */
    public bool $opened = false;

    /** Фаза обработки раздела: self::PHASE_*. */
    public string $phase = self::PHASE_ELEMENTS;

    /** Сколько подразделов уже взято в обход. */
    public int $childrenIndex = 0;

    abstract public function toArray(): array;

    abstract public static function fromArray(array $data): static;

    /**
     * @param array[] $rows Стек в виде массивов (как в STATE_JSON)
     * @return static[]
     */
    public static function stackFromArray(array $rows): array
    {
        return array_map(static fn(array $row): static => static::fromArray($row), array_values($rows));
    }

    /**
     * @param TraversalFrame[] $stack
     * @return array[] Стек в виде массивов — для json_encode() в STATE_JSON
     */
    public static function stackToArray(array $stack): array
    {
        return array_map(static fn(TraversalFrame $frame): array => $frame->toArray(), array_values($stack));
    }
}
