<?php

declare(strict_types=1);

function cw3_response(array $body, int $status = 200): array
{
    return ['status' => $status, 'body' => $body];
}

function cw3_positive_integer($value): int
{
    if (is_int($value)) return $value > 0 ? $value : 0;
    if (!is_string($value) || !preg_match('/^[1-9]\d*$/', $value)) return 0;
    return (int) $value;
}

function cw3_public_source(array $source): array
{
    return [
        'sale_size_id' => (int) $source['sale_size_id'],
        'calculation_version_id' => (int) $source['calculation_version_id'],
        'entity_type' => (string) $source['entity_type'],
        'entity_id' => (int) $source['entity_id'],
        'product_name' => (string) $source['product_name'],
        'variation_name' => (string) $source['variation_name'],
        'attributes' => (string) $source['attributes'],
        'confirmed_cost' => (float) $source['confirmed_cost'],
    ];
}

function cw3_handle(string $method, array $request, array $dependencies): array
{
    try {
        ($dependencies['authorize'])();
        if (!in_array($method, ['GET', 'POST'], true)) {
            return cw3_response(['ok' => false, 'code' => 'method_not_allowed', 'message' => 'Use GET for preview or POST for confirmed publishing.'], 405);
        }
        if ($method === 'POST') ($dependencies['verify_nonce'])();
        $ids = $request['sale_size_ids'] ?? [$request['sale_size_id'] ?? null];
        if (!is_array($ids) || count($ids) !== 1) throw new DomainException('single_line_required');
        $saleSizeId = cw3_positive_integer($ids[0]);
        if ($saleSizeId < 1) throw new DomainException('invalid_entity_id');
        $source = ($dependencies['source'])($saleSizeId);
        if (!in_array($source['entity_type'] ?? null, ['product', 'variation'], true) || (int) ($source['entity_id'] ?? 0) < 1) {
            throw new DomainException('exact_entity_required');
        }
        $expectedType = ($source['classification'] ?? null) === 'simple' ? 'product' : (($source['classification'] ?? null) === 'variation' ? 'variation' : null);
        if ($expectedType === null || $source['entity_type'] !== $expectedType) throw new DomainException('exact_entity_required');
        if (($source['version_status'] ?? '') !== 'confirmed' || empty($source['confirmed_at'])) {
            throw new DomainException('confirmed_cost_required');
        }
        $adapter = ($dependencies['adapter'])();
        $current = $adapter->read($source['entity_type'], (int) $source['entity_id'], $source['parent_id'] ?? null);
        if (!$current['feature_enabled']) throw new DomainException('woocommerce_cogs_disabled');
        if ($method === 'GET') return cw3_response(['ok' => true, 'publish_available' => true, 'source' => cw3_public_source($source), 'woocommerce' => $current]);
        if (empty($request['confirmed'])) throw new DomainException('explicit_confirmation_required');
        $expected = array_key_exists('expected_current_cost', $request) && $request['expected_current_cost'] !== null
            ? (float) $request['expected_current_cost'] : null;
        $user = ($dependencies['user'])();
        ($dependencies['audit'])('woocommerce_cogs_publish_started', $saleSizeId, $current, ['confirmed_source' => cw3_public_source($source)], $user);
        $after = $adapter->updateVerified($source['entity_type'], (int) $source['entity_id'], $source['parent_id'] ?? null, (float) $source['confirmed_cost'], $expected);
        ($dependencies['audit'])('woocommerce_cogs_published', $saleSizeId, $current, $after, $user);
        return cw3_response(['ok' => true, 'source' => cw3_public_source($source), 'woocommerce' => $after]);
    } catch (Throwable $error) {
        $known = ['confirmed_cost_required', 'exact_entity_required', 'single_line_required', 'invalid_entity_id', 'explicit_confirmation_required'];
        if (in_array($error->getMessage(), $known, true)) {
            return cw3_response(['ok' => false, 'code' => $error->getMessage(), 'message' => 'The confirmed cost request is not eligible for publishing.'], 422);
        }
        if (in_array($error->getMessage(), ['authentication_required', 'permission_denied', 'invalid_nonce'], true)) {
            return cw3_response(['ok' => false, 'code' => $error->getMessage(), 'message' => 'This request is not authorized.'], 403);
        }
        $safe = CostWorkbookNativeCogs::safeError($error);
        return cw3_response($safe, $safe['code'] === 'woocommerce_cogs_disabled' ? 409 : 502);
    }
}
