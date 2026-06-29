<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

function wc_configured(): bool
{
    return WC_STORE_URL !== '' && WC_CONSUMER_KEY !== '' && WC_CONSUMER_SECRET !== '';
}

function wc_request_log(string $message, array $context = []): void
{
    $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);
    $dir = $basePath . '/storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    if ($context) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
    }

    @file_put_contents($dir . '/woocommerce-api.log', $line . PHP_EOL, FILE_APPEND);
}

function wc_get(string $path, array $query = []): array
{
    if (!wc_configured()) {
        wc_request_log('WooCommerce API request skipped', [
            'endpoint' => $path,
            'error' => 'WooCommerce API is not configured in config.local.php.',
        ]);
        throw new RuntimeException('WooCommerce API is not configured in config.local.php.');
    }

    $query = array_merge($query, [
        'consumer_key' => WC_CONSUMER_KEY,
        'consumer_secret' => WC_CONSUMER_SECRET,
    ]);

    $url = WC_STORE_URL . '/wp-json/wc/v3/' . ltrim($path, '/') . '?' . http_build_query($query);

    if (!function_exists('curl_init')) {
        wc_request_log('WooCommerce API request failed', [
            'endpoint' => $path,
            'error' => 'PHP cURL is not enabled on this server.',
        ]);
        throw new RuntimeException('PHP cURL is not enabled on this server.');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false || $body === '') {
        wc_request_log('WooCommerce API response failed', [
            'endpoint' => $path,
            'status' => $status,
            'count' => 0,
            'error' => $error ?: 'empty response',
            'body' => is_string($body) ? substr($body, 0, 500) : '',
        ]);
        throw new RuntimeException('WooCommerce request failed: ' . ($error ?: 'empty response'));
    }

    $data = json_decode($body, true);
    $count = is_array($data) ? count($data) : 0;
    wc_request_log('WooCommerce API response', [
        'endpoint' => $path,
        'status' => $status,
        'count' => $count,
        'error' => $error ?: null,
        'body' => $status >= 400 ? substr((string) $body, 0, 500) : null,
    ]);
    if ($status >= 400) {
        $message = is_array($data) ? ($data['message'] ?? $body) : $body;
        wc_request_log('WooCommerce API response error', [
            'endpoint' => $path,
            'status' => $status,
            'count' => $count,
            'error' => $message,
            'body' => substr((string) $body, 0, 500),
        ]);
        throw new RuntimeException('WooCommerce request failed: ' . $message);
    }

    return is_array($data) ? $data : [];
}

