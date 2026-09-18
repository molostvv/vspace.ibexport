<?php

namespace Vspace\Ibexport\Tests\Fake;

use Vspace\Ibexport\Http\YandexDiskTransportInterface;

/**
 * Транспорт-подделка для тестов YandexDiskClient: ответы задаются заранее
 * (очередью), все вызовы записываются для последующих ассертов — реальных
 * HTTP-запросов и ядра Bitrix не требуется.
 */
class FakeYandexDiskTransport implements YandexDiskTransportInterface
{
    /** @var array<int, array{status:int, body:string}> */
    private array $responses = [];

    /** @var array<int, int> */
    private array $downloadStatuses = [];

    /** @var array<int, array{method:string,url:string,headers:array,body:?string}> */
    public array $calls = [];

    /** @var array<int, array{url:string,headers:array,destPath:string}> */
    public array $downloadCalls = [];

    public string $downloadWrittenContent = 'fake-content';

    public function queueResponse(int $status, string $body): void
    {
        $this->responses[] = ['status' => $status, 'body' => $body];
    }

    public function queueDownloadStatus(int $status): void
    {
        $this->downloadStatuses[] = $status;
    }

    public function request(string $method, string $url, array $headers, ?string $body = null): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        if (!$this->responses) {
            throw new \RuntimeException('FakeYandexDiskTransport: no queued response for ' . $method . ' ' . $url);
        }
        return array_shift($this->responses);
    }

    public function downloadToFile(string $url, array $headers, string $destPath): int
    {
        $this->downloadCalls[] = ['url' => $url, 'headers' => $headers, 'destPath' => $destPath];
        if (!$this->downloadStatuses) {
            throw new \RuntimeException('FakeYandexDiskTransport: no queued download status for ' . $url);
        }
        $status = array_shift($this->downloadStatuses);
        if ($status >= 200 && $status < 300) {
            file_put_contents($destPath, $this->downloadWrittenContent);
        }
        return $status;
    }
}
