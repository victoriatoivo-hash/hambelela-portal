<?php
declare(strict_types=1);
// Included only by the existing owner-only Performance Settings page.
if (!isset($tab,$csrf) || $tab!=='settings') { http_response_code(404); exit; }
$timing=\Hambelela\EPI\ProvisionalTimingPolicy::current(db());
$timingValues=$timing['minutes']??array_combine(array_keys(\Hambelela\EPI\ProvisionalTimingPolicy::FIELDS),\Hambelela\EPI\ProvisionalTimingPolicy::APPROVED);
$timingEscape=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
$packingDeadlines=ops_rows("SELECT deadline_uuid,object_reference,obligation_key,due_at FROM epi_v2_operational_deadlines WHERE module='Packing List' AND state='open' AND breach_incident_uuid IS NULL AND due_at>NOW() AND JSON_EXTRACT(policy_snapshot_json,'$.responsibility_budget_minutes') IS NOT NULL ORDER BY due_at LIMIT 200");
?>
<style>
/* Keep the existing settings grid within the viewport; wide legacy tables scroll. */
#kpi-management{grid-template-columns:minmax(0,1fr)}
#kpi-management>.panel,#kpi-management>.section-row{min-width:0}
#kpi-management .form-grid{width:100%;max-width:850px}
#kpi-management .field{min-width:0}
#kpi-management .table-scroll{min-width:0;max-width:100%;overflow:auto}
.epi-timing-panel p{overflow-wrap:anywhere}
.epi-timing-panel button{justify-self:start;border:0;border-radius:7px;padding:9px 14px;background:#586944;color:white;font:inherit;cursor:pointer}
.epi-timing-panel button:hover,.epi-timing-panel button:focus-visible{background:#40522f}
</style>
<section class="panel epi-timing-panel" aria-labelledby="provisional-timing-heading">
    <div class="section-row"><div><p class="eyebrow">Provisional · responsibility-based</p><h2 id="provisional-timing-heading">Operational SLA timings</h2>
    <p>Monday–Friday 08:00–17:00 · Saturday 09:00–13:00 · Sunday paused · Namibia time. Clocks start at scheduled hours, not login.</p>
    <p>Only accepted, verified responsibility counts. A handover transfers the remaining allowance; an earlier breach stays with its original responsible employee. Unknown availability pauses scoring.</p>
    <p>Not approved for pay, bonuses or disciplinary action. <?= $timing?'Active from '.$timingEscape($timing['effective_from']).' · '.$timingEscape($timing['version']):'Not activated — defaults below are not yet active.' ?></p></div></div>
    <form method="post" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= $timingEscape($csrf) ?>"><input type="hidden" name="kpi_action" value="save_provisional_timings">
        <?php foreach (\Hambelela\EPI\ProvisionalTimingPolicy::FIELDS as $key=>$label): ?>
        <label class="field"><span><?= $timingEscape($label) ?> (working minutes)</span><input type="number" min="1" max="21600" step="1" name="<?= $timingEscape($key) ?>" value="<?= (int)$timingValues[$key] ?>" required></label>
        <?php endforeach; ?>
        <label class="field"><span>Reason for new version</span><input name="timing_reason" maxlength="500" required></label>
        <button class="btn-primary" type="submit">Save provisional version</button>
    </form>
    <p>First response is separate from a promised follow-up, which keeps its actual promised date/time. Cash starts at an explicit paid-cash receipt confirmation; automatic ticks and method edits do not qualify. Website timing starts when product and stock quantity information is available.</p>
    <p>These versioned timings control new V2 obligations. Older global/module settings below do not override them or rewrite existing deadlines.</p>
</section>
<section class="panel epi-timing-panel" aria-labelledby="packing-adjustment-heading">
    <div class="section-row"><div><h2 id="packing-adjustment-heading">Large or unusual Packing List job</h2><p>Record an extended deadline and reason before the current deadline expires. Existing breaches cannot be removed by extending a deadline.</p></div></div>
    <?php if (!$packingDeadlines): ?><p>No eligible future Packing List deadline currently needs adjustment.</p><?php else: ?>
    <form method="post" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= $timingEscape($csrf) ?>"><input type="hidden" name="kpi_action" value="adjust_packing_deadline">
        <label class="field"><span>Packing obligation</span><select name="deadline_uuid" required><?php foreach($packingDeadlines as $deadline): ?><option value="<?= $timingEscape($deadline['deadline_uuid']) ?>"><?= $timingEscape($deadline['object_reference'].' · '.str_replace('_',' ',$deadline['obligation_key']).' · '.$deadline['due_at']) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>Adjusted deadline (Namibia time)</span><input type="datetime-local" name="adjusted_due" required></label>
        <label class="field"><span>Reason</span><input name="adjustment_reason" maxlength="500" required></label>
        <button class="btn-primary" type="submit">Record adjustment</button>
    </form><?php endif; ?>
</section>
