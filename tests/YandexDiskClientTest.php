<?php

namespace Vspace\Ibexport\Tests;

use PHPUnit\Framework\TestCase;
use Vspace\Ibexport\Tests\Fake\FakeYandexDiskTransport;
use Vspace\Ibexport\YandexDiskClient;
use Vspace\Ibexport\YandexDiskException;

final class YandexDiskClientTest extends TestCase
{
    private FakeYandexDiskTransport $transport;
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->transport = new FakeYandexDiskTransport();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->tempFiles = [];
    }

    private function makeTempFile(int $size): string
    {
        $path = tempnam(sys_get_temp_dir(), 'yandex_disk_test_');
        $handle = fopen($path, 'w');
        ftruncate($handle, $size);
        fclose($handle);
        $this->tempFiles[] = $path;
        return $path;
    }

    private function queryParam(string $url, string $name): ?string
    {
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);
        return $query[$name] ?? null;
    }

    public function testConstructorRejectsEmptyToken(): void
    {
        $this->expectException(YandexDiskException::class);
        new YandexDiskClient('', $this->transport);
    }

    public function testCheckConnectionParsesLoginAndFreeSpace(): void
    {
        $this->transport->queueResponse(200, json_encode([
            'user' => ['login' => 'ivanov'],
            'total_space' => 1000,
            'used_space' => 400,
        ]));

        $client = new YandexDiskClient('token', $this->transport);
        $result = $client->checkConnection();

        self::assertSame('ivanov', $result['user_login']);
        self::assertSame(1000, $result['total_space']);
        self::assertSame(400, $result['used_space']);
        self::assertSame(600, $result['free_space']);

        self::assertCount(1, $this->transport->calls);
        self::assertSame('GET', $this->transport->calls[0]['method']);
        self::assertSame('OAuth token', $this->transport->calls[0]['headers']['Authorization']);
    }

    public function testCheckConnectionThrowsOnNetworkFailure(): void
    {
        $this->transport->queueResponse(0, 'connection refused');

        $client = new YandexDiskClient('token', $this->transport);

        $this->expectException(YandexDiskException::class);
        $this->expectExceptionMessage('недоступен');
        $client->checkConnection();
    }

    public function testCheckConnectionThrowsWithApiMessageOnError(): void
    {
        $this->transport->queueResponse(401, json_encode([
            'message' => 'Unauthorized',
            'description' => 'OAuth token is invalid',
        ]));

        $client = new YandexDiskClient('bad-token', $this->transport);

        try {
            $client->checkConnection();
            self::fail('Expected YandexDiskException was not thrown.');
        } catch (YandexDiskException $e) {
            self::assertSame(401, $e->getCode());
            self::assertStringContainsString('Unauthorized', $e->getMessage());
            self::assertStringContainsString('OAuth token is invalid', $e->getMessage());
        }
    }

    public function testCheckConnectionThrowsOnNonJsonBody(): void
    {
        $this->transport->queueResponse(200, 'not json at all');

        $client = new YandexDiskClient('token', $this->transport);

        $this->expectException(YandexDiskException::class);
        $client->checkConnection();
    }

    public function testEnsureFolderAcceptsCreatedAndAlreadyExists(): void
    {
        $client = new YandexDiskClient('token', $this->transport);

        $this->transport->queueResponse(201, '');
        $client->ensureFolder('/vspace.ibexport');

        $this->transport->queueResponse(409, json_encode(['message' => 'already exists']));
        $client->ensureFolder('/vspace.ibexport'); // не должно бросить исключение

        self::assertSame('PUT', $this->transport->calls[0]['method']);
        self::assertSame('/vspace.ibexport', $this->queryParam($this->transport->calls[0]['url'], 'path'));
    }

    public function testEnsureFolderThrowsOnUnexpectedStatus(): void
    {
        $this->transport->queueResponse(500, json_encode(['message' => 'Internal error']));

        $client = new YandexDiskClient('token', $this->transport);

        $this->expectException(YandexDiskException::class);
        $client->ensureFolder('/vspace.ibexport');
    }

    public function testUploadFileRejectsMissingLocalFile(): void
    {
        $client = new YandexDiskClient('token', $this->transport);

        $this->expectException(YandexDiskException::class);
        $client->uploadFile('/vspace.ibexport/x.zip', '/no/such/file.zip');

        self::assertCount(0, $this->transport->calls);
    }

    public function testUploadFileRejectsFileOverSizeLimit(): void
    {
        $bigFile = $this->makeTempFile(YandexDiskClient::MAX_FILE_SIZE + 1);
        $client = new YandexDiskClient('token', $this->transport);

        try {
            $client->uploadFile('/vspace.ibexport/x.zip', $bigFile);
            self::fail('Expected YandexDiskException was not thrown.');
        } catch (YandexDiskException $e) {
            self::assertStringContainsString('1 ГБ', $e->getMessage());
        }

        // Лимит проверяется до любого обращения к API.
        self::assertCount(0, $this->transport->calls);
    }

    public function testUploadFileHappyPath(): void
    {
        $localFile = $this->makeTempFile(10);
        file_put_contents($localFile, str_repeat('a', 10));

        $this->transport->queueResponse(200, json_encode(['href' => 'https://uploader.example/put-here']));
        $this->transport->queueResponse(201, '');

        $client = new YandexDiskClient('token', $this->transport);
        $client->uploadFile('/vspace.ibexport/job17.zip', $localFile, true);

        self::assertCount(2, $this->transport->calls);
        self::assertSame('GET', $this->transport->calls[0]['method']);
        self::assertSame('/vspace.ibexport/job17.zip', $this->queryParam($this->transport->calls[0]['url'], 'path'));
        self::assertSame('true', $this->queryParam($this->transport->calls[0]['url'], 'overwrite'));

        self::assertSame('PUT', $this->transport->calls[1]['method']);
        self::assertSame('https://uploader.example/put-here', $this->transport->calls[1]['url']);
        self::assertSame(str_repeat('a', 10), $this->transport->calls[1]['body']);
    }

    public function testUploadFileThrowsWhenUploadHrefMissing(): void
    {
        $localFile = $this->makeTempFile(1);

        $this->transport->queueResponse(200, json_encode(['href' => '']));

        $client = new YandexDiskClient('token', $this->transport);

        $this->expectException(YandexDiskException::class);
        $client->uploadFile('/vspace.ibexport/x.zip', $localFile);
    }

    public function testUploadFileThrowsWhenPutFails(): void
    {
        $localFile = $this->makeTempFile(1);

        $this->transport->queueResponse(200, json_encode(['href' => 'https://uploader.example/put-here']));
        $this->transport->queueResponse(507, json_encode(['message' => 'Insufficient Storage']));

        $client = new YandexDiskClient('token', $this->transport);

        $this->expectException(YandexDiskException::class);
        $this->expectExceptionMessage('Insufficient Storage');
        $client->uploadFile('/vspace.ibexport/x.zip', $localFile);
    }

    public function testListFilesFiltersOnlyFilesAndParsesFields(): void
    {
        $this->transport->queueResponse(200, json_encode([
            '_embedded' => ['items' => [
                ['type' => 'dir', 'name' => 'subfolder', 'path' => 'disk:/vspace.ibexport/subfolder'],
                ['type' => 'file', 'name' => 'job17.zip', 'path' => 'disk:/vspace.ibexport/job17.zip', 'size' => 12345, 'modified' => '2026-09-18T12:00:00+00:00'],
            ]],
        ]));

        $client = new YandexDiskClient('token', $this->transport);
        $files = $client->listFiles('/vspace.ibexport');

        self::assertCount(1, $files);
        self::assertSame('job17.zip', $files[0]['name']);
        self::assertSame('disk:/vspace.ibexport/job17.zip', $files[0]['path']);
        self::assertSame(12345, $files[0]['size']);
        self::assertSame('2026-09-18T12:00:00+00:00', $files[0]['modified']);
    }

    public function testListFilesReturnsEmptyOn404(): void
    {
        $this->transport->queueResponse(404, json_encode(['message' => 'Not Found']));

        $client = new YandexDiskClient('token', $this->transport);
        self::assertSame([], $client->listFiles('/vspace.ibexport'));
    }

    public function testListFilesThrowsOnOtherErrors(): void
    {
        $this->transport->queueResponse(503, json_encode(['message' => 'Service Unavailable']));

        $client = new YandexDiskClient('token', $this->transport);

        $this->expectException(YandexDiskException::class);
        $client->listFiles('/vspace.ibexport');
    }

    public function testDownloadFileHappyPath(): void
    {
        $destPath = sys_get_temp_dir() . '/yandex_disk_test_download_' . uniqid() . '.zip';
        $this->tempFiles[] = $destPath;

        $this->transport->queueResponse(200, json_encode(['href' => 'https://downloader.example/get-here']));
        $this->transport->queueDownloadStatus(200);
        $this->transport->downloadWrittenContent = 'zip-bytes';

        $client = new YandexDiskClient('token', $this->transport);
        $client->downloadFile('/vspace.ibexport/job17.zip', $destPath);

        self::assertFileExists($destPath);
        self::assertSame('zip-bytes', file_get_contents($destPath));
        self::assertSame('https://downloader.example/get-here', $this->transport->downloadCalls[0]['url']);
    }

    public function testDownloadFileThrowsWhenHrefRequestFails(): void
    {
        $this->transport->queueResponse(404, json_encode(['message' => 'Resource not found']));

        $client = new YandexDiskClient('token', $this->transport);

        $this->expectException(YandexDiskException::class);
        $this->expectExceptionMessage('Resource not found');
        $client->downloadFile('/vspace.ibexport/missing.zip', sys_get_temp_dir() . '/unused.zip');
    }

    public function testDownloadFileThrowsAndDoesNotLeaveFileWhenDownloadFails(): void
    {
        $destPath = sys_get_temp_dir() . '/yandex_disk_test_download_fail_' . uniqid() . '.zip';

        $this->transport->queueResponse(200, json_encode(['href' => 'https://downloader.example/get-here']));
        $this->transport->queueDownloadStatus(500);

        $client = new YandexDiskClient('token', $this->transport);

        try {
            $client->downloadFile('/vspace.ibexport/job17.zip', $destPath);
            self::fail('Expected YandexDiskException was not thrown.');
        } catch (YandexDiskException $e) {
            self::assertFileDoesNotExist($destPath);
        }
    }
}
