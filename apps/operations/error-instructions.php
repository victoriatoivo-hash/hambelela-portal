<?php

declare(strict_types=1);

ob_start();
$errorInstructionResponseSent = false;
register_shutdown_function(static function (): void {
    global $errorInstructionResponseSent;
    if ($errorInstructionResponseSent) return;
    $lastError = error_get_last();
    if (!$lastError || !in_array((int)$lastError['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
    if (ob_get_level() > 0) ob_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok'=>false, 'message'=>'The instruction service encountered a server error. Retry or contact the owner.'], JSON_UNESCAPED_SLASHES);
});
require_once __DIR__ . '/operations.php';
require_once BASE_PATH . '/shared/error-instructions.php';

header('Content-Type: application/json; charset=UTF-8');

function error_instruction_response(array $payload, int $status = 200): void
{
    global $errorInstructionResponseSent;
    $errorInstructionResponseSent = true;
    if (ob_get_level() > 0) ob_clean();
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function error_instruction_payload(int $instructionId): array
{
    $stmt = db()->prepare("SELECT i.*, creator.full_name created_by_name, completer.full_name completed_by_name
        FROM ops_error_instructions i
        LEFT JOIN ops_employees creator ON creator.id=i.created_by_user_id
        LEFT JOIN ops_employees completer ON completer.id=i.completed_by_user_id
        WHERE i.id=? LIMIT 1");
    $stmt->execute([$instructionId]);
    $row = $stmt->fetch();
    $stmt->closeCursor();
    if (!is_array($row)) return [];
    return [
        'id'=>(int)$row['id'], 'error_id'=>(int)$row['error_id'],
        'instruction_text'=>(string)$row['instruction_text'],
        'created_by_name'=>(string)($row['created_by_name'] ?: 'Owner/Admin'),
        'created_at'=>(string)$row['created_at'], 'status'=>(string)($row['status'] ?: 'pending'),
        'completion_note'=>(string)($row['completion_note'] ?? ''),
        'completed_by_name'=>(string)($row['completed_by_name'] ?? ''),
        'completed_at'=>(string)($row['completed_at'] ?? ''),
    ];
}

try {
    if (!in_array(current_role_key(), ['owner_admin', 'front_desk_admin', 'front_desk_admin_employee'], true)) {
        error_instruction_response(['ok'=>false, 'message'=>'Your session expired or you do not have access to Error Log instructions.'], 403);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('Method not allowed.');
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    $sessionCsrf = (string) ($_SESSION['error_instruction_csrf_token'] ?? '');
    if ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
        error_instruction_response(['ok'=>false, 'message'=>'This instruction request expired. Refresh and retry.'], 419);
    }
    if (!error_instructions_schema_ready()) throw new RuntimeException('Owner Instructions are temporarily unavailable.');
    $action = trim((string) ($_POST['action'] ?? ''));
    $errorId = max(0, (int) ($_POST['error_id'] ?? 0));
    $error = error_instruction_error($errorId);
    if (!$error) error_instruction_response(['ok'=>false, 'message'=>'The error could not be found.'], 404);
    $userId = (int) (current_user()['id'] ?? 0);
    $roleKey = current_role_key();

    if ($action === 'send_instruction') {
        if ($roleKey !== 'owner_admin') error_instruction_response(['ok'=>false, 'message'=>'Only an owner/admin may send instructions.'], 403);
        if ((string) ($error['status'] ?? 'open') === 'resolved') {
            error_instruction_response(['ok'=>false, 'message'=>'Instructions can only be sent while the error is unresolved.'], 409);
        }
        $submissionToken = (string) ($_POST['submission_token'] ?? '');
        $sessionSubmissionToken = (string) ($_SESSION['error_instruction_submission_token'] ?? '');
        if ($submissionToken === '' || $sessionSubmissionToken === '' || !hash_equals($sessionSubmissionToken, $submissionToken)) {
            error_instruction_response(['ok'=>false, 'message'=>'This instruction was already sent. Refresh before sending another instruction.'], 409);
        }
        $instruction = trim((string) ($_POST['instruction_text'] ?? ''));
        if ($instruction === '') error_instruction_response(['ok'=>false, 'message'=>'Enter an instruction before sending.'], 422);
        if (function_exists('mb_strlen') ? mb_strlen($instruction) > 4000 : strlen($instruction) > 4000) {
            error_instruction_response(['ok'=>false, 'message'=>'The instruction must be 4,000 characters or fewer.'], 422);
        }
        $recipients = array_values(array_filter(error_instruction_front_person_ids($error), static fn(int $id): bool => $id > 0 && $id !== $userId));
        if (!$recipients) throw new RuntimeException('No active front person is available to receive this instruction.');

        db()->beginTransaction();
        try {
            $priorStmt = db()->prepare('SELECT COUNT(*) FROM ops_error_instructions WHERE error_id=?');
            $priorStmt->execute([$errorId]);
            $isFollowUp = (int) $priorStmt->fetchColumn() > 0;
            $priorStmt->closeCursor();
            $insert = db()->prepare('INSERT INTO ops_error_instructions (error_id,instruction_text,created_by_user_id,created_at) VALUES (?,?,?,NOW())');
            $insert->execute([$errorId, $instruction, $userId]);
            $instructionId = (int) db()->lastInsertId();
            $notificationId = notifications_create([
                'title'=>'Owner instruction received',
                'message'=>'An instruction has been added to unresolved error #' . $errorId . '.',
                'module'=>'errors',
                'related_type'=>'error_instruction',
                'related_id'=>$errorId,
                'priority'=>'important',
                'required_delivery'=>true,
                'deduplication_key'=>'error-instruction:' . $instructionId,
                'action_link'=>BASE_URL . '/apps/operations/errors.php?error_id=' . $errorId . '&instruction=1#owner-instructions-' . $errorId,
            ], $recipients);
            if (!$notificationId) throw new RuntimeException('The front-person notification could not be created.');
            $readInsert = db()->prepare('INSERT INTO ops_error_instruction_reads (instruction_id,recipient_user_id,notification_id) VALUES (?,?,?)');
            foreach ($recipients as $recipientId) $readInsert->execute([$instructionId, $recipientId, $notificationId]);
            ops_activity_log($isFollowUp ? 'error_owner_follow_up_instruction_sent' : 'error_owner_instruction_sent', 'error_log', $errorId, [
                'instruction_id'=>$instructionId,
                'actor_user_id'=>$userId,
                'recipient_user_ids'=>$recipients,
                'new_value'=>$instruction,
            ]);
            db()->commit();
            $_SESSION['error_instruction_submission_token'] = bin2hex(random_bytes(32));
        } catch (Throwable $errorDuringSend) {
            if (db()->inTransaction()) db()->rollBack();
            throw $errorDuringSend;
        }
        error_instruction_response(['ok'=>true, 'message'=>'Instruction sent successfully.', 'error_id'=>$errorId,
            'instruction'=>error_instruction_payload($instructionId),
            'submission_token'=>(string)$_SESSION['error_instruction_submission_token']]);
    }

    if ($action === 'complete_instruction') {
        if ($roleKey === 'owner_admin' || !error_instruction_user_can_view($error, $userId, $roleKey)) {
            error_instruction_response(['ok'=>false, 'message'=>'Only the assigned employee may complete this instruction.'], 403);
        }
        $instructionId = max(0, (int)($_POST['instruction_id'] ?? 0));
        $completionNote = trim((string)($_POST['completion_note'] ?? ''));
        if ($completionNote === '') error_instruction_response(['ok'=>false, 'message'=>'Enter a completion note before marking this instruction complete.'], 422);
        $noteLength = function_exists('mb_strlen') ? mb_strlen($completionNote) : strlen($completionNote);
        if ($noteLength < 10) error_instruction_response(['ok'=>false, 'message'=>'The completion note must be at least 10 characters.'], 422);
        if ($noteLength > 4000) error_instruction_response(['ok'=>false, 'message'=>'The completion note must be 4,000 characters or fewer.'], 422);

        db()->beginTransaction();
        try {
            $instructionStmt = db()->prepare("SELECT i.id,i.status FROM ops_error_instructions i
                JOIN ops_error_instruction_reads r ON r.instruction_id=i.id AND r.recipient_user_id=?
                WHERE i.id=? AND i.error_id=? FOR UPDATE");
            $instructionStmt->execute([$userId, $instructionId, $errorId]);
            $instructionRow = $instructionStmt->fetch();
            $instructionStmt->closeCursor();
            if (!is_array($instructionRow)) throw new RuntimeException('This instruction is not assigned to you.');
            if ((string)$instructionRow['status'] === 'completed') {
                db()->rollBack();
                error_instruction_response(['ok'=>false, 'message'=>'This instruction has already been completed.'], 409);
            }
            $update = db()->prepare("UPDATE ops_error_instructions SET status='completed', completed_by_user_id=?, completion_note=?, completed_at=NOW(), updated_at=NOW() WHERE id=? AND status='pending'");
            $update->execute([$userId, $completionNote, $instructionId]);
            if ($update->rowCount() !== 1) throw new RuntimeException('The instruction could not be completed. Refresh and retry.');
            $ownerRecipients = notifications_role_recipients(['owner_admin']);
            if ($ownerRecipients) notifications_create([
                'title'=>'Owner instruction completed',
                'message'=>'Instruction #' . $instructionId . ' on error #' . $errorId . ' was completed.',
                'module'=>'errors', 'related_type'=>'error_instruction', 'related_id'=>$errorId,
                'priority'=>'important', 'required_delivery'=>true,
                'deduplication_key'=>'error-instruction-completed:' . $instructionId,
                'action_link'=>BASE_URL . '/apps/operations/errors.php?error_id=' . $errorId . '&instruction=1#owner-instructions-' . $errorId,
            ], $ownerRecipients);
            ops_activity_log('error_owner_instruction_completed', 'error_log', $errorId, [
                'instruction_id'=>$instructionId, 'completed_by_user_id'=>$userId, 'completion_note'=>$completionNote,
            ]);
            db()->commit();
        } catch (Throwable $completionError) {
            if (db()->inTransaction()) db()->rollBack();
            throw $completionError;
        }
        error_instruction_response(['ok'=>true, 'message'=>'Instruction completed successfully.', 'error_id'=>$errorId,
            'instruction'=>error_instruction_payload($instructionId)]);
    }

    if ($action === 'mark_read') {
        if (!error_instruction_user_can_view($error, $userId, $roleKey)) {
            error_instruction_response(['ok'=>false, 'message'=>'You do not have access to this instruction.'], 403);
        }
        if ($roleKey === 'owner_admin') error_instruction_response(['ok'=>true, 'unread'=>0]);
        $stmt = db()->prepare("UPDATE ops_error_instruction_reads r
            JOIN ops_error_instructions i ON i.id=r.instruction_id
            SET r.read_at=COALESCE(r.read_at,NOW())
            WHERE i.error_id=? AND r.recipient_user_id=? AND r.read_at IS NULL");
        $stmt->execute([$errorId, $userId]);
        $marked = $stmt->rowCount();
        if ($marked > 0) {
            db()->prepare("UPDATE notification_recipients nr
                JOIN ops_error_instruction_reads r ON r.notification_id=nr.notification_id AND r.recipient_user_id=nr.employee_id
                JOIN ops_error_instructions i ON i.id=r.instruction_id
                SET nr.read_at=COALESCE(nr.read_at,NOW())
                WHERE i.error_id=? AND nr.employee_id=?")->execute([$errorId, $userId]);
            ops_activity_log('error_owner_instruction_viewed', 'error_log', $errorId, [
                'recipient_user_id'=>$userId,
                'instruction_count'=>$marked,
            ]);
        }
        error_instruction_response(['ok'=>true, 'unread'=>0, 'marked'=>$marked]);
    }

    error_instruction_response(['ok'=>false, 'message'=>'Unknown instruction action.'], 400);
} catch (Throwable $error) {
    error_log('Owner instruction request failed: ' . $error->getMessage());
    error_instruction_response(['ok'=>false, 'message'=>$error->getMessage() ?: 'Unable to process the instruction.'], 500);
}

