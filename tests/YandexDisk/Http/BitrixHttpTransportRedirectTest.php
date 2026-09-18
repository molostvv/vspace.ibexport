<?php

namespace Vspace\Ibexport\Tests\YandexDisk\Http;

use PHPUnit\Framework\TestCase;
use Vspace\Ibexport\YandexDisk\Http\BitrixHttpTransport;

/**
 * Юнит-тесты только для resolveRedirectUrl() — чистой функции достройки
 * Location до абсолютного URL. Сам BitrixHttpTransport не тестируется
 * напрямую (он реально дёргает \Bitrix\Main\Web\HttpClient и требует ядро
 * Bitrix), но эта логика — причина реального бага (аплоадер Яндекс.Диска
 * отвечает на PUT/GET protocol-relative/относительным Location, из-за
 * чего HttpClient::query() падал с "Only http and https schemes are
 * supported" при следовании за редиректом) — и она не зависит от ядра.
 */
final class BitrixHttpTransportRedirectTest extends TestCase
{
    public function testAbsoluteLocationIsReturnedAsIs(): void
    {
        self::assertSame(
            'https://storage.example/put-here',
            BitrixHttpTransport::resolveRedirectUrl('https://uploader.example/upload', 'https://storage.example/put-here')
        );
    }

    public function testProtocolRelativeLocationGetsSchemeFromBaseUrl(): void
    {
        self::assertSame(
            'https://storage2.disk.yandex.net/put-here?token=abc',
            BitrixHttpTransport::resolveRedirectUrl('https://uploader11sas.disk.yandex.net/upload-target/xyz', '//storage2.disk.yandex.net/put-here?token=abc')
        );
    }

    public function testAbsolutePathLocationKeepsBaseHostAndScheme(): void
    {
        self::assertSame(
            'https://uploader11sas.disk.yandex.net/put-here',
            BitrixHttpTransport::resolveRedirectUrl('https://uploader11sas.disk.yandex.net/upload-target/xyz', '/put-here')
        );
    }

    public function testRelativeLocationResolvesAgainstBaseDirectory(): void
    {
        self::assertSame(
            'https://uploader11sas.disk.yandex.net/upload-target/put-here',
            BitrixHttpTransport::resolveRedirectUrl('https://uploader11sas.disk.yandex.net/upload-target/xyz', 'put-here')
        );
    }

    public function testHttpSchemeIsPreservedNotForcedToHttps(): void
    {
        self::assertSame(
            'http:' . '//storage.example/put-here',
            BitrixHttpTransport::resolveRedirectUrl('http://uploader.example/upload', '//storage.example/put-here')
        );
    }
}
