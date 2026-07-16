<?php

declare(strict_types=1);

$reviewRows = $ready ? kpi_performance_reviews($period) : [];
$eligibleCount = count(array_filter($employeeScores, static fn (array $row): bool => (float) $row['score'] >= 75));
$pendingCount = count(array_filter($employeeScores, static fn (array $row): bool => in_array((string) ($row['bonus_status'] ?? ''), ['Eligible', 'Pending Review'], true)));
$approvedCount = count(array_filter($employeeScores, static fn (array $row): bool => in_array((string) ($row['bonus_status'] ?? ''), ['Approved', 'Paid'], true)));
$atRiskCount = count(array_filter($employeeScores, static fn (array $row): bool => (float) $row['score'] < 75));
$topScore = $employeeScores[0] ?? null;
$weights = kpi_role_weights();
?>
<main class="workspace module kpi-page" data-kpi-root>
    <header class="kpi-page-head">
        <div>
            <p class="kpi-eyebrow">Performance Management</p>
            <h1>KPI &amp; Performance</h1>
            <p>Live employee scorecards, evidence, reviews and owner-controlled bonus decisions.</p>
        </div>
        <?php if ($kpiIsOwner): ?>
            <div class="kpi-head-actions">
                <button class="kpi-btn kpi-btn--ghost" type="button" data-kpi-open-settings><i data-lucide="sliders-horizontal"></i> Settings</button>
                <form method="post" data-kpi-async>
                    <input type="hidden" name="kpi_action" value="refresh_scores">
                    <button class="kpi-btn" type="submit"><i data-lucide="refresh-cw"></i> Refresh scores</button>
                </form>
            </div>
        <?php endif; ?>
    </header>

    <?php if (!$ready) { ops_setup_notice(); } ?>
    <?php ops_flash($message, $messageType); ?>

    <section class="kpi-filter-panel" aria-label="KPI filters">
        <form method="get" class="kpi-filter-grid">
            <label><span>Period</span><select name="period_mode"><option>Month</option><option>Quarter</option><option>Year</option><option>Custom</option></select></label>
            <label><span>From</span><input type="date" name="start_date" value="<?= htmlspecialchars($filterStartDate, ENT_QUOTES, 'UTF-8') ?>"></label>
            <label><span>To</span><input type="date" name="end_date" value="<?= htmlspecialchars($filterEndDate, ENT_QUOTES, 'UTF-8') ?>"></label>
            <label><span>Role</span><select data-kpi-filter="role"><option value="">All roles</option><option value="packer">Packer</option><option value="front_desk">Front Desk</option></select></label>
            <label><span>Employee</span><select data-kpi-filter="employee"><option value="">All employees</option><?php foreach ($employeeScores as $row): ?><option value="<?= (int) $row['employee_id'] ?>"><?= htmlspecialchars((string) $row['name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
            <label><span>Tier</span><select data-kpi-filter="tier"><option value="">All tiers</option><option>Gold</option><option>Silver</option><option>Bronze</option><option>No Bonus</option></select></label>
            <label><span>Bonus status</span><select data-kpi-filter="bonus"><option value="">All statuses</option><?php foreach (['Not Eligible','Eligible','Pending Review','Approved','Declined','Paid'] as $status): ?><option><?= $status ?></option><?php endforeach; ?></select></label>
            <label><span>Review status</span><select data-kpi-filter="review"><option value="">All reviews</option><?php foreach (['Not Reviewed','Pending Review','Reviewed','Locked'] as $status): ?><option><?= $status ?></option><?php endforeach; ?></select></label>
            <label class="kpi-filter-search"><span>Search</span><input type="search" placeholder="Search employee or role" data-kpi-search></label>
            <button class="kpi-btn" type="submit">Apply period</button>
        </form>
    </section>

    <section class="kpi-summary-grid" aria-label="Performance summary">
        <?php
        $summaryCards = [
            ['Average score', number_format($averageScore, 1), 'gauge'],
            ['Bonus eligible', $eligibleCount, 'award'],
            ['Pending review', $pendingCount, 'clock-3'],
            ['Approved / paid', $approvedCount, 'badge-check'],
            ['At risk', $atRiskCount, 'triangle-alert'],
            ['Top performer', $topScore ? $topScore['name'] : 'Unavailable', 'trophy'],
        ];
        foreach ($summaryCards as [$label, $value, $icon]): ?>
            <article><span class="kpi-summary-icon"><i data-lucide="<?= $icon ?>"></i></span><div><small><?= htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') ?></small><strong><?= htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') ?></strong></div></article>
        <?php endforeach; ?>
    </section>

    <section class="kpi-layout">
        <div class="kpi-primary">
            <section class="kpi-card">
                <header class="kpi-section-head"><div><h2>Employee leaderboard</h2><p>Final score after audited manual adjustments.</p></div><button class="kpi-btn kpi-btn--ghost" type="button" onclick="window.print()"><i data-lucide="download"></i> Export</button></header>
                <div class="kpi-table-wrap">
                    <table class="kpi-table">
                        <thead><tr><th>Rank</th><th>Employee</th><th>Role</th><th>Score</th><th>Tier</th><th>Review</th><th>Bonus</th><th>Action</th></tr></thead>
                        <tbody>
                        <?php foreach ($employeeScores as $row): ?>
                            <tr data-kpi-row data-role="<?= htmlspecialchars((string) $row['role_group'], ENT_QUOTES, 'UTF-8') ?>" data-employee="<?= (int) $row['employee_id'] ?>" data-tier="<?= htmlspecialchars((string) $row['tier']['label'], ENT_QUOTES, 'UTF-8') ?>" data-bonus="<?= htmlspecialchars((string) $row['bonus_status'], ENT_QUOTES, 'UTF-8') ?>" data-review="<?= htmlspecialchars((string) $row['review_status'], ENT_QUOTES, 'UTF-8') ?>" data-search="<?= htmlspecialchars(strtolower((string) $row['name'] . ' ' . (string) $row['role_name']), ENT_QUOTES, 'UTF-8') ?>">
                                <td>#<?= (int) $row['rank'] ?></td>
                                <td><strong><?= htmlspecialchars((string) $row['name'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                                <td><?= htmlspecialchars((string) $row['role_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><strong><?= number_format((float) $row['score'], 1) ?></strong></td>
                                <td><span class="kpi-chip kpi-chip--<?= htmlspecialchars((string) $row['tier']['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $row['tier']['label'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td><?= htmlspecialchars((string) $row['review_status'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) $row['bonus_status'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><button class="kpi-link" type="button" data-kpi-open="<?= (int) $row['employee_id'] ?>">View scorecard</button></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$employeeScores): ?><tr><td colspan="8" class="kpi-empty">No linked employee scorecard is available for this account.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="kpi-card">
                <header class="kpi-section-head"><div><h2>Scorecards</h2><p>Role-specific, evidence-backed performance categories.</p></div></header>
                <div class="kpi-scorecard-grid">
                    <?php foreach ($employeeScores as $row): ?>
                        <article class="kpi-employee-card" data-kpi-card data-role="<?= htmlspecialchars((string) $row['role_group'], ENT_QUOTES, 'UTF-8') ?>" data-employee="<?= (int) $row['employee_id'] ?>" data-tier="<?= htmlspecialchars((string) $row['tier']['label'], ENT_QUOTES, 'UTF-8') ?>" data-bonus="<?= htmlspecialchars((string) $row['bonus_status'], ENT_QUOTES, 'UTF-8') ?>" data-review="<?= htmlspecialchars((string) $row['review_status'], ENT_QUOTES, 'UTF-8') ?>" data-search="<?= htmlspecialchars(strtolower((string) $row['name'] . ' ' . (string) $row['role_name']), ENT_QUOTES, 'UTF-8') ?>">
                            <header><div class="kpi-avatar"><?= htmlspecialchars(strtoupper(substr((string) $row['name'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></div><div><h3><?= htmlspecialchars((string) $row['name'], ENT_QUOTES, 'UTF-8') ?></h3><p><?= htmlspecialchars((string) $row['scorecard'], ENT_QUOTES, 'UTF-8') ?></p></div><strong class="kpi-score"><?= number_format((float) $row['score'], 1) ?></strong></header>
                            <div class="kpi-category-list">
                                <?php foreach ($row['components'] as $label => $component): ?>
                                    <div><span><b><?= htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') ?></b><small><?= number_format((float) $component['weight'], 0) ?>% · <?= htmlspecialchars((string) $component['raw'], ENT_QUOTES, 'UTF-8') ?></small></span><strong><?= number_format((float) $component['score'], 1) ?></strong><i style="--value:<?= max(0, min(100, (float) $component['score'])) ?>%"></i></div>
                                <?php endforeach; ?>
                            </div>
                            <footer><span class="kpi-chip kpi-chip--<?= htmlspecialchars((string) $row['tier']['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $row['tier']['label'], ENT_QUOTES, 'UTF-8') ?></span><button class="kpi-link" type="button" data-kpi-open="<?= (int) $row['employee_id'] ?>">Details &amp; evidence</button></footer>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>

        <aside class="kpi-secondary">
            <section class="kpi-card kpi-trend-card"><h2>Performance trend</h2><p>Current vs previous period.</p><svg viewBox="0 0 300 120" role="img" aria-label="Performance trend"><polyline points="10,92 75,72 145,78 215,42 290,<?= max(10, min(105, 110 - $averageScore)) ?>" fill="none" stroke="#f07420" stroke-width="4"/><line x1="10" y1="108" x2="290" y2="108" stroke="#eadfd6"/><circle cx="290" cy="<?= max(10, min(105, 110 - $averageScore)) ?>" r="6" fill="#721b1a"/></svg></section>
            <section class="kpi-card"><h2>Risk alerts</h2><div class="kpi-alert-list"><?php foreach (array_slice(array_filter($employeeScores, static fn(array $row): bool => (float) $row['score'] < 75 || (int) $row['error_count'] > 0), 0, 5) as $row): ?><button type="button" data-kpi-open="<?= (int) $row['employee_id'] ?>"><strong><?= htmlspecialchars((string) $row['name'], ENT_QUOTES, 'UTF-8') ?></strong><span><?= (float) $row['score'] < 75 ? 'Below bonus threshold' : number_format((int) $row['error_count']) . ' linked error(s)' ?></span></button><?php endforeach; ?><?php if (!$atRiskCount): ?><p class="kpi-empty">No current risk alerts.</p><?php endif; ?></div></section>
            <section class="kpi-card"><h2>Live workload</h2><p>Operational volume is context only and is not added directly to the score.</p><?php foreach ($employeeScores as $row): ?><div class="kpi-workload"><span><?= htmlspecialchars((string) $row['name'], ENT_QUOTES, 'UTF-8') ?></span><strong><?= number_format((int) $row['orders_handled']) ?> orders · <?= number_format((float) $row['items_packed']) ?> items</strong></div><?php endforeach; ?></section>
        </aside>
    </section>

    <?php foreach ($employeeScores as $row): $history = $ready ? kpi_performance_history((int) $row['employee_id'], $period) : []; ?>
        <aside class="kpi-drawer" data-kpi-drawer="<?= (int) $row['employee_id'] ?>" aria-hidden="true">
            <div class="kpi-drawer-head"><div><small><?= htmlspecialchars((string) $row['scorecard'], ENT_QUOTES, 'UTF-8') ?></small><h2><?= htmlspecialchars((string) $row['name'], ENT_QUOTES, 'UTF-8') ?></h2></div><button type="button" data-kpi-close aria-label="Close"><i data-lucide="x"></i></button></div>
            <nav class="kpi-drawer-tabs"><?php foreach (['Overview','Score Breakdown','Evidence','Attendance','Tasks','Notes','Review History'] as $index => $tab): ?><button type="button" class="<?= $index === 0 ? 'active' : '' ?>" data-kpi-tab="<?= $index ?>"><?= $tab ?></button><?php endforeach; ?></nav>
            <div class="kpi-drawer-body">
                <section data-kpi-panel="0"><div class="kpi-hero-score"><strong><?= number_format((float) $row['score'], 1) ?></strong><span><?= htmlspecialchars((string) $row['tier']['label'], ENT_QUOTES, 'UTF-8') ?><br><?= htmlspecialchars((string) $row['bonus_status'], ENT_QUOTES, 'UTF-8') ?></span></div><dl><div><dt>Automatic score</dt><dd><?= number_format((float) $row['automatic_score'], 1) ?></dd></div><div><dt>Manual adjustment</dt><dd><?= number_format((float) $row['manual_adjustment'], 1, '.', '') ?></dd></div><div><dt>Review status</dt><dd><?= htmlspecialchars((string) $row['review_status'], ENT_QUOTES, 'UTF-8') ?></dd></div></dl></section>
                <section data-kpi-panel="1" hidden><?php foreach ($row['components'] as $label => $component): ?><div class="kpi-breakdown"><span><strong><?= htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars((string) $component['raw'], ENT_QUOTES, 'UTF-8') ?></small></span><b><?= number_format((float) $component['score'], 1) ?> / <?= number_format((float) $component['weight'], 0) ?>%</b></div><?php endforeach; ?></section>
                <section data-kpi-panel="2" hidden><?php foreach (($employeeKpiDetails[(int) $row['employee_id']] ?? []) as $bucket): ?><h3><?= htmlspecialchars((string) $bucket['title'], ENT_QUOTES, 'UTF-8') ?></h3><?php foreach ($bucket['metrics'] as $metric): ?><div class="kpi-evidence"><span><?= htmlspecialchars((string) $metric['label'], ENT_QUOTES, 'UTF-8') ?></span><strong><?= htmlspecialchars((string) $metric['value'], ENT_QUOTES, 'UTF-8') ?></strong></div><?php endforeach; ?><?php endforeach; ?></section>
                <section data-kpi-panel="3" hidden><div class="kpi-evidence"><span>Attendance score</span><strong><?= number_format((float) $row['attendance_score'], 1) ?></strong></div><div class="kpi-evidence"><span>Reliability score</span><strong><?= number_format((float) $row['reliability_score'], 1) ?></strong></div><p>Approved leave is treated as an exception and does not become a false zero.</p></section>
                <section data-kpi-panel="4" hidden><div class="kpi-evidence"><span>Checklist completion</span><strong><?= number_format((float) $row['checklist_rate'], 1) ?>%</strong></div></section>
                <section data-kpi-panel="5" hidden><p><?= htmlspecialchars((string) ($row['notes'] ?: 'No shared performance note.'), ENT_QUOTES, 'UTF-8') ?></p></section>
                <section data-kpi-panel="6" hidden><?php foreach ($history as $event): ?><div class="kpi-history"><strong><?= htmlspecialchars((string) $event['action_key'], ENT_QUOTES, 'UTF-8') ?></strong><span><?= htmlspecialchars((string) ($event['actor_name'] ?: 'System'), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string) $event['created_at'], ENT_QUOTES, 'UTF-8') ?></span><p><?= htmlspecialchars((string) $event['reason'], ENT_QUOTES, 'UTF-8') ?></p></div><?php endforeach; ?><?php if (!$history): ?><p>No review changes recorded yet.</p><?php endif; ?></section>

                <?php if ($kpiIsOwner): ?>
                    <form class="kpi-review-form" method="post" data-kpi-async>
                        <input type="hidden" name="kpi_action" value="review_performance"><input type="hidden" name="employee_id" value="<?= (int) $row['employee_id'] ?>">
                        <h3>Owner review</h3>
                        <label>Manual adjustment (-10 to +10)<input type="number" name="manual_adjustment" min="-10" max="10" step="0.5" value="<?= htmlspecialchars((string) $row['manual_adjustment'], ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label>Adjustment reason<textarea name="adjustment_reason" placeholder="Required when changing the score"><?= htmlspecialchars((string) ($row['review']['adjustment_reason'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea></label>
                        <div class="kpi-form-grid"><label>Review status<select name="review_status"><?php foreach (['Not Reviewed','Pending Review','Reviewed','Locked'] as $status): ?><option <?= $row['review_status'] === $status ? 'selected' : '' ?>><?= $status ?></option><?php endforeach; ?></select></label><label>Bonus status<select name="bonus_status"><?php foreach (['Not Eligible','Eligible','Pending Review','Approved','Declined','Paid'] as $status): ?><option <?= $row['bonus_status'] === $status ? 'selected' : '' ?>><?= $status ?></option><?php endforeach; ?></select></label></div>
                        <label>Owner note<textarea name="owner_note"><?= htmlspecialchars((string) ($row['review']['owner_note'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea></label>
                        <button class="kpi-btn" type="submit">Save audited review</button>
                    </form>
                <?php else: ?>
                    <form class="kpi-review-form" method="post" data-kpi-async><input type="hidden" name="kpi_action" value="acknowledge_review"><label>Employee note<textarea name="employee_note" placeholder="Optional response to your review"></textarea></label><button class="kpi-btn" type="submit">Acknowledge review</button></form>
                <?php endif; ?>
            </div>
        </aside>
    <?php endforeach; ?>

    <?php if ($kpiIsOwner): ?>
        <aside class="kpi-drawer" data-kpi-settings aria-hidden="true">
            <div class="kpi-drawer-head"><div><small>Owner controls</small><h2>KPI settings</h2></div><button type="button" data-kpi-close aria-label="Close"><i data-lucide="x"></i></button></div>
            <div class="kpi-drawer-body">
                <form class="kpi-review-form" method="post" data-kpi-async><input type="hidden" name="kpi_action" value="save_weights"><h3>Role category weights</h3><?php foreach ($weights as $group => $rows): ?><fieldset><legend><?= htmlspecialchars(ucwords(str_replace('_', ' ', $group)), ENT_QUOTES, 'UTF-8') ?></legend><?php foreach ($rows as $key => $weight): ?><label><?= htmlspecialchars((string) $weight['label'], ENT_QUOTES, 'UTF-8') ?><input type="number" min="0" max="100" step="1" name="weight_<?= htmlspecialchars($group . '_' . $key, ENT_QUOTES, 'UTF-8') ?>" value="<?= number_format((float) $weight['weight'], 0, '.', '') ?>"></label><?php endforeach; ?><strong>Total must equal 100%</strong></fieldset><?php endforeach; ?><button class="kpi-btn" type="submit">Save weights</button></form>
                <div class="kpi-period-controls"><form method="post" data-kpi-async><input type="hidden" name="kpi_action" value="lock_period"><button class="kpi-btn" type="submit">Lock period</button></form><form method="post" data-kpi-async><input type="hidden" name="kpi_action" value="reopen_period"><button class="kpi-btn kpi-btn--ghost" type="submit">Reopen period</button></form></div>
            </div>
        </aside>
    <?php endif; ?>
    <div class="kpi-backdrop" data-kpi-backdrop hidden></div>
</main>

