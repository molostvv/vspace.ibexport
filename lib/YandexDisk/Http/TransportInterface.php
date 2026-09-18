<?php

namespace Vspace\Ibexport\YandexDisk\Http;

/**
 * Тонкая граница между Client и реальным HTTP-транспортом. Нужна для
 * того, чтобы логику клиента (сборка URL, разбор ответов API, обработка
 * ошибок, лимит размера файла) можно было покрыть юнит-тестами с
 * поддельным транспортом — без сетевых запросов и без поднятия ядра
 * Bitrix. Боевая реализация — BitrixHttpTransport поверх
 * \Bitrix\Main\Web\HttpClient.
 */
interface TransportInterface
{
    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:string}
     */
    public function request(string $method, string $url, array $headers, ?string $body = null): array;

    /**
     * Скачивает содержимое $url сразу в файл $destPath, не буферизуя всё
     * тело ответа в памяти. Возвращает HTTP-статус ответа (0 — сетевая
     * ошибка/запрос не выполнен).
     */
    public function downloadToFile(string $url, array $headers, string $destPath): int;
}
