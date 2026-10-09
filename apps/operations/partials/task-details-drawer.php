<?php
$panelAccess = checklist_detail_access($task, $canManage, (int) $currentEmployeeId);
if (!$panelAccess['can_view']) return;
$panelScheduled = !empty($task['scheduled_at']) && empty($task['released_at']);
$panelPriority = $priorities[$task['priority'] ?? 'normal'] ?? 'Normal';
$panelStatusLabel = $statuses[$panelSavedStatus] ?? ucfirst(str_replace('_', ' ', $panelSavedStatus));
$panelTypeLabel = ucfirst($taskKind);
$panelChecklistCount = count(array_intersect($items, $checked));
$panelProofFiles = $attachmentsByTask[$panelId] ?? [];
$panelProofIds = array_map(static function ($file) { return (int)$file['id']; }, array_filter($panelProofFiles, static function ($file) use ($task) {
    return empty($task['active_correction_id']) || empty($task['correction_require_new_proof']) || ((int)($file['correction_cycle_id'] ?? 0) === (int)$task['active_correction_id'] && (int)($file['uploaded_by'] ?? 0) === (int)$task['assigned_employee_id']);
}));
?>
<aside class="<?= $canManage ? 'task-admin-detail-panel' : 'task-detail-panel' ?> task-edit-drawer task-details-drawer hb-task-details" data-task-panel="<?= $panelId ?>" data-can-work="<?= $panelAccess['can_work'] ? '1' : '0' ?>" data-can-start="<?= $panelAccess['can_start'] ? '1' : '0' ?>" data-deadline-state="<?= htmlspecialchars((string)$panelDueState['value'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="task-detail-title-<?= $panelId ?>">
    <header class="task-edit-header task-detail-heading td-header td-header-row">
        <div class="task-detail-heading__copy">
            <span class="task-edit-eyebrow td-eyebrow">TASK MANAGEMENT</span>
            <h2 class="task-edit-title td-heading" id="task-detail-title-<?= $panelId ?>">Task Details</h2>
            <p class="task-edit-subtitle td-task-name" data-task-admin-title><?= htmlspecialchars(checklist_display_task_title((string)$task['task_name']), ENT_QUOTES, 'UTF-8') ?></p>
            <div class="task-details-badges td-badges">
                <span class="task-details-badge task-details-badge--status" data-status="<?= htmlspecialchars($panelSavedStatus, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($panelStatusLabel, ENT_QUOTES, 'UTF-8') ?></span>
                <span class="task-details-badge task-details-badge--priority" data-priority="<?= htmlspecialchars((string)($task['priority'] ?? 'normal'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($panelPriority, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>
        <div class="task-edit-header-actions td-header-actions">
            <?php if ($canManage): ?><button type="button" class="task-edit-btn td-btn task-edit-btn--secondary td-btn-secondary" data-task-admin-edit>Edit Task</button><?php endif; ?>
            <button type="button" class="task-detail-close" data-task-close aria-label="Close task details"><i data-lucide="x" aria-hidden="true"></i></button>
        </div>
    </header>
    <div class="task-edit-body td-body" id="task-details-<?= $panelId ?>">
        <?php if ($canManage) task_admin_render($task,$employees,$taskAttachmentCsrf); ?>
        <div class="td-content-stack" data-task-admin-view>
            <section class="task-details-section td-card task-detail-summary" aria-label="Task summary">
                <dl class="task-detail-summary__grid td-summary-grid">
                    <div class="td-summary-item"><dt class="td-summary-label">Assigned To</dt><dd class="td-summary-value"><?= htmlspecialchars((string)($task['assigned_name'] ?? 'Unassigned'), ENT_QUOTES, 'UTF-8') ?></dd></div>
                    <div class="td-summary-item"><dt class="td-summary-label">Priority</dt><dd class="td-summary-value"><?= htmlspecialchars($panelPriority, ENT_QUOTES, 'UTF-8') ?></dd></div>
                    <div class="td-summary-item"><dt class="td-summary-label">Due Date</dt><dd class="td-summary-value"><?= htmlspecialchars(checklist_date_label((string)($task['deadline'] ?? '')), ENT_QUOTES, 'UTF-8') ?></dd></div>
                    <div class="td-summary-item"><dt class="td-summary-label">Task Type</dt><dd class="td-summary-value"><?= htmlspecialchars($panelTypeLabel, ENT_QUOTES, 'UTF-8') ?></dd></div>
                    <div class="td-summary-item"><dt class="td-summary-label">Status</dt><dd class="td-summary-value" data-task-summary-status><?= htmlspecialchars($panelStatusLabel, ENT_QUOTES, 'UTF-8') ?></dd></div>
                    <?php if ($panelSavedStatus === 'complete'): ?><div class="td-summary-item"><dt class="td-summary-label">Completed By</dt><dd class="td-summary-value"><?= htmlspecialchars((string)($task['completed_by_name'] ?: 'Not recorded'), ENT_QUOTES, 'UTF-8') ?><small><?= htmlspecialchars(checklist_date_label((string)($task['date_completed'] ?: $task['completed_at'])), ENT_QUOTES, 'UTF-8') ?></small></dd></div><?php endif; ?>
                </dl>
            </section>
            <?php if ($canManage && $panelScheduled): ?>
            <section class="task-details-section td-card task-detail-scheduled td-scheduled">
                <div class="task-detail-scheduled__heading"><span class="task-detail-icon"><i data-lucide="clock-3" aria-hidden="true"></i></span><div><h3 class="td-card-heading">Scheduled Release</h3><p><?= htmlspecialchars(checklist_date_label((string)$task['scheduled_at']), ENT_QUOTES, 'UTF-8') ?></p></div></div>
                <p class="task-detail-helper td-scheduled-description">This task will become visible to its assigned employee when released.</p>
                <div class="task-detail-scheduled__actions td-scheduled-actions">
                    <form method="post"><input type="hidden" name="action" value="release_scheduled_task"><input type="hidden" name="task_id" value="<?= $panelId ?>"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($taskAttachmentCsrf, ENT_QUOTES, 'UTF-8') ?>"><button class="task-edit-btn td-btn task-edit-btn--primary td-btn-primary" type="submit"><i data-lucide="play" aria-hidden="true"></i>Release Now</button></form>
                    <form method="post" onsubmit="return confirm('Cancel this scheduled task?');"><input type="hidden" name="action" value="cancel_scheduled_task"><input type="hidden" name="task_id" value="<?= $panelId ?>"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($taskAttachmentCsrf, ENT_QUOTES, 'UTF-8') ?>"><button class="task-edit-btn td-btn task-detail-btn--danger td-btn-danger" type="submit">Cancel Scheduled Task</button></form>
                </div>
            </section>
            <?php endif; ?>
            <section class="task-details-section td-card task-detail-instructions">
                <h3 class="td-card-heading"><i data-lucide="file-text" aria-hidden="true"></i>Instructions</h3>
                <div class="task-detail-instructions__content td-instructions" data-readonly-instructions><?= checklist_render_instructions((string)($task['instructions'] ?: $task['notes'] ?: '')) ?></div>
            </section>
            <form method="post" enctype="multipart/form-data" class="task-detail-work-form" id="task-work-form-<?= $panelId ?>" <?= $panelAccess['can_work'] ? 'data-task-progress-form' : '' ?> data-task-id="<?= $panelId ?>" data-proof-required="<?= !empty($task['completion_evidence_required']) ? '1' : '0' ?>" data-proof-ids="<?= htmlspecialchars(json_encode(array_values($panelProofIds)), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="task_id" value="<?= $panelId ?>"><input type="hidden" name="status" value="complete">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($taskAttachmentCsrf, ENT_QUOTES, 'UTF-8') ?>">
                <div class="task-completion-error" data-task-completion-error role="alert" hidden><strong>This task cannot be completed yet.</strong><span data-task-completion-error-message></span></div>
                <section class="task-details-section td-card task-detail-checklist">
                    <div class="task-detail-section-heading"><h3 class="td-card-heading"><i data-lucide="list-checks" aria-hidden="true"></i>Checklist</h3><span class="task-detail-helper" data-task-checklist-count><?= $panelChecklistCount ?> / <?= count($items) ?> complete</span></div>
                    <div class="task-detail-checklist__rows td-checklist">
                    <?php foreach ($items as $item): $itemComplete = in_array($item,$checked,true); ?>
                        <label class="task-detail-checklist__item td-check-row<?= $itemComplete ? ' is-complete' : '' ?>" data-required-checklist-item><input class="td-check-input" type="checkbox" name="checked_items[]" value="<?= htmlspecialchars($item, ENT_QUOTES, 'UTF-8') ?>" <?= $itemComplete ? 'checked' : '' ?> <?= !$panelAccess['can_work'] ? 'disabled' : '' ?>><span class="task-detail-checklist__label td-check-text"><?= htmlspecialchars($item, ENT_QUOTES, 'UTF-8') ?></span><small class="td-check-required">Required</small></label>
                    <?php endforeach; ?>
                    <?php if (!$items): ?><p class="task-detail-helper">No checklist items added.</p><?php endif; ?>
                    </div>
                    <?php if ($panelAccess['can_start']): ?><p class="task-detail-helper">Start Task to record your time and work through the checklist.</p><?php elseif ($canManage): ?><p class="task-detail-helper">Employee checklist progress is shown here. Use Edit Task to record a management correction.</p><?php endif; ?>
                </section>
                <?php if ($panelAccess['can_work']): ?>
                <section class="task-details-section td-card task-detail-completion">
                    <h3 class="td-card-heading"><?= $activeCorrection ? 'Correction completion note' : 'Completion note' ?></h3>
                    <label class="task-detail-field-label" for="task-progress-note-<?= $panelId ?>">Describe the work completed <span aria-hidden="true">*</span></label>
                    <textarea id="task-progress-note-<?= $panelId ?>" name="completion_note" required minlength="5" maxlength="1000" data-completion-note placeholder="<?= $activeCorrection ? 'Explain exactly what you corrected.' : 'Explain what was completed.' ?>"><?= htmlspecialchars((string)($task['completion_note'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                    <p class="task-detail-helper">Complete every required checklist item<?= !empty($task['completion_evidence_required']) ? ' and upload the required proof' : '' ?> before finishing.</p>
                    <p data-task-note-error role="alert" hidden>Enter a completion note before saving.</p>
                </section>
                <?php elseif (!empty($task['completion_note'])): ?>
                <section class="task-details-section td-card"><h3 class="td-card-heading"><?= $panelSavedStatus === 'complete' ? 'Completion note' : 'Latest progress note' ?></h3><p class="task-detail-note"><?= htmlspecialchars((string)$task['completion_note'], ENT_QUOTES, 'UTF-8') ?></p></section>
                <?php endif; ?>
            </form>
                <?php
                $panelAttachments = $attachmentsByTask[$panelId] ?? [];
                $taskIsComplete = checklist_normalize_status((string) ($task['status'] ?? 'pending')) === 'complete';
                $taskAcceptsFiles = empty($task['archived_at']) && empty($task['deleted_at']) && ($canManage || $panelAccess['can_work']);
                ?>
                <section class="task-details-section td-card task-files" data-task-files data-task-id="<?= $panelId ?>" data-csrf-token="<?= htmlspecialchars($taskAttachmentCsrf, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="task-files__heading"><div><h3 class="td-card-heading">Files / proof</h3><p>Upload a photo, document or other proof of work.</p></div><?php if ($taskAcceptsFiles): ?><button type="button" class="task-files__add" data-add-task-file><i data-lucide="paperclip" aria-hidden="true"></i><span>Add photo or file</span></button><?php endif; ?></div>
                    <?php if ($taskAcceptsFiles): ?><input type="file" data-task-file-input multiple hidden accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.xls,.xlsx,.mp4"><?php endif; ?>
                    <p class="task-files__error" data-task-files-error hidden></p>
                    <div class="task-files__list" data-task-file-list>
                        <?php foreach ($panelAttachments as $attachment): ?>
                            <?php $canRemoveAttachment = $canManage || ($panelAccess['can_work'] && (int) ($attachment['uploaded_by'] ?? 0) === (int) $currentEmployeeId); $attachmentPayload = checklist_attachment_payload($attachment, $canRemoveAttachment); ?>
                            <article class="task-file" data-task-attachment-id="<?= (int) $attachment['id'] ?>"><a class="task-file__thumbnail" href="<?= htmlspecialchars($attachmentPayload['viewUrl'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?php if (strpos((string) $attachment['mime_type'], 'image/') === 0): ?><img src="<?= htmlspecialchars($attachmentPayload['viewUrl'], ENT_QUOTES, 'UTF-8') ?>" alt=""><?php else: ?><i data-lucide="file-text" aria-hidden="true"></i><?php endif; ?></a><div class="task-file__information"><div class="task-file__name"><?= htmlspecialchars((string) $attachment['original_filename'], ENT_QUOTES, 'UTF-8') ?></div><div class="task-file__meta"><?= number_format(((int) $attachment['file_size']) / 1024, 1) ?> KB · <?= htmlspecialchars((string) $attachment['uploaded_by_name'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(checklist_date_label((string) $attachment['created_at']), ENT_QUOTES, 'UTF-8') ?></div></div><div class="task-file__actions"><a class="task-file__action" href="<?= htmlspecialchars($attachmentPayload['viewUrl'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">View</a><a class="task-file__action" href="<?= htmlspecialchars($attachmentPayload['downloadUrl'], ENT_QUOTES, 'UTF-8') ?>">Download</a><?php if ($canRemoveAttachment): ?><button type="button" class="task-file__action" data-remove-task-file>Remove</button><?php endif; ?></div></article>
                        <?php endforeach; ?>
                        <?php if (!$panelAttachments && !empty($task['photo_path'])): ?><article class="task-file task-file--legacy"><span class="task-file__thumbnail"><i data-lucide="image" aria-hidden="true"></i></span><div class="task-file__information"><div class="task-file__name">Legacy photo proof</div><div class="task-file__meta">Previously uploaded evidence</div></div><div class="task-file__actions"><a class="task-file__action" href="<?= BASE_URL ?>/apps/operations/task-proof.php?task_id=<?= $panelId ?>" target="_blank" rel="noopener">View</a></div></article><?php endif; ?>
                    </div>
                    <p class="task-files__empty" data-task-files-empty <?= ($panelAttachments || !empty($task['photo_path'])) ? 'hidden' : '' ?>>No files uploaded yet.</p>
                </section>
                <?php if ($activeCorrection): ?><section class="task-correction-banner"><div class="task-correction-banner__icon"><i data-lucide="rotate-ccw" aria-hidden="true"></i></div><div><span>Correction required · Round <?= (int)$activeCorrection['correction_round'] ?></span><h3 class="td-card-heading"><?= htmlspecialchars((string)$activeCorrection['message'],ENT_QUOTES,'UTF-8') ?></h3><p>Due <?= htmlspecialchars(checklist_date_label((string)$activeCorrection['correction_due_at']),ENT_QUOTES,'UTF-8') ?><?= !empty($activeCorrection['require_new_proof'])?' · New proof required':'' ?></p><?php if(!$canManage):?><small>When finished, describe the correction in the Correction completion note below, then submit it.</small><?php endif;?></div></section><?php endif; ?>
                <?php if ($canManage && $panelSavedStatus==='complete' && !$activeCorrection): ?><form method="post" enctype="multipart/form-data" class="task-details-section td-card task-correction-card" data-task-correction-form><input type="hidden" name="action" value="request_task_correction"><input type="hidden" name="task_id" value="<?= $panelId ?>"><header class="task-correction-card__heading"><span><i data-lucide="rotate-ccw" aria-hidden="true"></i></span><div><h3 class="td-card-heading">Request correction</h3><p>Reopen this completed task for the assigned employee. The original completion remains in its audit history.</p></div></header><label>What needs correcting<textarea name="correction_message" required minlength="5" maxlength="1000"></textarea></label><label>Correction due date and time<input type="datetime-local" name="correction_due_at" required></label><label class="task-correction-check"><input type="checkbox" name="require_new_proof" value="1"> Require new proof for this correction</label><label class="task-correction-file"><span>Supporting file (optional)</span><input type="file" name="correction_attachment" accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.xls,.xlsx"></label><p data-task-correction-error hidden></p><button class="task-btn task-btn--primary" type="submit"><i data-lucide="send" aria-hidden="true"></i><span>Request correction</span></button></form><?php endif; ?>
                <?php if ($canManage && $activeCorrection): ?><section class="task-details-section td-card task-correction-card task-correction-card--edit"><form method="post" data-task-correction-form><input type="hidden" name="action" value="update_task_correction"><input type="hidden" name="task_id" value="<?= $panelId ?>"><header class="task-correction-card__heading"><span><i data-lucide="message-square-pen" aria-hidden="true"></i></span><div><h3 class="td-card-heading">Edit correction request</h3><p>Update the instructions or deadline sent to the assigned employee.</p></div></header><label>Correction message<textarea name="correction_message" required minlength="5"><?= htmlspecialchars((string)$activeCorrection['message'],ENT_QUOTES,'UTF-8') ?></textarea></label><div class="task-correction-card__options"><label>Correction due date and time<input type="datetime-local" name="correction_due_at" value="<?= htmlspecialchars(substr((string)$activeCorrection['correction_due_at'],0,16),ENT_QUOTES,'UTF-8') ?>" required></label><label class="task-correction-proof-toggle"><input type="checkbox" name="require_new_proof" value="1" <?= !empty($activeCorrection['require_new_proof'])?'checked':'' ?>><span class="task-correction-proof-toggle__track"><span></span></span><span class="task-correction-proof-toggle__copy"><strong>Require new proof</strong><small>Employee must upload fresh evidence.</small></span></label></div><p data-task-correction-error hidden></p><div class="task-correction-actions"><button class="task-btn task-btn--primary task-correction-save" type="submit"><i data-lucide="save" aria-hidden="true"></i><span>Save correction</span></button></div></form><details class="task-correction-cancel"><summary><i data-lucide="circle-x" aria-hidden="true"></i><span>Cancel this correction</span><i data-lucide="chevron-down" aria-hidden="true"></i></summary><form method="post" data-task-correction-form data-correction-cancel><input type="hidden" name="action" value="cancel_task_correction"><input type="hidden" name="task_id" value="<?= $panelId ?>"><label>Cancellation reason<textarea name="cancel_reason" required minlength="5" placeholder="Explain why this correction is being cancelled."></textarea></label><p data-task-correction-error hidden></p><div class="task-correction-actions"><button class="task-btn task-btn--danger" type="submit"><span>Confirm cancellation</span></button></div></form></details></section><?php endif; ?>
                <?php if ($panelCorrections): ?><details class="task-details-section td-card task-correction-history"><summary>Correction history · <?= count($panelCorrections) ?> round<?= count($panelCorrections)===1?'':'s' ?></summary><?php foreach ($panelCorrections as $cycle): $snapshot=json_decode((string)($cycle['completion_snapshot_json']??'{}'),true)?:[]; ?><article><strong>Round <?= (int)$cycle['correction_round'] ?> · <?= htmlspecialchars(ucfirst((string)$cycle['status']),ENT_QUOTES,'UTF-8') ?></strong><p><?= htmlspecialchars((string)$cycle['message'],ENT_QUOTES,'UTF-8') ?></p><?php if(trim((string)($cycle['employee_completion_note']??''))!==''):?><div class="task-correction-history__response"><span>Employee correction note</span><p><?= htmlspecialchars((string)$cycle['employee_completion_note'],ENT_QUOTES,'UTF-8') ?></p></div><?php endif;?><small>Previous completion: <?= htmlspecialchars(checklist_date_label((string)($snapshot['completed_at']??'')),ENT_QUOTES,'UTF-8') ?> · <?= htmlspecialchars((string)($snapshot['completion_note']??'No note'),ENT_QUOTES,'UTF-8') ?></small></article><?php endforeach; ?></details><?php endif; ?>

            <?php if ($canManage): ?>
            <details class="task-details-section td-card task-detail-audit" data-task-audit-disclosure><summary><i data-lucide="history" aria-hidden="true"></i>Task audit history<i data-lucide="chevron-down" aria-hidden="true"></i></summary><div data-task-details-audits><p class="task-detail-helper">Open to load original assignment and management corrections.</p></div></details>
            <details class="task-details-section td-card task-detail-management"><summary><i data-lucide="settings-2" aria-hidden="true"></i>Management actions<i data-lucide="chevron-down" aria-hidden="true"></i></summary><div class="task-detail-management__body">
                <button type="button" class="task-edit-btn td-btn task-edit-btn--secondary td-btn-secondary" data-save-task-template="<?= $panelId ?>">Save as template</button>
                <?php if ($taskKind === 'recurring'): ?><form method="post"><input type="hidden" name="action" value="task_cancel_recurrence"><input type="hidden" name="task_id" value="<?= $panelId ?>"><button class="task-edit-btn td-btn task-detail-btn--danger td-btn-danger" type="submit">Stop future recurrence</button><p class="task-detail-helper">The current task stays available; no new copies will be created.</p></form><?php endif; ?>
            </div></details>
            <?php endif; ?>
        </div>
    </div>
    <footer class="task-edit-footer td-footer task-detail-footer" data-task-detail-footer>
        <button type="button" class="task-edit-btn td-btn task-edit-btn--secondary td-btn-secondary" data-task-close>Close</button>
        <?php if ($canManage): ?><button type="button" class="task-edit-btn td-btn task-edit-btn--primary td-btn-primary" data-task-admin-edit>Edit Task</button>
        <?php elseif ($panelAccess['can_start']): ?><button type="button" class="task-edit-btn td-btn task-edit-btn--primary td-btn-primary" data-task-detail-start>Start Task</button>
        <?php elseif ($panelAccess['can_work']): ?><button type="submit" form="task-work-form-<?= $panelId ?>" name="action" value="update_task_progress" class="task-edit-btn td-btn task-edit-btn--primary td-btn-primary" data-save-task disabled>Complete Task</button><?php endif; ?>
    </footer>
    <?php if ($canManage): ?><footer class="task-edit-footer td-footer" data-task-admin-footer hidden><button type="button" class="task-edit-btn td-btn task-edit-btn--secondary td-btn-secondary" data-task-admin-cancel>Cancel</button><button type="submit" form="task-admin-form-<?= $panelId ?>" class="task-edit-btn td-btn task-edit-btn--primary td-btn-primary" data-task-admin-save>Save Changes</button></footer><?php endif; ?>
</aside>
