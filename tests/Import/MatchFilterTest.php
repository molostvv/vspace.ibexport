<?php

namespace Vspace\Ibexport\Tests\Import;

use PHPUnit\Framework\TestCase;
use Vspace\Ibexport\Import\AbstractNodeImporter;

final class MatchFilterTest extends TestCase
{
    public function testCodeWinsWhenPresent(): void
    {
        $this->assertSame(['CODE' => 'news'], AbstractNodeImporter::matchFilter('news', '45', true));
        $this->assertSame(['CODE' => 'news'], AbstractNodeImporter::matchFilter('news', '45', false));
    }

    public function testXmlIdIsUsedOnlyWhenCodeIsEmptyAndFlagIsOn(): void
    {
        $this->assertSame(['XML_ID' => '1715'], AbstractNodeImporter::matchFilter('', '1715', true));
    }

    public function testFlagOffKeepsOldBehaviourNothingToMatchWithoutCode(): void
    {
        $this->assertNull(AbstractNodeImporter::matchFilter('', '1715', false));
    }

    public function testNothingToMatchWithoutCodeAndXmlId(): void
    {
        $this->assertNull(AbstractNodeImporter::matchFilter('', '', true), 'архив без xml_id (старый формат) — сопоставлять не с чем');
    }
}
