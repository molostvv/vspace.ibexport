<?php

namespace Vspace\Ibexport\Tests\Export\Fake;

use Vspace\Ibexport\Export\ElementWriter;
use Vspace\Ibexport\XmlStreamWriter;

/** Пишет упрощённый узел элемента без обращения к ядру Bitrix. */
final class RecordingElementWriter extends ElementWriter
{
    /** @var int[] */
    public array $written = [];

    /** @var array<int, int|null> ID элемента => раздел, под которым его выгрузили (последний) */
    public array $sections = [];

    public function __construct()
    {
        // родительский конструктор требует FileRefWriter и колбэк предупреждений, которые тут не нужны
    }

    public function writeRow(XmlStreamWriter $w, int $elementId, bool $withSections = false, ?int $sectionId = null): void
    {
        $this->written[] = $elementId;
        $this->sections[$elementId] = $sectionId;
        $w->openTag('element', ['id' => $elementId], true);
    }
}
