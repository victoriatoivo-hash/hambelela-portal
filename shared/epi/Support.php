<?php

declare(strict_types=1);

namespace Hambelela\EPI;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

final class Support
{
    public const MODULES = [
        'Attendance', 'Orders', 'Packing', 'Packing List', 'Tasks', 'Courier',
        'Bookkeeping', 'Inventory', 'Error Log', 'Notifications', 'Portal Activity',
    ];

    public static function requireModule(string $module): string
    {
        $module = trim($module);
        if (!in_array($module, self::MODULES, true)) {
            throw new InvalidArgumentException('Unsupported EPI module: ' . $module);
        }
        return $module;
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    public static function timestamp($value = null, string $timezone = 'Africa/Windhoek'): DateTimeImmutable
    {
        $zone = new DateTimeZone($timezone);
        if ($value instanceof DateTimeInterface) {
            return (new DateTimeImmutable($value->format('Y-m-d H:i:s'), $value->getTimezone()))->setTimezone($zone);
        }
        return new DateTimeImmutable($value === null || $value === '' ? 'now' : (string) $value, $zone);
    }

    public static function json($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            return $value;
        }
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json === false ? null : $json;
    }

    public static function dedupe(array $parts): string
    {
        return hash('sha256', implode('|', array_map(static function ($value): string {
            if (is_array($value) || is_object($value)) {
                return (string) self::json($value);
            }
            return trim((string) $value);
        }, $parts)));
    }
}
