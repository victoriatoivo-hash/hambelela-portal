<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';
require_once BASE_PATH . '/shared/error-instructions.php';

require_role('owner_admin', 'front_desk_admin', 'front_desk_admin_employee');
header('Content-Type: application/json; charset=UTF-8');

function error_instruction_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

try {
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
            unset($_SESSION['error_instruction_submission_token']);
        } catch (Throwable $errorDuringSend) {
            if (db()->inTransaction()) db()->rollBack();
            throw $errorDuringSend;
        }
        error_instruction_response(['ok'=>true, 'message'=>'Instruction sent successfully.', 'error_id'=>$errorId]);
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

