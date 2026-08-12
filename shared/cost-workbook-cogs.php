<?php

declare(strict_types=1);

/**
 * Narrow adapter for WooCommerce 11.0.1 native Cost of Goods Sold.
 * Authentication remains entirely inside shared/woocommerce.php.
 */
final class CostWorkbookNativeCogs
{
    private $get;
    private $put;

    public function __construct(?callable $get = null, ?callable $put = null)
    {
        $this->get = $get ?? static fn(string $path, array $query = []): array => wc_get($path, $query);
        $this->put = $put ?? static fn(string $path, array $payload): array => wc_put($path, $payload);
    }

    public static function endpoint(string $entityType, int $entityId, ?int $parentId = null): string
    {
        if ($entityId < 1) throw new InvalidArgumentException('A WooCommerce entity ID is required.');
        if ($entityType === 'product') return 'products/' . $entityId;
        if ($entityType === 'variation' && ($parentId ?? 0) > 0) {
            return 'products/' . $parentId . '/variations/' . $entityId;
        }
        throw new InvalidArgumentException('Use an exact product or variation match.');
    }

    public static function payload(float $confirmedCost, string $entityType): array
    {
        if (!is_finite($confirmedCost) || $confirmedCost <= 0) {
            throw new InvalidArgumentException('A confirmed cost greater than zero is required.');
        }
        $cogs = ['values' => [['defined_value' => $confirmedCost]]];
        if ($entityType === 'variation') $cogs['defined_value_is_additive'] = false;
        elseif ($entityType !== 'product') throw new InvalidArgumentException('Invalid WooCommerce entity type.');
        return ['cost_of_goods_sold' => $cogs];
    }

    public static function normalize(array $response, string $entityType, int $entityId): array
    {
        if (!array_key_exists('cost_of_goods_sold', $response)) {
            return [
                'feature_enabled' => false,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'defined_cost' => null,
                'effective_cost' => null,
                'total_cost' => null,
                'inheritance_mode' => 'unavailable',
                'defined_value_is_additive' => null,
                'verified' => false,
            ];
        }
        $cogs = is_array($response['cost_of_goods_sold']) ? $response['cost_of_goods_sold'] : [];
        $value = is_array($cogs['values'][0] ?? null) ? $cogs['values'][0] : [];
        $defined = array_key_exists('defined_value', $value) && $value['defined_value'] !== null ? (float)$value['defined_value'] : null;
        $additive = $entityType === 'variation' ? (bool)($cogs['defined_value_is_additive'] ?? false) : null;
        return [
            'feature_enabled' => true,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'defined_cost' => $defined,
            'effective_cost' => isset($value['effective_value']) ? (float)$value['effective_value'] : null,
            'total_cost' => isset($cogs['total_value']) ? (float)$cogs['total_value'] : null,
            'inheritance_mode' => $entityType === 'variation' ? ($defined === null ? 'parent' : ($additive ? 'additive' : 'override')) : 'direct',
            'defined_value_is_additive' => $additive,
            'verified' => false,
        ];
    }

    public function read(string $entityType, int $entityId, ?int $parentId = null): array
    {
        $path = self::endpoint($entityType, $entityId, $parentId);
        return self::normalize(($this->get)($path, ['context' => 'edit']), $entityType, $entityId);
    }

    public function updateVerified(string $entityType, int $entityId, ?int $parentId, float $confirmedCost, ?float $expectedCurrent): array
    {
        $before = $this->read($entityType, $entityId, $parentId);
        if (!$before['feature_enabled']) throw new DomainException('woocommerce_cogs_disabled');
        if (!self::same($before['defined_cost'], $expectedCurrent)) throw new DomainException('woocommerce_cogs_stale');
        $path = self::endpoint($entityType, $entityId, $parentId);
        $after = self::normalize(($this->put)($path, self::payload($confirmedCost, $entityType)), $entityType, $entityId);
        if (!$after['feature_enabled'] || !self::same($after['defined_cost'], $confirmedCost)) {
            throw new RuntimeException('woocommerce_cogs_verification_failed');
        }
        $after['verified'] = true;
        return $after;
    }

    private static function same(?float $left, ?float $right): bool
    {
        if ($left === null || $right === null) return $left === $right;
        return abs($left - $right) < 0.000001;
    }

    public static function safeError(Throwable $error): array
    {
        $code = in_array($error->getMessage(), ['woocommerce_cogs_disabled', 'woocommerce_cogs_stale', 'woocommerce_cogs_verification_failed'], true)
            ? $error->getMessage() : 'woocommerce_cogs_request_failed';
        $messages = [
            'woocommerce_cogs_disabled' => 'WooCommerce Cost of Goods Sold is not enabled. Cost publishing is unavailable.',
            'woocommerce_cogs_stale' => 'The WooCommerce cost changed after preview. Refresh and review it again.',
            'woocommerce_cogs_verification_failed' => 'WooCommerce did not return the confirmed cost. Nothing further was published.',
            'woocommerce_cogs_request_failed' => 'WooCommerce could not complete the cost request safely.',
        ];
        return ['ok' => false, 'code' => $code, 'message' => $messages[$code]];
    }
}
