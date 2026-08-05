<?php

declare(strict_types=1);

namespace Hambelela\EPI;

use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Fail-safe adapter from the existing Orders activity stream into EPI.
 * It never writes to operational Orders tables.
 */
final class OrdersActivityBridge
{
    private static $recordingAvailable;

    public static function record(PDO $pdo, string $legacyAction, int $orderId, array $activityMetadata = []): void
    {
        if ($orderId <= 0) {
            return;
        }

        try {
            if (self::$recordingAvailable === null) {
                Performance::configure($pdo);
                $moduleFlag = $pdo->query("SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key='orders_front_desk_module_enabled' LIMIT 1")->fetchColumn();
                self::$recordingAvailable = Performance::enabled()
                    && ($moduleFlag === false || in_array(strtolower(trim((string) $moduleFlag)), ['1', 'true', 'yes', 'on'], true));
            }
            if (!self::$recordingAvailable) {
                return;
            }

            $order = self::order($pdo, $orderId);
            if (!$order) {
                return;
            }
            $actor = self::actor($pdo, isset($activityMetadata['employee_id']) ? (int) $activityMetadata['employee_id'] : null);
            $occurredAt = Support::timestamp($activityMetadata['occurred_at'] ?? null);
            $reference = trim((string) ($order['order_number'] ?? '')) ?: ('ORDER-' . $orderId);
            $statusBefore = self::stringValue($activityMetadata, ['old_value', 'previous_status', 'status_before']);
            $statusAfter = self::stringValue($activityMetadata, ['new_value', 'new_status', 'status_after']);
            if ($statusAfter === '' && self::isStatusAction($legacyAction, $activityMetadata)) {
                $statusAfter = (string) ($order['status'] ?? '');
            }
            $action = self::canonicalAction($legacyAction, $statusBefore, $statusAfter, $activityMetadata);
            $orderType = self::orderType($order);
            $walkIn = self::isWalkIn($pdo, $order, $orderType);
            $dueAt = $walkIn ? self::walkInDueAt($pdo, (string) ($order['created_at'] ?? $occurredAt->format('Y-m-d H:i:s'))) : null;
            $completed = in_array($statusAfter, ['completed', 'packed', 'verified'], true) || $action === 'order_completed';
            $overdue = $completed && $dueAt instanceof DateTimeImmutable && $occurredAt > $dueAt;
            $durationStart = (string) ($order['created_at'] ?? '');
            $workingMinutes = $durationStart !== '' ? Performance::businessMinutes($durationStart, $occurredAt) : null;
            $metadata = array_merge($activityMetadata, [
                'legacy_action' => $legacyAction,
                'order_id' => $orderId,
                'customer' => (string) ($order['customer_name'] ?? ''),
                'customer_contact' => (string) ($order['customer_contact'] ?? ''),
                'order_created_at' => (string) ($order['created_at'] ?? ''),
                'order_type' => $orderType,
                'is_walk_in' => $walkIn,
                'payment_method' => (string) ($order['payment_method'] ?? ''),
                'payment_status' => (string) ($order['payment_status'] ?? ''),
                'due_at' => $dueAt ? $dueAt->format('Y-m-d H:i:s') : null,
                'overdue' => $overdue,
            ]);
            $dedupe = Support::dedupe(['orders-activity', $orderId, $legacyAction, $actor['id'] ?? '', $occurredAt->format('Y-m-d H:i:s'), $activityMetadata]);

            Performance::recordActivity([
                'module' => 'Orders',
                'reference_number' => $reference,
                'employee_id' => $actor['id'] ?? null,
                'employee_name' => $actor['name'] ?? null,
                'department' => $actor['department'] ?? 'Front Desk',
                'activity_type' => $action,
                'description' => self::description($action, $reference),
                'activity_source' => 'orders_activity_log:' . $legacyAction,
                'timestamp' => $occurredAt,
                'manual' => !self::isAutomatic($legacyAction, $activityMetadata),
                'deduplication_key' => $dedupe,
                'metadata' => $metadata,
            ]);

            $evidenceUuid = Performance::recordEvidence([
                'module' => 'Orders',
                'reference_number' => $reference,
                'employee_id' => $actor['id'] ?? null,
                'employee_name' => $actor['name'] ?? null,
                'department' => $actor['department'] ?? 'Front Desk',
                'action' => $action,
                'action_description' => self::description($action, $reference),
                'previous_value' => $activityMetadata['old_value'] ?? $activityMetadata['previous_value'] ?? null,
                'new_value' => $activityMetadata['new_value'] ?? $activityMetadata['value'] ?? null,
                'status_before' => $statusBefore ?: null,
                'status_after' => $statusAfter ?: null,
                'priority' => $order['priority'] ?? null,
                'timestamp' => $occurredAt,
                'working_minutes' => $workingMinutes,
                'duration_seconds' => $durationStart !== '' ? max(0, $occurredAt->getTimestamp() - Support::timestamp($durationStart)->getTimestamp()) : null,
                'manual' => !self::isAutomatic($legacyAction, $activityMetadata),
                'activity_source' => 'orders_activity_log:' . $legacyAction,
                'deduplication_key' => Support::dedupe(['orders-evidence', $dedupe]),
                'metadata' => $metadata,
            ]);

            self::recordOwnership($reference, $actor, $action, $activityMetadata, $occurredAt);
            self::recordCandidateEvidence($pdo, $reference, $actor, $action, $occurredAt, $metadata, $overdue, $workingMinutes, $evidenceUuid);
            self::recordPackingCompletion($pdo, $reference, $actor, $occurredAt, $statusBefore, $statusAfter, $metadata, $workingMinutes, $evidenceUuid);
        } catch (Throwable $error) {
            self::logFailure($error, $legacyAction, $orderId);
        }
    }

