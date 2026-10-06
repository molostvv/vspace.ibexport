<?php

namespace Vspace\Ibexport\Tests\YandexDisk;

use PHPUnit\Framework\TestCase;
use Vspace\Ibexport\Tests\YandexDisk\Fake\FakeTransport;
use Vspace\Ibexport\YandexDisk\Client;
use Vspace\Ibexport\YandexDisk\Exception;

final class ClientTest extends TestCase
{
    private FakeTransport $transport;
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
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
        $this->expectException(Exception::class);
        new Client('', $this->transport);
    }

    public function testCheckConnectionParsesLoginAndFreeSpace(): void
    {
        $this->transport->queueResponse(200, json_encode([
            'user' => ['login' => 'ivanov'],
            'total_space' => 1000,
            'used_space' => 400,
        ]));

        $client = new Client('token', $this->transport);
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

        $client = new Client('token', $this->transport);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('недоступен');
        $client->checkConnection();
    }

    public function testCheckConnectionThrowsWithApiMessageOnError(): void
    {
        $this->transport->queueResponse(401, json_encode([
            'message' => 'Unauthorized',
            'description' => 'OAuth token is invalid',
        ]));

        $client = new Client('bad-token', $this->transport);

        try {
            $client->checkConnection();
            self::fail('Expected Exception was not thrown.');
        } catch (Exception $e) {
            self::assertSame(401, $e->getCode());
            self::assertStringContainsString('Unauthorized', $e->getMessage());
            self::assertStringContainsString('OAuth token is invalid', $e->getMessage());
        }
    }

    public function testCheckConnectionThrowsOnNonJsonBody(): void
    {
        $this->transport->queueResponse(200, 'not json at all');

        $client = new Client('token', $this->transport);

        $this->expectException(Exception::class);
        $client->checkConnection();
    }

    public function testEnsureFolderAcceptsCreatedAndAlreadyExists(): void
    {
        $client = new Client('token', $this->transport);

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

        $client = new Client('token', $this->transport);

        $this->expectException(Exception::class);
        $client->ensureFolder('/vspace.ibexport');
    }

    public function testUploadFileRejectsMissingLocalFile(): void
    {
        $client = new Client('token', $this->transport);

        $this->expectException(Exception::class);
        $client->uploadFile('/vspace.ibexport/x.zip', '/no/such/file.zip');

        self::assertCount(0, $this->transport->calls);
    }

    public function testUploadFileRejectsFileOverSizeLimit(): void
    {
        $bigFile = $this->makeTempFile(Client::MAX_FILE_SIZE + 1);
        $client = new Client('token', $this->transport);

        try {
            $client->uploadFile('/vspace.ibexport/x.zip', $bigFile);
            self::fail('Expected Exception was not thrown.');
        } catch (Exception $e) {
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

        $client = new Client('token', $this->transport);
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

        $client = new Client('token', $this->transport);

        $this->expectException(Exception::class);
        $client->uploadFile('/vspace.ibexport/x.zip', $localFile);
    }

    public function testUploadFileThrowsWhenPutFails(): void
    {
        $localFile = $this->makeTempFile(1);

        $this->transport->queueResponse(200, json_encode(['href' => 'https://uploader.example/put-here']));
        $this->transport->queueResponse(507, json_encode(['message' => 'Insufficient Storage']));

        $client = new Client('token', $this->transport);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Insufficient Storage');
        $client->uploadFile('/vspace.ibexport/x.zip', $localFile);
    }

    public function testUploadFileRetriesWithNewHrefAfterNetworkFailure(): void
    {
        $localFile = $this->makeTempFile(3);
        $pauses = [];

        $this->transport->queueResponse(200, json_encode(['href' => 'https://uploader1.example/put']));
        $this->transport->queueResponse(0, '[2] stream_socket_client(): Unable to connect (Network is unreachable)');
        $this->transport->queueResponse(200, json_encode(['href' => 'https://uploader2.example/put']));
        $this->transport->queueResponse(201, '');

        $client = new Client('token', $this->transport, 15, static function (int $seconds) use (&$pauses): void {
            $pauses[] = $seconds;
        });
        $client->uploadFile('/vspace.ibexport/x.zip', $localFile, true);

        self::assertCount(4, $this->transport->calls);
        self::assertSame('GET', $this->transport->calls[2]['method']);
        self::assertSame('https://uploader2.example/put', $this->transport->calls[3]['url']);
        self::assertSame([1], $pauses);
    }

    public function testUploadFileGivesUpAfterAllAttemptsFailOnNetwork(): void
    {
        $localFile = $this->makeTempFile(3);
        $pauses = [];
        for ($i = 0; $i < Client::TRANSFER_ATTEMPTS; $i++) {
            $this->transport->queueResponse(200, json_encode(['href' => 'https://uploader.example/put']));
            $this->transport->queueResponse(0, 'Network is unreachable');
        }

        $client = new Client('token', $this->transport, 15, static function (int $seconds) use (&$pauses): void {
            $pauses[] = $seconds;
        });

        try {
            $client->uploadFile('/vspace.ibexport/x.zip', $localFile, true);
            self::fail('Expected Exception was not thrown.');
        } catch (Exception $e) {
            self::assertStringContainsString('Network is unreachable', $e->getMessage());
            self::assertStringContainsString((string)Client::TRANSFER_ATTEMPTS, $e->getMessage());
        }
        self::assertCount(2 * Client::TRANSFER_ATTEMPTS, $this->transport->calls);
        self::assertSame(range(1, Client::TRANSFER_ATTEMPTS - 1), $pauses);
    }

    public function testUploadFileDoesNotRetryHttpErrors(): void
    {
        $localFile = $this->makeTempFile(1);

        $this->transport->queueResponse(200, json_encode(['href' => 'https://uploader.example/put-here']));
        $this->transport->queueResponse(500, '');

        $client = new Client('token', $this->transport, 15, static function (): void {
            self::fail('No pause expected: HTTP errors are not retried.');
        });

        $this->expectException(Exception::class);
        $client->uploadFile('/vspace.ibexport/x.zip', $localFile);
    }

    public function testDeleteFileMovesToTrash(): void
    {
        $this->transport->queueResponse(204, '');

        (new Client('token', $this->transport))->deleteFile('disk:/vspace.ibexport/job17.zip');

        self::assertSame('DELETE', $this->transport->calls[0]['method']);
        self::assertSame('disk:/vspace.ibexport/job17.zip', $this->queryParam($this->transport->calls[0]['url'], 'path'));
        self::assertSame('false', $this->queryParam($this->transport->calls[0]['url'], 'permanently'));
    }

    public function testDeleteFileAcceptsAsyncAndAlreadyDeleted(): void
    {
        $this->transport->queueResponse(202, '{"href":"https://cloud-api.yandex.net/v1/disk/operations/1"}');
        $this->transport->queueResponse(404, json_encode(['message' => 'Resource not found']));

        $client = new Client('token', $this->transport);
        $client->deleteFile('disk:/vspace.ibexport/a.zip');
        $client->deleteFile('disk:/vspace.ibexport/b.zip');

        self::assertCount(2, $this->transport->calls);
    }

    public function testDeleteFileRetriesAfterNetworkFailure(): void
    {
        $pauses = [];
        $this->transport->queueResponse(0, 'stream timeout'); // запрос выполнен, но ответ не дошёл
        $this->transport->queueResponse(404, json_encode(['message' => 'Resource not found']));

        $client = new Client('token', $this->transport, 15, static function (int $seconds) use (&$pauses): void {
            $pauses[] = $seconds;
        });
        $client->deleteFile('disk:/vspace.ibexport/a.zip');

        self::assertCount(2, $this->transport->calls);
        self::assertSame([1], $pauses);
    }

    public function testClearFolderDeletesEveryFileAndReportsFailures(): void
    {
        $listing = json_encode(['_embedded' => ['items' => [
            ['type' => 'file', 'name' => 'a.zip', 'path' => 'disk:/vspace.ibexport/a.zip'],
            ['type' => 'dir', 'name' => 'keep', 'path' => 'disk:/vspace.ibexport/keep'],
            ['type' => 'file', 'name' => 'b.zip', 'path' => 'disk:/vspace.ibexport/b.zip'],
        ]]]);
        $this->transport->queueResponse(200, $listing);
        $this->transport->queueResponse(204, '');
        $this->transport->queueResponse(423, json_encode(['message' => 'Resource is locked']));
        // повторный список: остался только b.zip, который уже пробовали удалить, — очистка на этом заканчивается
        $this->transport->queueResponse(200, json_encode(['_embedded' => ['items' => [
            ['type' => 'file', 'name' => 'b.zip', 'path' => 'disk:/vspace.ibexport/b.zip'],
        ]]]));

        $result = (new Client('token', $this->transport))->clearFolder('/vspace.ibexport');

        self::assertSame(1, $result['deleted']);
        self::assertSame(['b.zip' => 'Resource is locked'], $result['failed']);
        $methods = array_column($this->transport->calls, 'method');
        self::assertSame(['GET', 'DELETE', 'DELETE', 'GET'], $methods); // папка "keep" не удаляется
    }

    public function testClearFolderOfMissingFolderDoesNothing(): void
    {
        $this->transport->queueResponse(404, json_encode(['message' => 'Resource not found']));

        $result = (new Client('token', $this->transport))->clearFolder('/vspace.ibexport');

        self::assertSame(['deleted' => 0, 'failed' => []], $result);
    }

    public function testClearFolderRefusesDiskRoot(): void
    {
        foreach (['', '/', 'disk:/', 'disk:', ' / '] as $root) {
            try {
                (new Client('token', $this->transport))->clearFolder($root);
                self::fail('Root "' . $root . '" must be refused.');
            } catch (Exception $e) {
                self::assertCount(0, $this->transport->calls);
            }
        }
        self::assertFalse(Client::isRootPath('/vspace.ibexport'));
        self::assertFalse(Client::isRootPath('disk:/vspace.ibexport/'));
    }

    public function testListFilesFiltersOnlyFilesAndParsesFields(): void
    {
        $this->transport->queueResponse(200, json_encode([
            '_embedded' => ['items' => [
                ['type' => 'dir', 'name' => 'subfolder', 'path' => 'disk:/vspace.ibexport/subfolder'],
                ['type' => 'file', 'name' => 'job17.zip', 'path' => 'disk:/vspace.ibexport/job17.zip', 'size' => 12345, 'modified' => '2026-09-18T12:00:00+00:00', 'md5' => '72d528ca2185608b99800fdff7a711b8'],
            ]],
        ]));

        $client = new Client('token', $this->transport);
        $files = $client->listFiles('/vspace.ibexport');

        self::assertCount(1, $files);
        self::assertSame('job17.zip', $files[0]['name']);
        self::assertSame('disk:/vspace.ibexport/job17.zip', $files[0]['path']);
        self::assertSame(12345, $files[0]['size']);
        self::assertSame('2026-09-18T12:00:00+00:00', $files[0]['modified']);
        self::assertSame('72d528ca2185608b99800fdff7a711b8', $files[0]['md5']);
    }

    public function testListFilesReturnsEmptyOn404(): void
    {
        $this->transport->queueResponse(404, json_encode(['message' => 'Not Found']));

        $client = new Client('token', $this->transport);
        self::assertSame([], $client->listFiles('/vspace.ibexport'));
    }

    public function testListFilesThrowsOnOtherErrors(): void
    {
        $this->transport->queueResponse(503, json_encode(['message' => 'Service Unavailable']));

        $client = new Client('token', $this->transport);

        $this->expectException(Exception::class);
        $client->listFiles('/vspace.ibexport');
    }

    public function testDownloadFileHappyPath(): void
    {
        $destPath = sys_get_temp_dir() . '/yandex_disk_test_download_' . uniqid() . '.zip';
        $this->tempFiles[] = $destPath;

        $this->transport->queueResponse(200, json_encode(['href' => 'https://downloader.example/get-here']));
        $this->transport->queueDownloadStatus(200);
        $this->transport->downloadWrittenContent = 'zip-bytes';

        $client = new Client('token', $this->transport);
        $client->downloadFile('/vspace.ibexport/job17.zip', $destPath);

        self::assertFileExists($destPath);
        self::assertSame('zip-bytes', file_get_contents($destPath));
        self::assertSame('https://downloader.example/get-here', $this->transport->downloadCalls[0]['url']);
    }

    public function testDownloadFileThrowsWhenHrefRequestFails(): void
    {
        $this->transport->queueResponse(404, json_encode(['message' => 'Resource not found']));

        $client = new Client('token', $this->transport);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Resource not found');
        $client->downloadFile('/vspace.ibexport/missing.zip', sys_get_temp_dir() . '/unused.zip');
    }

    public function testDownloadFileRetriesWithNewHrefAfterNetworkFailure(): void
    {
        $destPath = sys_get_temp_dir() . '/yandex_disk_test_download_retry_' . uniqid() . '.zip';
        $this->tempFiles[] = $destPath;
        $pauses = [];

        $this->transport->queueResponse(200, json_encode(['href' => 'https://downloader1.example/get']));
        $this->transport->queueDownloadStatus(0);
        $this->transport->queueResponse(200, json_encode(['href' => 'https://downloader2.example/get']));
        $this->transport->queueDownloadStatus(200);
        $this->transport->downloadWrittenContent = 'zip-bytes';

        $client = new Client('token', $this->transport, 15, static function (int $seconds) use (&$pauses): void {
            $pauses[] = $seconds;
        });
        $client->downloadFile('/vspace.ibexport/job17.zip', $destPath);

        self::assertSame('zip-bytes', file_get_contents($destPath));
        self::assertSame('https://downloader2.example/get', $this->transport->downloadCalls[1]['url']);
        self::assertSame([1], $pauses);
    }

    public function testDownloadFileThrowsAndDoesNotLeaveFileWhenDownloadFails(): void
    {
        $destPath = sys_get_temp_dir() . '/yandex_disk_test_download_fail_' . uniqid() . '.zip';

        $this->transport->queueResponse(200, json_encode(['href' => 'https://downloader.example/get-here']));
        $this->transport->queueDownloadStatus(500);

        $client = new Client('token', $this->transport);

        try {
            $client->downloadFile('/vspace.ibexport/job17.zip', $destPath);
            self::fail('Expected Exception was not thrown.');
        } catch (Exception $e) {
            self::assertFileDoesNotExist($destPath);
        }
    }
}
