<?php
require_once __DIR__ . '/leave-balance-service.php';

function hrCanCancelApprovedLeave(array $user): bool {
    return ($user['role'] ?? '') === 'admin'
        && ($user['portal_role_key'] ?? '') === 'owner_admin'
        && (int)($user['portal_user_id'] ?? 0) > 0;
}

// Additive only: retain original approval, attachments and request identity.
function hrEnsureLeaveCancellationSchema(PDO $db): void {
    $columns = $db->query('SHOW COLUMNS FROM leave_requests')->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    $type = (string)($columns['status']['Type'] ?? '');
    if (strpos($type, "'cancelled'") === false) {
        if (!preg_match("/^enum\('[a-z_]+'(?:,'[a-z_]+')*\)$/i", $type)) {
            throw new RuntimeException('Leave status schema requires review before cancellation can be enabled.');
        }
        $db->exec("ALTER TABLE leave_requests MODIFY status " . substr($type, 0, -1) . ",'cancelled') DEFAULT 'pending'");
    }
    foreach (['cancelled_by'=>'INT UNSIGNED NULL', 'cancelled_at'=>'DATETIME NULL', 'cancellation_reason'=>'TEXT NULL'] as $name=>$definition) {
        if (!isset($columns[$name])) $db->exec("ALTER TABLE leave_requests ADD COLUMN `$name` $definition");
    }
}

// Permanent record removal is separate from cancellation and keeps its audit evidence.
function hrDeleteCancelledLeave(PDO $db, array $user, int $id): void {
    if (!hrCanCancelApprovedLeave($user)) throw new DomainException('Only the portal Owner may delete cancelled leave.');
    if ($id < 1 || hrLeaveRecoveryLocked($db)) throw new DomainException('Leave deletion is unavailable.');
    $db->beginTransaction();
    try {
        $q = $db->prepare('SELECT * FROM leave_requests WHERE id=? FOR UPDATE');
        $q->execute([$id]); $request = $q->fetch(PDO::FETCH_ASSOC);
        if (!$request || $request['status'] !== 'cancelled') throw new DomainException('Only an existing cancelled request can be permanently deleted.');
        $runs = $db->prepare('SELECT id FROM payroll_runs WHERE period_year*100+period_month BETWEEN ? AND ? FOR UPDATE');
        $runs->execute([(int)date('Ym',strtotime($request['start_date'])),(int)date('Ym',strtotime($request['end_date']))]);
        if ($runs->fetchColumn()) throw new DomainException('Payroll exists for this period. Review payroll before deleting; nothing was changed.');
        $db->prepare("INSERT INTO audit_log(user_id,action,description,ip_address) VALUES (?,'cancelled_leave_deleted',?,?)")
            ->execute([(int)$user['id'],json_encode(['reason'=>'Owner confirmed permanent removal of cancelled leave.','request'=>$request,'portal_actor_id'=>(int)$user['portal_user_id']],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),(string)($_SERVER['REMOTE_ADDR']??'')]);
        $delete = $db->prepare("DELETE FROM leave_requests WHERE id=? AND status='cancelled'");
        $delete->execute([$id]);
        if ($delete->rowCount() !== 1) throw new DomainException('The request changed. Refresh and review it again.');
        // Cancelled records already have no effect on balances. Keep all prior audits,
        // notification history and files; never touch replacement requests or payroll.
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function hrCancelApprovedLeave(PDO $db, array $user, int $id, string $reason): array {
    if (!hrCanCancelApprovedLeave($user)) throw new DomainException('Only the signed-in portal Owner can cancel approved leave.');
    $reason = trim($reason);
    if ($id < 1 || $reason === '' || strlen($reason) > 2000) throw new DomainException('Select a leave request and enter a cancellation reason (maximum 2,000 bytes).');
    if (hrLeaveRecoveryLocked($db)) throw new DomainException('Leave changes are temporarily locked for verification.');
    $db->beginTransaction();
    try {
        $q = $db->prepare('SELECT * FROM leave_requests WHERE id=? FOR UPDATE');
        $q->execute([$id]); $request = $q->fetch(PDO::FETCH_ASSOC);
        if (!$request) throw new DomainException('Leave request not found.');
        if ($request['status'] !== 'approved') throw new DomainException('This request is no longer approved. No further cancellation was made.');
        // Lock overlapping periods too: payroll creates its run before generating payslips.
        // Even a draft requires payroll review; never silently rewrite a payslip.
        $periodStart = (int)date('Ym', strtotime($request['start_date']));
        $periodEnd = (int)date('Ym', strtotime($request['end_date']));
        $runs = $db->prepare('SELECT id FROM payroll_runs WHERE period_year*100+period_month BETWEEN ? AND ? FOR UPDATE');
        $runs->execute([$periodStart,$periodEnd]);
        if ($runs->fetchColumn()) throw new DomainException('Payroll already exists for a month covered by this leave. Review payroll before cancelling; no records were changed.');
        $update = $db->prepare("UPDATE leave_requests SET status='cancelled',cancelled_by=?,cancelled_at=NOW(),cancellation_reason=? WHERE id=? AND status='approved'");
        $update->execute([(int)$user['id'],$reason,$id]);
        if ($update->rowCount() !== 1) throw new DomainException('The leave request changed. Refresh and review it again.');
        $used = hrRefreshUsedLeave($db,(int)$request['employee_id'],$request['leave_type'],(int)date('Y',strtotime($request['start_date'])));
        $audit = ['request_id'=>$id,'employee_id'=>(int)$request['employee_id'],'leave_type'=>$request['leave_type'],
            'start_date'=>$request['start_date'],'end_date'=>$request['end_date'],'days'=>$request['days'],
            'previous_status'=>'approved','status'=>'cancelled','original_approved_by'=>$request['approved_by'],
            'original_approved_at'=>$request['approved_at'],'reason'=>$reason,'used_days_after'=>$used,
            'portal_actor_id'=>(int)$user['portal_user_id']];
        $db->prepare("INSERT INTO audit_log(user_id,action,description,ip_address) VALUES (?,'leave_cancelled',?,?)")
            ->execute([(int)$user['id'],json_encode($audit,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),(string)($_SERVER['REMOTE_ADDR']??'')]);
        $recipients = $db->prepare("SELECT id FROM users WHERE employee_id=? AND active=1 AND role='employee'");
        $recipients->execute([(int)$request['employee_id']]);
        foreach ($recipients->fetchAll(PDO::FETCH_COLUMN) as $recipient) {
            $db->prepare("INSERT INTO notifications(user_id,title,message,type,action_url) VALUES (?,?,?,'info',?)")
                ->execute([(int)$recipient,'Approved leave cancelled', $request['leave_type'].' for '.$request['start_date'].' to '.$request['end_date'].' was cancelled by the Owner. Reason: '.$reason,'my-leave.php?leave_request='.$id.'#leave-request-'.$id]);
        }
        $db->commit();
        return ['id'=>$id,'status'=>'cancelled'];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
