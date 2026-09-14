<?php
declare(strict_types=1);
namespace Hambelela\EPI;

use PDO;
use Throwable;

/** Records actionable notification lifecycle events without affecting delivery. */
final class NotificationActivityBridge
{
    public static function record(PDO $pdo, string $action, int $notificationId, int $employeeId, array $metadata = []): void
    {
        if ($notificationId <= 0 || $employeeId <= 0) return;
        try {
            Performance::configure($pdo);
            if (Performance::mode() === FeatureFlags::MODE_DISABLED) return;
            $isTest = Performance::mode() === FeatureFlags::MODE_TEST;
            if ($isTest && empty($metadata['test_data'])) return;
            $stmt = $pdo->prepare('SELECT n.*,e.full_name FROM notifications n LEFT JOIN ops_employees e ON e.id=? WHERE n.id=? LIMIT 1');
            $stmt->execute([$employeeId, $notificationId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if (!$row) return;
            $actionable = !empty($row['action_link']) || !empty($row['related_type']) || (string) $row['priority'] === 'urgent' || !empty($metadata['required_delivery']);
            $occurredAt = Support::timestamp($metadata['occurred_at'] ?? null);
            $reference = 'NOTIFICATION-' . $notificationId;
            $dedupe = Support::dedupe(['notification', $notificationId, $employeeId, $action, $occurredAt->format('Y-m-d H:i:s'), $metadata['event_nonce'] ?? '']);
            $meta = array_merge($metadata, [
                'notification_id' => $notificationId, 'notification_type' => $row['module'],
                'related_module' => $row['related_type'], 'related_reference' => $row['related_id'],
                'actionable' => $actionable, 'test_data' => $isTest, 'excluded_from_scoring' => true,
            ]);
            $base = ['module'=>'Notifications','reference_number'=>$reference,'employee_id'=>$employeeId,
                'employee_name'=>$row['full_name'] ?: ('Employee '.$employeeId),'department'=>'Operations',
                'activity_source'=>'notifications:'.$action,'timestamp'=>$occurredAt,'recording_mode'=>$isTest?'test':'automatic','metadata'=>$meta];
            Performance::recordActivity($base + ['activity_type'=>$action,'description'=>'Notification '.str_replace('_',' ',$action),
                'deduplication_key'=>Support::dedupe(['notification-activity',$dedupe])]);
            if ($actionable) Performance::recordEvidence($base + ['action'=>$action,'action_description'=>'Actionable notification '.str_replace('_',' ',$action),
                'priority'=>$row['priority'],'deduplication_key'=>Support::dedupe(['notification-evidence',$dedupe])]);
        } catch (Throwable $error) {
            error_log('EPI notification bridge failed: '.$error->getMessage());
            try {$pdo->prepare('INSERT INTO epi_performance_logs(level,component,message,context_json) VALUES(?,?,?,?)')
                ->execute(['error','notification_bridge',$error->getMessage(),Support::json(['notification_id'=>$notificationId,'action'=>$action])]);} catch (Throwable $ignored) {}
        }
    }
}
