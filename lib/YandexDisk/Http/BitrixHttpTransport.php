<?php

namespace Vspace\Ibexport\YandexDisk\Http;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\HttpClient;

Loc::loadMessages(__FILE__);

/**
 * Реализация TransportInterface на штатном HTTP-клиенте ядра
 * (\Bitrix\Main\Web\HttpClient) — готового коннектора для Яндекс.Диска в
 * Bitrix нет (CCloudStorage поддерживает только S3-совместимые/Google/
 * Azure/OpenStack), поэтому сам протокол — не штатный механизм, но
 * транспорт для него — штатный.
 */
class BitrixHttpTransport implements TransportInterface
{
    private const MAX_REDIRECTS = 5;

    private int $timeout;

    public function __construct(int $timeout = 15)
    {
        $this->timeout = $timeout;
    }

    private function newClient(array $headers): HttpClient
    {
        $http = new HttpClient();
        $http->setTimeout($this->timeout);
        // Скачивание/выгрузка самого файла может занять дольше, чем
        // короткие вызовы метаданных API — таймаут потока увеличен отдельно.
        $http->setStreamTimeout(max($this->timeout, 60));
        // Редиректы обрабатываем сами (см. resolveRedirectUrl()) — аплоадер
        // Яндекс.Диска на PUT/GET по одноразовой ссылке отвечает
        // редиректом с protocol-relative/относительным Location (без
        // "https://"), а собственный разбор Location в HttpClient::query()
        // такие адреса не резолвит и падает с "Only http and https
        // schemes are supported" на самом переходе по редиректу.
        $http->setRedirect(false);
        foreach ($headers as $name => $value) {
            $http->setHeader($name, $value);
        }
        return $http;
    }

    /**
     * Достраивает Location до абсолютного URL, если он protocol-relative
     * ("//host/path") или относительный ("/path" или "path") — по тем же
     * правилам, что и обычный браузер при обработке HTTP-редиректа.
     */
    public static function resolveRedirectUrl(string $baseUrl, string $location): string
    {
        if (preg_match('~^https?://~i', $location)) {
            return $location;
        }

        $base = parse_url($baseUrl);
        $scheme = $base['scheme'] ?? 'https';

        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }

        $host = $base['host'] ?? '';
        if (str_starts_with($location, '/')) {
            return $scheme . '://' . $host . $location;
        }

        $basePath = $base['path'] ?? '/';
        $dir = substr($basePath, 0, strrpos($basePath, '/') + 1);
        return $scheme . '://' . $host . $dir . $location;
    }

    public function request(string $method, string $url, array $headers, ?string $body = null): array
    {
        return $this->requestFollowingRedirects($method, $url, $headers, $body, 0);
    }

    private function requestFollowingRedirects(string $method, string $url, array $headers, ?string $body, int $depth): array
    {
        if ($depth > self::MAX_REDIRECTS) {
            return ['status' => 0, 'body' => Loc::getMessage('IBX_YADISK_HTTP_TOO_MANY_REDIRECTS')];
        }

        $http = $this->newClient($headers);
        $ok = $http->query($method, $url, $body);
        if (!$ok) {
            return ['status' => 0, 'body' => implode('; ', $http->getError())];
        }

        $status = (int)$http->getStatus();
        if ($status >= 300 && $status < 400) {
            $location = $http->getHeaders()->get('Location');
            if ($location) {
                return $this->requestFollowingRedirects($method, self::resolveRedirectUrl($url, $location), $headers, $body, $depth + 1);
            }
        }

        return ['status' => $status, 'body' => (string)$http->getResult()];
    }

    public function downloadToFile(string $url, array $headers, string $destPath): int
    {
        return $this->downloadFollowingRedirects($url, $headers, $destPath, 0);
    }

    private function downloadFollowingRedirects(string $url, array $headers, string $destPath, int $depth): int
    {
        if ($depth > self::MAX_REDIRECTS) {
            return 0;
        }

        $http = $this->newClient($headers);
        $ok = $http->query('GET', $url);
        if (!$ok) {
            return 0;
        }

        $status = (int)$http->getStatus();
        if ($status >= 300 && $status < 400) {
            $location = $http->getHeaders()->get('Location');
            if ($location) {
                return $this->downloadFollowingRedirects(self::resolveRedirectUrl($url, $location), $headers, $destPath, $depth + 1);
            }
        }

        if ($status >= 200 && $status < 300) {
            $http->saveFile($destPath);
        }
        return $status;
    }
}
