<?php

namespace Vspace\Ibexport\YandexDisk;

use Vspace\Ibexport\YandexDisk\Http\BitrixHttpTransport;
use Vspace\Ibexport\YandexDisk\Http\TransportInterface;

/**
 * Обёртка над REST API Яндекс.Диска (cloud-api.yandex.net) — канал переноса
 * файла между двумя независимыми инсталляциями (тест → Диск → прод), см.
 * docs/yandex-disk.md. Все операции синхронные, по явному нажатию кнопки
 * администратором — без очередей/фоновых воркеров (ТЗ, раздел 6).
 *
 * Вся сетевая логика идёт через Http\TransportInterface — это единственный
 * способ покрыть её юнит-тестами (сборка URL, разбор ответов API, коды
 * ошибок, лимит размера файла) без реальных запросов к Яндексу и без
 * поднятия ядра Bitrix, см. tests/YandexDisk/ClientTest.php.
 */
class Client
{
    private const API_BASE = 'https://cloud-api.yandex.net/v1/disk';

    /** Лимит размера файла на бесплатном тарифе Яндекс.Диска (ТЗ, раздел 6/7). */
    public const MAX_FILE_SIZE = 1073741824; // 1 ГБ

    private string $token;
    private TransportInterface $transport;

    public function __construct(string $token, ?TransportInterface $transport = null, int $timeout = 15)
    {
        if ($token === '') {
            throw new Exception('Не задан токен Яндекс.Диска.');
        }
        $this->token = $token;
        $this->transport = $transport ?? new BitrixHttpTransport($timeout);
    }

