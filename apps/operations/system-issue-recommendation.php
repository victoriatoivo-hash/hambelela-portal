<?php
declare(strict_types=1);

require_once __DIR__.'/operations.php';
require_once BASE_PATH.'/shared/system-issues.php';
require_login();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function system_issue_recommendation_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Method not allowed.');
    }

    system_issue_verify_csrf((string) ($_POST['csrf'] ?? ''));
    if (!system_issue_is_owner()) {
        throw new RuntimeException('Permission denied.');
    }

    $issueId = max(0, (int) ($_POST['issue_id'] ?? 0));
    if ($issueId < 1) {
        throw new RuntimeException('Issue ID was missing.');
    }

    $currentUserId = (int) (current_user()['id'] ?? 0);
    $issue = system_issue_find_visible($issueId, $currentUserId, true);
    if (!$issue) {
        system_issue_log_access_denied($issueId, 'recommendation.ajax');
        throw new RuntimeException('Issue not found.');
    }

    $action = ops_post_string('action', 40);
    if ($action === 'save_owner_recommendation') {
        $text = ops_post_string('owner_recommendation', 6000);
        $recommendationId = system_issue_save_owner_recommendation($issueId, $text);
        $saved = ops_rows(
            'SELECT r.*,e.full_name created_by_name FROM system_issue_owner_recommendations r LEFT JOIN ops_employees e ON e.id=r.created_by WHERE r.id=? AND r.issue_id=? LIMIT 1',
            [$recommendationId, $issueId]
        )[0] ?? null;
        if (!$saved) {
            throw new RuntimeException('Recommendation could not be saved.');
        }
        system_issue_recommendation_json([
            'ok' => true,
            'issue_id' => $issueId,
            'recommendation_id' => $recommendationId,
            'recommendation' => (string) $saved['recommendation_text'],
            'saved_by' => (string) ($saved['created_by_name'] ?? 'Owner'),
            'saved_at' => (string) $saved['created_at'],
            'updated_at' => (string) ($saved['updated_at'] ?? $saved['created_at']),
            'message' => 'Recommendation saved.',
        ]);
    }

    if ($action === 'update_ai_brief') {
        $submitted = trim(ops_post_string('owner_recommendation', 6000));
        $latest = ops_rows(
            'SELECT * FROM system_issue_owner_recommendations WHERE issue_id=? ORDER BY id DESC LIMIT 1',
            [$issueId]
        )[0] ?? null;
        if (!$latest) {
            throw new RuntimeException('Save an owner recommendation before updating the AI brief.');
        }
        if ($submitted !== trim((string) $latest['recommendation_text'])) {
            throw new RuntimeException('Save the latest recommendation before updating the AI brief.');
        }

        $result = system_issue_regenerate_brief($issueId);
        if (empty($result['ok'])) {
            system_issue_recommendation_json([
                'ok' => false,
                'issue_id' => $issueId,
                'recommendation_saved' => true,
                'message' => (string) ($result['message'] ?? 'Recommendation saved, but AI Brief update failed.'),
            ], 502);
        }

        $briefRow = ops_rows(
            'SELECT * FROM system_issue_ai_briefs WHERE issue_id=? AND is_current=1 AND ai_brief_json IS NOT NULL ORDER BY version_number DESC,id DESC LIMIT 1',
            [$issueId]
        )[0] ?? null;
        $brief = $briefRow ? json_decode((string) $briefRow['ai_brief_json'], true) : null;
        if (!$briefRow || !is_array($brief)) {
            throw new RuntimeException('AI Brief updated, but the new brief could not be loaded.');
        }
        system_issue_recommendation_json([
            'ok' => true,
            'issue_id' => $issueId,
            'brief_version' => (int) $briefRow['version_number'],
            'brief' => $brief,
            'risk_level' => (string) ($briefRow['ai_risk_level'] ?? $issue['ai_risk_level'] ?? 'high'),
            'updated_at' => (string) $briefRow['created_at'],
            'message' => 'AI Brief updated.',
        ]);
    }

    throw new RuntimeException('Unsupported recommendation action.');
} catch (Throwable $error) {
    $message = trim($error->getMessage()) ?: 'Server error while saving.';
    $status = $message === 'Permission denied.' ? 403 : ($message === 'Method not allowed.' ? 405 : 422);
    system_issue_recommendation_json(['ok' => false, 'message' => $message], $status);
}
