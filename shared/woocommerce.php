<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

function wc_configured(): bool
{
    return WC_STORE_URL !== '' && WC_CONSUMER_KEY !== '' && WC_CONSUMER_SECRET !== '';
}

function wc_get(string $path, array $query = []): array
{
    if (!wc_configured()) {
        throw new RuntimeException('WooCommerce API is not configured in config.local.php.');
    }

    $query = array_merge($query, [
        'consumer_key' => WC_CONSUMER_KEY,
        'consumer_secret' => WC_CONSUMER_SECRET,
    ]);

    $url = WC_STORE_URL . '/wp-json/wc/v3/' . ltrim($path, '/') . '?' . http_build_query($query);

    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is not enabled on this server.');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false || $body === '') {
        throw new RuntimeException('WooCommerce request failed: ' . ($error ?: 'empty response'));
    }

    $data = json_decode($body, true);
    if ($status >= 400) {
        $message = is_array($data) ? ($data['message'] ?? $body) : $body;
        throw new RuntimeException('WooCommerce request failed: ' . $message);
    }

    return is_array($data) ? $data : [];
}