    private static function order(PDO $pdo, int $orderId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM ops_orders WHERE id = ? LIMIT 1');
        $stmt->execute([$orderId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function actor(PDO $pdo, ?int $explicitId): array
    {
        $employeeId = $explicitId ?: (function_exists('ops_current_employee_id') ? (int) ops_current_employee_id() : 0);
        $fallbackName = function_exists('current_user') ? (string) ((current_user()['name'] ?? '') ?: '') : '';
        if ($employeeId <= 0) {
            return ['id' => null, 'name' => $fallbackName ?: 'System', 'department' => 'Front Desk'];
        }
        $stmt = $pdo->prepare('SELECT e.id, e.full_name, COALESCE(r.name, \'Front Desk\') department FROM ops_employees e LEFT JOIN ops_roles r ON r.id=e.role_id WHERE e.id=? LIMIT 1');
        try {
            $stmt->execute([$employeeId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $error) {
            $row = [];
        }
        $name = (string) ($row['full_name'] ?? '');
        return ['id' => $employeeId, 'name' => $name !== '' ? $name : ($fallbackName !== '' ? $fallbackName : ('Employee ' . $employeeId)), 'department' => (string) ($row['department'] ?? 'Front Desk')];
    }

    private static function canonicalAction(string $legacyAction, string $before, string $after, array $metadata): string
    {
        if (self::isStatusAction($legacyAction, $metadata)) {
            if (in_array($after, ['completed', 'packed', 'verified'], true)) return 'order_completed';
            if (in_array($before, ['completed', 'packed', 'verified'], true) && !in_array($after, ['completed', 'packed', 'verified'], true)) return 'order_reopened';
            return 'order_status_changed';
        }
        $map = [
            'payment_status_updated' => 'payment_verified', 'payment_status_auto_walk_in' => 'payment_verified',
            'payment_changed' => 'payment_corrected', 'customer_contact_changed' => 'customer_contact_updated',
            'mobile_changed' => 'customer_contact_updated', 'customer_updated' => 'order_administration_updated',
            'mode_changed' => 'order_type_updated', 'amount_changed' => 'order_administration_updated',
            'update_added' => 'customer_communication_recorded', 'order_datetime_updated' => 'order_administration_updated',
            'group_date_updated' => 'order_administration_updated', 'order_moved_to_trash' => 'order_moved_to_trash',
            'order_restored_from_trash' => 'order_restored', 'order_archived' => 'order_archived',
            'order_restored_from_archive' => 'order_restored', 'order_file_uploaded' => 'order_file_uploaded',
            'order_file_deleted' => 'order_file_deleted', 'packed_by_changed' => 'order_assignment_changed',
            'packed_by_cleared' => 'order_assignment_changed', 'assigned' => 'order_assignment_changed',
            'packer_attribution_corrected' => 'order_assignment_corrected',
        ];
        if (strpos($legacyAction, 'bulk_') === 0) return 'order_' . substr($legacyAction, 5);
        return $map[$legacyAction] ?? $legacyAction;
    }

    private static function isStatusAction(string $action, array $metadata): bool
    {
        return in_array($action, ['status_changed', 'order_completed', 'bulk_status_updated'], true) || (($metadata['field'] ?? '') === 'status');
    }

    private static function stringValue(array $metadata, array $keys): string
    {
        foreach ($keys as $key) if (isset($metadata[$key])) return strtolower(trim((string) $metadata[$key]));
        return '';
    }

    private static function orderType(array $order): string
    {
        $raw = strtolower(trim((string) (($order['fulfilment_mode'] ?? '') ?: ($order['order_type'] ?? ''))));
        if (strpos($raw, 'courier') !== false) return 'courier';
        if (strpos($raw, 'deliver') !== false) return 'delivery';
        if (strpos($raw, 'collect') !== false) return 'collection';
        if (strpos($raw, 'walk') !== false) return 'walk_in';
        return $raw ?: 'unknown';
    }

    private static function isWalkIn(PDO $pdo, array $order, string $orderType): bool
    {
        if ($orderType === 'walk_in') return true;
        $identifiers = ['walk-in', 'walk in', 'walk_in', 'walk in customer'];
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key='orders_walk_in_identifiers' LIMIT 1");
            $stmt->execute();
            $configured = json_decode((string) $stmt->fetchColumn(), true);
            if (is_array($configured)) $identifiers = array_merge($identifiers, $configured);
        } catch (Throwable $error) {
        }
        $haystack = strtolower(implode(' ', [(string) ($order['customer_contact'] ?? ''), (string) ($order['customer_name'] ?? ''), (string) ($order['order_type'] ?? ''), (string) ($order['fulfilment_mode'] ?? '')]));
        foreach ($identifiers as $identifier) if (($needle = strtolower(trim((string) $identifier))) !== '' && strpos($haystack, $needle) !== false) return true;
        return false;
    }

    private static function walkInDueAt(PDO $pdo, string $loadedAt): ?DateTimeImmutable
    {
        try {
            $engine = new BusinessTimeEngine($pdo);
            $loaded = Support::timestamp($loadedAt);
            $window = $engine->windowForDate($loaded);
            if ($window !== null && $loaded < $window[1]) return Performance::graceDueAt('walk_in_orders', $window[1]);
            $cursor = $loaded->modify('+1 day')->setTime(0, 0);
            for ($i = 0; $i < 370; $i++, $cursor = $cursor->modify('+1 day')) {
                $next = $engine->windowForDate($cursor);
                if ($next !== null) return Performance::graceDueAt('walk_in_orders', $next[1]);
            }
        } catch (Throwable $error) {
        }
        return null;
    }

    private static function recordOwnership(string $reference, array $actor, string $action, array $metadata, DateTimeImmutable $occurredAt): void
    {
        if (!in_array($action, ['order_completed', 'order_reopened', 'order_assignment_changed', 'order_assignment_corrected', 'payment_verified'], true)) return;
        Performance::recordOwnership([
            'module' => 'Orders', 'reference_number' => $reference,
            'current_owner_id' => $actor['id'] ?? null, 'current_owner_name' => $actor['name'] ?? null,
            'completed_by_id' => $action === 'order_completed' ? ($actor['id'] ?? null) : null,
            'completed_by_name' => $action === 'order_completed' ? ($actor['name'] ?? null) : null,
            'verified_by_id' => $action === 'payment_verified' ? ($actor['id'] ?? null) : null,
            'verified_by_name' => $action === 'payment_verified' ? ($actor['name'] ?? null) : null,
            'change_reason' => $metadata['reason'] ?? $metadata['correction_note'] ?? $action,
            'changed_by' => $actor['id'] ?? null, 'effective_at' => $occurredAt,
        ]);
    }

    private static function recordCandidateEvidence(PDO $pdo, string $reference, array $actor, string $action, DateTimeImmutable $occurredAt, array $metadata, bool $overdue, ?float $workingMinutes, ?string $sourceEvidence): void
    {
        $candidate = null;
        if ($overdue) $candidate = 'late_completion';
        elseif ($action === 'order_reopened') $candidate = 'order_reopened_due_to_front_desk';
        elseif ($action === 'payment_corrected') $candidate = 'incorrect_payment';
        $prefix = 'deduction_candidate_';
        if ($candidate === null && $action === 'order_completed' && $workingMinutes !== null) {
            $threshold = 30;
            try {
                $threshold = (int) $pdo->query("SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key='orders_excellent_completion_minutes' LIMIT 1")->fetchColumn() ?: 30;
            } catch (Throwable $error) {
            }
            if ($workingMinutes <= $threshold) { $candidate = 'excellent_completion_time'; $prefix = 'bonus_candidate_'; }
        }
        if ($candidate === null && $action === 'payment_verified') { $candidate = 'payment_accuracy'; $prefix = 'bonus_candidate_'; }
        if ($candidate === null) return;
        Performance::recordEvidence([
            'module' => 'Orders', 'reference_number' => $reference,
            'employee_id' => $actor['id'] ?? null, 'employee_name' => $actor['name'] ?? null,
            'department' => $actor['department'] ?? 'Front Desk', 'action' => $prefix . $candidate,
            'action_description' => 'Front Desk ' . ($prefix === 'bonus_candidate_' ? 'bonus' : 'deduction') . ' candidate: ' . str_replace('_', ' ', $candidate),
            'timestamp' => $occurredAt, 'manual' => false, 'activity_source' => 'orders_epi_candidate_engine',
            'deduplication_key' => Support::dedupe(['orders-candidate', $reference, $candidate, $occurredAt->format('Y-m-d H:i:s')]),
            'metadata' => array_merge($metadata, ['source_evidence_uuid' => $sourceEvidence, 'candidate_only' => true]),
        ]);
    }

    /** Phase 3: In Progress is the existing Orders-board signal that packing is ready for the next stage. */
    private static function recordPackingCompletion(PDO $pdo, string $reference, array $actor, DateTimeImmutable $occurredAt, string $before, string $after, array $metadata, ?float $workingMinutes, ?string $sourceEvidence): void
    {
        if ($after !== 'in_progress' || $before === 'in_progress') return;
        try {
            $flag = $pdo->query("SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key='packing_module_enabled' LIMIT 1")->fetchColumn();
            if ($flag !== false && !in_array(strtolower(trim((string)$flag)), ['1','true','yes','on'], true)) return;
        } catch (Throwable $error) { return; }
        $mode = (string)($metadata['order_type'] ?? 'unknown');
        $walkIn = !empty($metadata['is_walk_in']);
        $dueAt = $mode === 'courier' ? self::courierPackingDueAt($pdo, (string)($metadata['created_at'] ?? $metadata['order_created_at'] ?? '')) : null;
        $missed = $dueAt instanceof DateTimeImmutable && $occurredAt > $dueAt;
        $packingMetadata = array_merge($metadata, [
            'packing_stage_semantics'=>'Orders In Progress means packed and ready for next stage',
            'packing_classification'=>$walkIn ? 'walk_in_assistance' : $mode,
            'courier_due_at'=>$dueAt ? $dueAt->format('Y-m-d H:i:s') : null,
            'courier_cutoff_missed'=>$missed,
            'source_evidence_uuid'=>$sourceEvidence,
        ]);
        $packingUuid = Performance::recordEvidence([
            'module'=>'Packing','reference_number'=>$reference,'employee_id'=>$actor['id'] ?? null,'employee_name'=>$actor['name'] ?? null,
            'department'=>'Packing','action'=>'order_packed','action_description'=>'Order packing completed for '.$reference,
            'status_before'=>$before ?: null,'status_after'=>$after,'timestamp'=>$occurredAt,'working_minutes'=>$workingMinutes,
            'manual'=>true,'activity_source'=>'orders_status_transition:in_progress',
            'deduplication_key'=>Support::dedupe(['packing-order',$reference,$actor['id'] ?? '',$occurredAt->format('Y-m-d H:i:s')]),'metadata'=>$packingMetadata,
        ]);
        if ($missed) Performance::recordEvidence([
            'module'=>'Packing','reference_number'=>$reference,'employee_id'=>$actor['id'] ?? null,'employee_name'=>$actor['name'] ?? null,'department'=>'Packing',
            'action'=>'deduction_candidate_courier_cutoff_missed','action_description'=>'Potential deduction: courier packing cut-off missed',
            'timestamp'=>$occurredAt,'working_minutes'=>$workingMinutes,'manual'=>false,'activity_source'=>'packing_epi_candidate_engine',
            'deduplication_key'=>Support::dedupe(['packing-courier-missed',$reference,$occurredAt->format('Y-m-d H:i:s')]),
            'metadata'=>array_merge($packingMetadata,['candidate_only'=>true,'review_status'=>'pending_owner_review','source_evidence_uuid'=>$packingUuid]),
        ]);
        elseif ($mode === 'courier') Performance::recordEvidence([
            'module'=>'Packing','reference_number'=>$reference,'employee_id'=>$actor['id'] ?? null,'employee_name'=>$actor['name'] ?? null,'department'=>'Packing',
            'action'=>'bonus_candidate_courier_ready_before_cutoff','action_description'=>'Positive evidence: courier ready before cut-off',
            'timestamp'=>$occurredAt,'manual'=>false,'activity_source'=>'packing_epi_candidate_engine',
            'deduplication_key'=>Support::dedupe(['packing-courier-ontime',$reference,$occurredAt->format('Y-m-d H:i:s')]),
            'metadata'=>array_merge($packingMetadata,['candidate_only'=>true,'source_evidence_uuid'=>$packingUuid]),
        ]);
    }

    private static function courierPackingDueAt(PDO $pdo, string $loadedAt): ?DateTimeImmutable
    {
        if (trim($loadedAt)==='') return null;
        try {
            $cutoff='14:00';$stmt=$pdo->prepare("SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key='packing_courier_cutoff' LIMIT 1");$stmt->execute();$saved=(string)$stmt->fetchColumn();if(preg_match('/^\d{2}:\d{2}$/',$saved))$cutoff=$saved;
            list($hour,$minute)=array_map('intval',explode(':',$cutoff));$engine=new BusinessTimeEngine($pdo);$loaded=Support::timestamp($loadedAt);$window=$engine->windowForDate($loaded);
            if($window!==null){$same=$loaded->setTime($hour,$minute);if($loaded<$same&&$same<=$window[1])return Performance::graceDueAt('packing_courier_cutoff',$same);}
            $cursor=$loaded->modify('+1 day')->setTime(0,0);for($i=0;$i<370;$i++,$cursor=$cursor->modify('+1 day')){$next=$engine->windowForDate($cursor);if($next!==null){$due=$cursor->setTime($hour,$minute);if($due>$next[1])$due=$next[1];return Performance::graceDueAt('packing_courier_cutoff',$due);}}
        } catch(Throwable $error) {}
        return null;
    }

    private static function isAutomatic(string $action, array $metadata): bool
    {
        return strpos($action, 'auto_') !== false || strpos($action, 'automatically') !== false || (($metadata['source'] ?? '') === 'woocommerce_sync');
    }

    private static function description(string $action, string $reference): string
    {
        return ucwords(str_replace('_', ' ', $action)) . ' for ' . $reference;
    }

    private static function logFailure(Throwable $error, string $action, int $orderId): void
    {
        $dir = defined('BASE_PATH') ? BASE_PATH . '/storage/logs' : sys_get_temp_dir();
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @file_put_contents($dir . '/epi-orders.log', '[' . date('c') . '] ' . $action . ' order ' . $orderId . ': ' . $error->getMessage() . PHP_EOL, FILE_APPEND);
    }
}
