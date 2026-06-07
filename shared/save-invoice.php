<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';

function post_string(string $key, string $default = ''): string
{
    return trim((string) ($_POST[$key] ?? $default));
}

function post_float(string $key): float
{
    $value = preg_replace('/[^0-9.-]/', '', (string) ($_POST[$key] ?? '0'));
    return $value === '' ? 0.0 : (float) $value;
}

function nullable_date(string $value): ?string
{
    $value = trim($value);
    return $value === '' ? null : $value;
}

function ensure_supplier(PDO $pdo, string $name): int
{
    $name = trim($name) ?: 'Unknown supplier';
    $stmt = $pdo->prepare('SELECT id FROM suppliers WHERE name = ? LIMIT 1');
    $stmt->execute([$name]);
    $id = $stmt->fetchColumn();

    if ($id) {
        return (int) $id;
    }

    $stmt = $pdo->prepare('INSERT INTO suppliers (name) VALUES (?)');
    $stmt->execute([$name]);
    return (int) $pdo->lastInsertId();
}

function ensure_transport_provider(PDO $pdo, string $name): int
{
    $name = trim($name) ?: 'Unknown transport provider';
    $stmt = $pdo->prepare('SELECT id FROM transport_providers WHERE name = ? LIMIT 1');
    $stmt->execute([$name]);
    $id = $stmt->fetchColumn();

    if ($id) {
        return (int) $id;
    }

    $stmt = $pdo->prepare('INSERT INTO transport_providers (name) VALUES (?)');
    $stmt->execute([$name]);
    return (int) $pdo->lastInsertId();
}

