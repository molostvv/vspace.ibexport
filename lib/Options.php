<?php

namespace Vspace\Ibexport;

use Bitrix\Main\Config\Option;

class Options
{
    const MODULE_ID = 'vspace.ibexport';

    /** Количество записей за одну выборку из CIBlockElement/SectionTable (раздел 8: батчами, не всё сразу). */
    public static function getBatchSize(): int
    {
        return (int)(Option::get(self::MODULE_ID, 'BATCH_SIZE', 200)) ?: 200;
    }

    /** Выше этого расчётного числа узлов (разделы+элементы) экспорт уходит в фон (агент), а не выполняется целиком в запросе. */
    public static function getSyncThreshold(): int
    {
        return (int)(Option::get(self::MODULE_ID, 'SYNC_THRESHOLD', 300)) ?: 300;
    }

    /** Максимум секунд, которые может занять один тик фоновой обработки, прежде чем отдать управление (защита от max_execution_time). */
    public static function getTickBudgetSeconds(): int
    {
        return (int)(Option::get(self::MODULE_ID, 'TICK_BUDGET', 12)) ?: 12;
    }

    /** Через сколько часов временные файлы и архив завершённого задания удаляются автоматически. */
    public static function getTtlHours(): int
    {
        return (int)(Option::get(self::MODULE_ID, 'TTL_HOURS', 24)) ?: 24;
    }

    public static function getDefaultWithFiles(): bool
    {
        return Option::get(self::MODULE_ID, 'DEFAULT_WITH_FILES', 'Y') === 'Y';
    }

    public static function getDefaultActiveOnly(): bool
    {
        return Option::get(self::MODULE_ID, 'DEFAULT_ACTIVE_ONLY', 'N') === 'Y';
    }

    /** Начальное состояние чекбокса "Обновлять существующие по коду" в форме импорта. */
    public static function getDefaultUpdateByCode(): bool
    {
        return Option::get(self::MODULE_ID, 'DEFAULT_UPDATE_BY_CODE', 'Y') === 'Y';
    }

    public static function isYandexDiskEnabled(): bool
    {
        return Option::get(self::MODULE_ID, 'YANDEX_DISK_ENABLED', 'N') === 'Y';
    }

    public static function setYandexDiskEnabled(bool $enabled): void
    {
        Option::set(self::MODULE_ID, 'YANDEX_DISK_ENABLED', $enabled ? 'Y' : 'N');
    }

    /** Папка обмена на Диске — общая для теста и прода (см. docs/yandex-disk.md). */
    public static function getYandexDiskFolder(): string
    {
        $folder = (string)Option::get(self::MODULE_ID, 'YANDEX_DISK_FOLDER', '/vspace.ibexport');
        return $folder !== '' ? $folder : '/vspace.ibexport';
    }

    public static function setYandexDiskFolder(string $folder): void
    {
        Option::set(self::MODULE_ID, 'YANDEX_DISK_FOLDER', $folder !== '' ? $folder : '/vspace.ibexport');
    }

    public static function hasYandexDiskToken(): bool
    {
        return (string)Option::get(self::MODULE_ID, 'YANDEX_DISK_TOKEN', '') !== '';
    }

    /**
     * Токен хранится в БД в зашифрованном виде — тем же приёмом, что ядро
     * использует для паролей SMTP (\Bitrix\Main\Mail\Internal\SenderTable):
     * \Bitrix\Main\Security\Cipher с ключом crypto_key из bitrix/.settings.php.
     * Готового «сейфа для секретов» в API модулей Bitrix нет, поэтому это
     * самый близкий к штатному способ, а не собственное изобретение.
     */
    public static function getYandexDiskToken(): string
    {
        $encoded = (string)Option::get(self::MODULE_ID, 'YANDEX_DISK_TOKEN', '');
        if ($encoded === '') {
            return '';
        }
        try {
            $key = self::getCryptoKey();
            if ($key === '') {
                return '';
            }
            $cipher = new \Bitrix\Main\Security\Cipher();
            return $cipher->decrypt(base64_decode($encoded), $key);
        } catch (\Throwable $e) {
            return '';
        }
    }

    public static function setYandexDiskToken(string $token): void
    {
        if ($token === '') {
            Option::set(self::MODULE_ID, 'YANDEX_DISK_TOKEN', '');
            return;
        }
        $key = self::getCryptoKey();
        if ($key === '') {
            throw new \Exception('В bitrix/.settings.php не задан crypto[crypto_key] — сохранение токена невозможно.');
        }
        $cipher = new \Bitrix\Main\Security\Cipher();
        Option::set(self::MODULE_ID, 'YANDEX_DISK_TOKEN', base64_encode($cipher->encrypt($token, $key)));
    }

    public static function clearYandexDiskToken(): void
    {
        Option::set(self::MODULE_ID, 'YANDEX_DISK_TOKEN', '');
        Option::set(self::MODULE_ID, 'YANDEX_DISK_ENABLED', 'N');
    }

    private static function getCryptoKey(): string
    {
        return (string)(\Bitrix\Main\Config\Configuration::getValue('crypto')['crypto_key'] ?? '');
    }
}