    private function buildUrl(string $path, array $query = []): string
    {
        $url = self::API_BASE . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }
        return $url;
    }

    private function authHeaders(): array
    {
        return [
            'Authorization' => 'OAuth ' . $this->token,
            'Accept' => 'application/json',
        ];
    }

    private function decodeJson(string $body): array
    {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new Exception('Некорректный (не JSON) ответ Яндекс.Диска.');
        }
        return $data;
    }

    private function throwOnTransportFailure(array $res): void
    {
        if ($res['status'] === 0) {
            throw new Exception('Яндекс.Диск недоступен: ' . $res['body']);
        }
    }

    /**
     * Диагностика для запросов по одноразовой ссылке (href), которую
     * возвращает сам API (upload/download) — в отличие от запросов к
     * cloud-api.yandex.net, эту ссылку мы не строим сами, поэтому в случае
     * "Only http and https schemes are supported" важно видеть, что реально
     * пришло от Яндекса (схема/хост/длина), а не гадать. Полный href не
     * логируем — он содержит одноразовый токен доступа.
     */
    private function hrefPreview(string $url): string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        return 'схема=' . ($scheme ?: '(нет)') . ', хост=' . ($host ?: '(нет)') . ', длина=' . strlen($url);
    }

    private function throwApiError(int $status, string $body): void
    {
        $message = 'HTTP ' . $status;
        $description = '';
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $message = (string)($decoded['message'] ?? $message);
            $description = (string)($decoded['description'] ?? '');
        }
        throw new Exception(trim($message . ($description !== '' ? ': ' . $description : '')), $status);
    }

    /** GET /v1/disk — валидность токена, логин владельца, свободное место. */
    public function checkConnection(): array
    {
        $res = $this->transport->request('GET', $this->buildUrl(''), $this->authHeaders());
        $this->throwOnTransportFailure($res);
        if ($res['status'] !== 200) {
            $this->throwApiError($res['status'], $res['body']);
        }

        $data = $this->decodeJson($res['body']);
        $total = (int)($data['total_space'] ?? 0);
        $used = (int)($data['used_space'] ?? 0);

        return [
            'user_login' => (string)($data['user']['login'] ?? ''),
            'total_space' => $total,
            'used_space' => $used,
            'free_space' => max(0, $total - $used),
        ];
    }

    /** PUT /v1/disk/resources — идемпотентное создание папки обмена (409 «уже существует» — не ошибка). */
    public function ensureFolder(string $path): void
    {
        $res = $this->transport->request('PUT', $this->buildUrl('/resources', ['path' => $path]), $this->authHeaders());
        $this->throwOnTransportFailure($res);
        if (!in_array($res['status'], [201, 409], true)) {
            $this->throwApiError($res['status'], $res['body']);
        }
    }

    /** Двухшаговая загрузка (тест → Диск): получить upload-href, затем PUT содержимого файла по этому href. */
    public function uploadFile(string $diskPath, string $localFilePath, bool $overwrite = false): void
    {
        if (!is_file($localFilePath)) {
            throw new Exception('Локальный файл для выгрузки не найден: ' . $localFilePath);
        }

        $size = filesize($localFilePath);
        if ($size === false || $size > self::MAX_FILE_SIZE) {
            throw new Exception('Файл превышает лимит 1 ГБ для бесплатного тарифа Яндекс.Диска.');
        }

        $res = $this->transport->request('GET', $this->buildUrl('/resources/upload', [
            'path' => $diskPath,
            'overwrite' => $overwrite ? 'true' : 'false',
        ]), $this->authHeaders());
        $this->throwOnTransportFailure($res);
        if ($res['status'] !== 200) {
            $this->throwApiError($res['status'], $res['body']);
        }

        $href = (string)($this->decodeJson($res['body'])['href'] ?? '');
        if ($href === '') {
            throw new Exception('Яндекс.Диск не вернул ссылку для загрузки.');
        }

        $content = file_get_contents($localFilePath);
        if ($content === false) {
            throw new Exception('Не удалось прочитать файл для выгрузки: ' . $localFilePath);
        }

        // href уже содержит собственный временный токен доступа — заголовок
        // авторизации Диска здесь не нужен (и не требуется API).
        $uploadRes = $this->transport->request('PUT', $href, [], $content);
        if ($uploadRes['status'] === 0) {
            throw new Exception('Не удалось обратиться по ссылке для загрузки (' . $this->hrefPreview($href) . '): ' . $uploadRes['body']);
        }
        if (!in_array($uploadRes['status'], [201, 202], true)) {
            $this->throwApiError($uploadRes['status'], $uploadRes['body']);
        }
    }

    /**
     * GET /v1/disk/resources — список файлов в папке обмена (для отображения на проде).
     * @return array<int, array{name:string,path:string,size:int,modified:string}>
     */
    public function listFiles(string $folderPath): array
    {
        $res = $this->transport->request('GET', $this->buildUrl('/resources', [
            'path' => $folderPath,
            'limit' => 200,
            'sort' => '-modified',
        ]), $this->authHeaders());
        $this->throwOnTransportFailure($res);

        if ($res['status'] === 404) {
            // Папка обмена ещё не создана (первой выгрузки с теста ещё не было) — пустой список, не ошибка.
            return [];
        }
        if ($res['status'] !== 200) {
            $this->throwApiError($res['status'], $res['body']);
        }

        $data = $this->decodeJson($res['body']);
        $items = $data['_embedded']['items'] ?? [];
        $files = [];
        foreach ($items as $item) {
            if (($item['type'] ?? '') !== 'file') {
                continue;
            }
            $files[] = [
                'name' => (string)($item['name'] ?? ''),
                'path' => (string)($item['path'] ?? ''),
                'size' => (int)($item['size'] ?? 0),
                'modified' => (string)($item['modified'] ?? ''),
            ];
        }
        return $files;
    }

    /** Скачивание (Диск → прод): получить download-href, затем скачать содержимое по этому href в файл. */
    public function downloadFile(string $diskPath, string $localFilePath): void
    {
        $res = $this->transport->request('GET', $this->buildUrl('/resources/download', ['path' => $diskPath]), $this->authHeaders());
        $this->throwOnTransportFailure($res);
        if ($res['status'] !== 200) {
            $this->throwApiError($res['status'], $res['body']);
        }

        $href = (string)($this->decodeJson($res['body'])['href'] ?? '');
        if ($href === '') {
            throw new Exception('Яндекс.Диск не вернул ссылку для скачивания.');
        }

        $status = $this->transport->downloadToFile($href, [], $localFilePath);
        if ($status === 0) {
            throw new Exception('Не удалось скачать файл по ссылке от Яндекс.Диска (' . $this->hrefPreview($href) . ').');
        }
        if ($status < 200 || $status >= 300) {
            @unlink($localFilePath);
            throw new Exception('Ошибка при скачивании файла с Яндекс.Диска (HTTP ' . $status . ').');
        }
    }
}
