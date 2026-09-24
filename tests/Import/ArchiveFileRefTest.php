<?php

namespace Vspace\Ibexport\Tests\Import;

use PHPUnit\Framework\TestCase;
use Vspace\Ibexport\Import\ArchiveFileRef;

/** Какие ссылки на файлы архива и какие записи ZIP импорт принимает. */
final class ArchiveFileRefTest extends TestCase
{
    public function testFilesWrittenByTheExportAreAccepted(): void
    {
        foreach (['files/301_cover.jpg', 'files/12_price-list_2026.PDF', 'files/5_a..b.png', 'files/7_'] as $ref) {
            $this->assertTrue(ArchiveFileRef::isValid($ref), $ref);
        }
    }

    public function testAnythingOutsideTheFilesDirectoryIsRejected(): void
    {
        $refs = [
            '../secret.txt', 'files/../secret.txt', '/etc/passwd', 'C:/windows/win.ini', 'files\\..\\x',
            'files/sub/a.jpg', 'files/', 'files/.htaccess', 'files/..', 'export.xml', 'files/a.jpg ', "files/a.jpg\n", 'files/кот.jpg',
        ];
        foreach ($refs as $ref) {
            $this->assertFalse(ArchiveFileRef::isValid($ref), var_export($ref, true));
        }
    }

    public function testOnlyTheXmlAndArchiveFilesAreExtracted(): void
    {
        $this->assertTrue(ArchiveFileRef::isExtractable('export.xml'));
        $this->assertTrue(ArchiveFileRef::isExtractable('files/1_a.jpg'));
        $this->assertFalse(ArchiveFileRef::isExtractable('shell.php'));
        $this->assertFalse(ArchiveFileRef::isExtractable('files/'));
        $this->assertFalse(ArchiveFileRef::isExtractable('nested/export.xml'));
        $this->assertFalse(ArchiveFileRef::isExtractable('../export.xml'));
    }

    public function testPathJoinsTheDirectoryAndTheReference(): void
    {
        $this->assertSame('/tmp/import_x/files/1_a.jpg', ArchiveFileRef::path('/tmp/import_x/', 'files/1_a.jpg'));
    }
}
