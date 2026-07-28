<?php

declare(strict_types=1);

function ops_order_documents_log(string $message, array $context = []): void
{
    $dir = BASE_PATH . '/storage/logs';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    @file_put_contents($dir . '/order-documents.log', '[' . date('Y-m-d H:i:s') . '] ' . $message . ($context ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '') . PHP_EOL, FILE_APPEND);
}

function ops_order_documents_ensure_table(): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS ops_order_documents (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NOT NULL,
            document_type VARCHAR(16) NOT NULL,
            source_system VARCHAR(32) NOT NULL DEFAULT 'website_pos',
            source_order_id VARCHAR(100) NOT NULL,
            document_id VARCHAR(190) NOT NULL,
            source_url TEXT NOT NULL,
            generated_at DATETIME NULL,
            document_version VARCHAR(100) NOT NULL,
            source_checksum VARCHAR(128) NULL,
            cached_checksum VARCHAR(64) NULL,
            cached_path VARCHAR(255) NULL,
            is_current TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_order_document (order_id, document_type, document_id, document_version),
            KEY idx_order_document_current (order_id, document_type, is_current)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ready = true;
        return $ready;
    } catch (Throwable $error) {
        ops_order_documents_log('Unable to ensure document metadata table', ['error' => $error->getMessage()]);
        $ready = false;
        return $ready;
    }
}

function ops_order_document_meta_value(array $sourceOrder, array $keys): string
{
    $wanted = array_map('strtolower', $keys);
    foreach ((array) ($sourceOrder['meta_data'] ?? []) as $meta) {
        if (!in_array(strtolower((string) ($meta['key'] ?? '')), $wanted, true)) continue;
        $value = $meta['value'] ?? '';
        if (!is_scalar($value)) continue;
        $value = trim((string) $value);
        if ($value !== '') return $value;
    }
    return '';
}

function ops_order_document_metadata(array $sourceOrder, string $type): ?array
{
    $prefixes = [$type, 'pos_' . $type, '_pos_' . $type, 'wc_' . $type];
    $urlKeys = [];
    $idKeys = [];
    $dateKeys = [];
    $versionKeys = [];
    $checksumKeys = [];
    foreach ($prefixes as $prefix) {
        $urlKeys = array_merge($urlKeys, [$prefix . '_url', $prefix . '_pdf', $prefix . '_pdf_url', $prefix . '_document_url']);
        $idKeys = array_merge($idKeys, [$prefix . '_id', $prefix . '_document_id']);
        $dateKeys = array_merge($dateKeys, [$prefix . '_generated_at', $prefix . '_date']);
        $versionKeys = array_merge($versionKeys, [$prefix . '_version']);
        $checksumKeys = array_merge($checksumKeys, [$prefix . '_checksum', $prefix . '_sha256']);
    }
    $url = '';
    foreach ([$type . '_url', $type . '_pdf', 'pos_' . $type . '_url'] as $field) {
        if (!empty($sourceOrder[$field]) && is_scalar($sourceOrder[$field])) {
            $url = trim((string) $sourceOrder[$field]);
            if ($url !== '') break;
        }
    }
    if ($url === '') $url = ops_order_document_meta_value($sourceOrder, $urlKeys);
    if ($url === '') {
        foreach ((array) ($sourceOrder['meta_data'] ?? []) as $meta) {
            $key = strtolower((string) ($meta['key'] ?? ''));
            $value = $meta['value'] ?? '';
            if (strpos($key, $type) === false || !is_scalar($value)) continue;
            $candidate = trim((string) $value);
            if (filter_var($candidate, FILTER_VALIDATE_URL)) {
                $url = $candidate;
                break;
            }
        }
    }
    if (!filter_var($url, FILTER_VALIDATE_URL)) return null;
    $storeHost = strtolower((string) parse_url(WC_STORE_URL, PHP_URL_HOST));
    $sourceHost = strtolower((string) parse_url($url, PHP_URL_HOST));
    if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https' || $sourceHost === '' || !hash_equals($storeHost, $sourceHost)) return null;

    $sourceOrderId = (string) ($sourceOrder['id'] ?? '');
    $documentId = ops_order_document_meta_value($sourceOrder, $idKeys);
    if ($documentId === '') $documentId = 'pos-' . $type . '-' . $sourceOrderId . '-' . substr(hash('sha256', $url), 0, 16);
    $generated = ops_order_document_meta_value($sourceOrder, $dateKeys);
    $timestamp = $generated !== '' ? strtotime($generated) : false;
    return [
        'type' => $type,
        'source_order_id' => $sourceOrderId,
        'document_id' => $documentId,
        'source_url' => $url,
        'generated_at' => $timestamp ? date('Y-m-d H:i:s', $timestamp) : null,
        'version' => ops_order_document_meta_value($sourceOrder, $versionKeys) ?: substr(hash('sha256', $url), 0, 16),
        'checksum' => strtolower(ops_order_document_meta_value($sourceOrder, $checksumKeys)) ?: null,
    ];
}

function ops_order_document_sync_metadata(int $orderId, array $sourceOrder): array
{
    $result = ['receipt' => null, 'invoice' => null];
    if (!ops_order_documents_ensure_table()) return $result;
    foreach (array_keys($result) as $type) {
        $metadata = ops_order_document_metadata($sourceOrder, $type);
        if (!$metadata) continue;
        try {
            $pdo = db();
            $previous = ops_rows('SELECT document_id, document_version FROM ops_order_documents WHERE order_id = ? AND document_type = ? AND is_current = 1 ORDER BY id DESC LIMIT 1', [$orderId, $type])[0] ?? null;
            $pdo->prepare('UPDATE ops_order_documents SET is_current = 0 WHERE order_id = ? AND document_type = ? AND (document_id <> ? OR document_version <> ?)')->execute([$orderId, $type, $metadata['document_id'], $metadata['version']]);
            $pdo->prepare("INSERT INTO ops_order_documents (order_id, document_type, source_order_id, document_id, source_url, generated_at, document_version, source_checksum, is_current)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE generated_at = VALUES(generated_at), source_checksum = VALUES(source_checksum), is_current = 1")
                ->execute([$orderId, $type, $metadata['source_order_id'], $metadata['document_id'], $metadata['source_url'], $metadata['generated_at'], $metadata['version'], $metadata['checksum']]);
            if ($previous && ((string) $previous['document_id'] !== (string) $metadata['document_id'] || (string) $previous['document_version'] !== (string) $metadata['version'])) {
                ops_activity_log('pos_document_version_changed', 'order', $orderId, [
                    'document_type' => $type,
                    'previous_version' => (string) $previous['document_version'],
                    'new_version' => (string) $metadata['version'],
                    'previous_document_id' => (string) $previous['document_id'],
                    'new_document_id' => (string) $metadata['document_id'],
                    'reason' => 'POS supplied a new immutable document version.',
                ]);
            }
            $result[$type] = $metadata;
        } catch (Throwable $error) {
            ops_order_documents_log('Unable to save document metadata', ['order_id' => $orderId, 'type' => $type, 'error' => $error->getMessage()]);
        }
    }
    return $result;
}

function ops_order_document_current(int $orderId, string $type): ?array
{
    if (!ops_order_documents_ensure_table()) return null;
    return ops_rows('SELECT * FROM ops_order_documents WHERE order_id = ? AND document_type = ? AND is_current = 1 ORDER BY id DESC LIMIT 1', [$orderId, $type])[0] ?? null;
}
