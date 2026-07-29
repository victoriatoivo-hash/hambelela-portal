<?php

declare(strict_types=1);

function packing_notification_recipients(int $itemId, int $actorId): array
{
    $row = ops_rows('SELECT assigned_employee_id FROM ops_packing_tasks WHERE id = ? LIMIT 1', [$itemId])[0] ?? [];
    $ids = [(int) ($row['assigned_employee_id'] ?? 0)];
    $ids = array_merge($ids, notifications_role_recipients(['owner_admin', 'supervisor_manager', 'front_desk_admin', 'front_desk_admin_employee']));
    return array_values(array_filter(array_unique(array_map('intval', $ids)), static fn(int $id): bool => $id > 0 && $id !== $actorId));
}

function packing_create_update_notifications(int $itemId, string $type, int $relatedId, int $actorId): void
{
    if ($itemId <= 0 || $relatedId <= 0 || !in_array($type, ['note_added', 'file_uploaded'], true)) return;
    $recipients = packing_notification_recipients($itemId, $actorId);
    foreach ($recipients as $recipientId) {
        $notificationId = notifications_create([
            'title' => $type === 'note_added' ? 'Packing note added' : 'Packing file uploaded',
            'message' => $type === 'note_added' ? 'A new note was added to a packing item.' : 'A new file was uploaded to a packing item.',
            'module' => 'packing', 'related_type' => 'packing_item', 'related_id' => $itemId,
            'priority' => 'normal',
            'deduplication_key' => "packing-item:{$itemId}:user:{$recipientId}:" . ($type === 'note_added' ? 'note' : 'file') . ":{$relatedId}",
            'action_link' => BASE_URL . '/apps/operations/consignments.php?packing_item=' . $itemId,
        ], [$recipientId]);
        if ($notificationId) ops_activity_log('packing_item_notification_created', 'packing_task', $itemId, ['notification_id'=>$notificationId, 'notification_type'=>$type, 'recipient_employee_id'=>$recipientId, 'actioning_employee_id'=>$actorId, 'related_id'=>$relatedId]);
    }
}

function packing_unread_updates_for_employee(int $employeeId): array
{
    if ($employeeId <= 0 || !notifications_schema_ready()) return [];
    $rows = ops_rows("SELECT n.related_id AS item_id,
        SUM(n.deduplication_key LIKE '%:note:%') AS notes,
        SUM(n.deduplication_key LIKE '%:file:%') AS files,
        COUNT(*) AS total
      FROM notifications n JOIN notification_recipients nr ON nr.notification_id=n.id
      WHERE nr.employee_id=? AND nr.read_at IS NULL AND nr.cleared_at IS NULL AND n.related_type='packing_item'
      GROUP BY n.related_id", [$employeeId]);
    $result=[]; foreach($rows as $row) $result[(int)$row['item_id']]=['total'=>(int)$row['total'],'notes'=>(int)$row['notes'],'files'=>(int)$row['files']];
    return $result;
}

function packing_mark_updates_read(int $itemId, int $employeeId, array $types=[]): array
{
    $patterns=[]; foreach($types as $type) { if($type==='note_added')$patterns[]='%:note:%'; if($type==='file_uploaded')$patterns[]='%:file:%'; }
    $sql="UPDATE notification_recipients nr JOIN notifications n ON n.id=nr.notification_id SET nr.read_at=COALESCE(nr.read_at,NOW()) WHERE nr.employee_id=? AND n.related_type='packing_item' AND n.related_id=? AND nr.cleared_at IS NULL";
    $params=[$employeeId,$itemId];
    if($patterns){$sql.=' AND (' . implode(' OR ',array_fill(0,count($patterns),'n.deduplication_key LIKE ?')) . ')';$params=array_merge($params,$patterns);}
    db()->prepare($sql)->execute($params);
    ops_activity_log('packing_item_notification_marked_read','packing_task',$itemId,['recipient_employee_id'=>$employeeId,'actioning_employee_id'=>$employeeId,'types'=>$types]);
    return packing_unread_updates_for_employee($employeeId)[$itemId] ?? ['total'=>0,'notes'=>0,'files'=>0];
}
