<?php

namespace Vspace\Ibexport\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Каждый Loc::getMessage('КЛЮЧ') в коде модуля есть в языковом файле этого же файла — lang/<язык>/<путь от корня
 * модуля> (правило Loc::loadMessages(__FILE__)) — и на русском, и на английском. Без ядра Bitrix: чистый разбор файлов.
 */
final class LangFilesTest extends TestCase
{
    private const LANGUAGES = ['ru', 'en'];

    /** Файлы, сообщения которых берутся через Loc::loadMessages(__FILE__). */
    private static function sourceFiles(): array
    {
        $root = dirname(__DIR__);
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/lib', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        foreach (['admin/task_description.php', 'admin/operation_description.php', 'install/index.php', 'install/unstep1.php'] as $relative) {
            $files[] = $root . '/' . $relative;
        }

        return $files;
    }

    private static function relative(string $path): string
    {
        return ltrim(str_replace('\\', '/', substr($path, strlen(dirname(__DIR__)))), '/');
    }

    /** @return array<string, string> */
    private static function messages(string $langFile): array
    {
        if (!is_file($langFile)) {
            return [];
        }
        $MESS = [];
        include $langFile;

        return $MESS;
    }

    public function testEveryMessageUsedInCodeExistsInBothLanguages(): void
    {
        $checked = 0;
        foreach (self::sourceFiles() as $source) {
            preg_match_all("~Loc::getMessage\\(\\s*'([A-Z0-9_]+)'~", file_get_contents($source), $m);
            $keys = array_unique($m[1]);
            if (!$keys) {
                continue;
            }
            $this->assertStringContainsString('Loc::loadMessages(__FILE__)', file_get_contents($source), self::relative($source) . ' uses Loc without loadMessages');

            foreach (self::LANGUAGES as $language) {
                $langFile = dirname(__DIR__) . '/lang/' . $language . '/' . self::relative($source);
                $messages = self::messages($langFile);
                foreach ($keys as $key) {
                    $this->assertArrayHasKey($key, $messages, sprintf('%s: key %s missing in lang/%s', self::relative($source), $key, $language));
                    $this->assertNotSame('', trim($messages[$key]), sprintf('%s: key %s is empty in lang/%s', self::relative($source), $key, $language));
                    $checked++;
                }
            }
        }

        $this->assertGreaterThan(50, $checked, 'the scan must actually find messages');
    }

    public function testRussianAndEnglishFilesDefineTheSameKeys(): void
    {
        $root = dirname(__DIR__);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/lang/ru', \FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen(str_replace('\\', '/', $root . '/lang/ru/')));
            $ru = array_keys(self::messages($file->getPathname()));
            $en = array_keys(self::messages($root . '/lang/en/' . $relative));
            sort($ru);
            sort($en);
            $this->assertSame($ru, $en, 'lang/ru and lang/en differ: ' . $relative);
        }
    }
}
