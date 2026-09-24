<?php

namespace Vspace\Ibexport\YandexDisk;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;
use Vspace\Ibexport\Options;

Loc::loadMessages(__FILE__);

/**
 * Хранение токена и папки обмена для интеграции с Яндекс.Диском. Флаг
 * "включена ли интеграция" (Options::isYandexDiskEnabled()/
 * setYandexDiskEnabled()) остаётся в общих настройках модуля — это просто
 * переключатель наравне с прочими опциями, а не специфика самого Диска.
 */
class Settings
{
    /** Папка обмена на Диске — общая для теста и прода (см. docs/yandex-disk.md). */
    public static function getFolder(): string
    {
        $folder = (string)Option::get(Options::MODULE_ID, 'YANDEX_DISK_FOLDER', '/vspace.ibexport');
        return $folder !== '' ? $folder : '/vspace.ibexport';
    }

    public static function setFolder(string $folder): void
    {
        Option::set(Options::MODULE_ID, 'YANDEX_DISK_FOLDER', $folder !== '' ? $folder : '/vspace.ibexport');
    }

    public static function hasToken(): bool
    {
        return (string)Option::get(Options::MODULE_ID, 'YANDEX_DISK_TOKEN', '') !== '';
    }

    /**
     * Токен хранится в БД в зашифрованном виде — тем же приёмом, что ядро
     * использует для паролей SMTP (\Bitrix\Main\Mail\Internal\SenderTable):
     * \Bitrix\Main\Security\Cipher с ключом crypto_key из bitrix/.settings.php.
     * Готового «сейфа для секретов» в API модулей Bitrix нет, поэтому это
     * самый близкий к штатному способ, а не собственное изобретение.
     */
    public static function getToken(): string
    {
        $encoded = (string)Option::get(Options::MODULE_ID, 'YANDEX_DISK_TOKEN', '');
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

    public static function setToken(string $token): void
    {
        if ($token === '') {
            Option::set(Options::MODULE_ID, 'YANDEX_DISK_TOKEN', '');
            return;
        }
        $key = self::getCryptoKey();
        if ($key === '') {
            throw new \Exception(Loc::getMessage('IBX_YADISK_SETTINGS_NO_CRYPTO_KEY'));
        }
        $cipher = new \Bitrix\Main\Security\Cipher();
        Option::set(Options::MODULE_ID, 'YANDEX_DISK_TOKEN', base64_encode($cipher->encrypt($token, $key)));
    }

    public static function clearToken(): void
    {
        Option::set(Options::MODULE_ID, 'YANDEX_DISK_TOKEN', '');
        Option::set(Options::MODULE_ID, 'YANDEX_DISK_ENABLED', 'N');
    }

    private static function getCryptoKey(): string
    {
        return (string)(\Bitrix\Main\Config\Configuration::getValue('crypto')['crypto_key'] ?? '');
    }
}
