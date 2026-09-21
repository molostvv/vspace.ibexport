<?php

namespace Vspace\Ibexport\Tests\Export\Fake;

use Vspace\Ibexport\Export\SectionWriter;
use Vspace\Ibexport\XmlStreamWriter;

/** Пишет упрощённые узлы раздела без обращения к ядру Bitrix; закрывающий </section> пишет обход, как и с настоящим писателем. */
final class RecordingSectionWriter extends SectionWriter
{
    /** @var int[] */
    public array $opened = [];

    /** @var array<int, array{int, bool}> */
    public array $stubCalls = [];

    public function __construct()
    {
        // родительский конструктор требует FileRefWriter, который тут не нужен
    }

    public function writeOpen(XmlStreamWriter $w, int $sectionId): void
    {
        $this->opened[] = $sectionId;
        $w->openTag('section', ['id' => $sectionId]);
        $w->textTag('name', 'S' . $sectionId);
    }

    public function writeSubsectionStubs(XmlStreamWriter $w, int $iblockId, int $sectionId, bool $activeOnly): void
    {
        $this->stubCalls[] = [$sectionId, $activeOnly];
        $w->openTag('subsections', ['note' => 'not_included_see_mode']);
        $w->closeTag('subsections');
    }
}
