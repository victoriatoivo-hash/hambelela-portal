<?php

declare(strict_types=1);

function hr_portal_gate_config(): array
{
    $localConfig = dirname(__DIR__) . '/config.local.php';
    if (is_file($localConfig)) {
        $config = require $localConfig;
        if (is_array($config)) {
            return $config;
        }
    }

    return [];
}

function hr_portal_pin_hash(): string
{
    $config = hr_portal_gate_config();

    return trim((string) (getenv('HAMBELELA_HR_PORTAL_PIN_HASH') ?: ($config['hr_portal_pin_hash'] ?? '')));
}

function hr_portal_plain_pin(): string
{
    $config = hr_portal_gate_config();

    return trim((string) (getenv('HAMBELELA_HR_PORTAL_PIN') ?: ($config['hr_portal_pin'] ?? '')));
}

function hr_portal_pin_is_configured(): bool
{
    return hr_portal_pin_hash() !== '' || hr_portal_plain_pin() !== '';
}

function hr_portal_pin_matches(string $pin): bool
{
    $hash = hr_portal_pin_hash();
    if ($hash === '') {
        $plainPin = hr_portal_plain_pin();
        return $plainPin !== '' && hash_equals($plainPin, $pin);
    }

    return password_verify($pin, $hash);
}

function hr_portal_unlock_secret(): string
{
    $config = hr_portal_gate_config();
    $secret = trim((string) (getenv('HAMBELELA_HR_PORTAL_UNLOCK_SECRET') ?: ($config['hr_portal_unlock_secret'] ?? '')));
    if ($secret !== '') {
        return $secret;
    }

    $hash = hr_portal_pin_hash();
    if ($hash !== '') {
        return $hash;
    }

    $pin = hr_portal_plain_pin();
    return $pin !== '' ? hash('sha256', $pin) : '';
}

function hr_portal_unlock_token(): string
{
    return hash_hmac('sha256', 'hambelela-hr-portal-embedded-access', hr_portal_unlock_secret());
}

function hr_portal_is_unlocked(): bool
{
    $token = (string) ($_COOKIE['hr_portal_unlocked'] ?? '');
    $expected = hr_portal_unlock_token();

    return $expected !== '' && hash_equals($expected, $token);
}

function hr_portal_set_unlocked_cookie(string $path = '/apps/hr-portal'): void
{
    setcookie('hr_portal_unlocked', hr_portal_unlock_token(), [
        'expires' => time() + 3600,
        'path' => $path,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
