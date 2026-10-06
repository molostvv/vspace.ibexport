<?php

namespace Vspace\Ibexport\Tests\Import;

use PHPUnit\Framework\TestCase;
use Vspace\Ibexport\Import\SourceInfo;

final class SourceInfoTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/vibx_source_info_' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testWrittenInfoReadsBack(): void
    {
        $zip = $this->dir . '/upload.zip';
        file_put_contents($zip, 'zip-bytes');

        SourceInfo::write($this->dir, $zip);
        unlink($zip); // как в Importer::extractAndValidate(): архив удаляется, сведения остаются

        $this->assertSame(['md5' => md5('zip-bytes'), 'size' => 9], SourceInfo::read($this->dir));
    }

    public function testNoFileGivesNull(): void
    {
        $this->assertNull(SourceInfo::read($this->dir));
    }

    public function testMalformedMd5GivesNull(): void
    {
        file_put_contents($this->dir . '/' . SourceInfo::FILE, json_encode(['md5' => '../../etc', 'size' => 1]));

        $this->assertNull(SourceInfo::read($this->dir));
    }
}
