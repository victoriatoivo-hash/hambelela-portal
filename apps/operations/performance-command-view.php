<?php
declare(strict_types=1);
// A view inside reports.php, never a substitute portal or public endpoint.
if (!isset($commandTabs,$tab) || !isset($commandTabs[$tab])) { http_response_code(404); exit; }
?>
<form class="kpi-period-panel pc-report-controls" data-pc-controls>
  <label>Reporting period<select name="period"><option value="this_month">This month</option><option value="last_month">Last month</option><option value="this_week">This week</option><option value="today">Today</option><option value="custom">Custom dates</option></select></label>
  <label>From<input type="date" name="date_from"></label><label>To<input type="date" name="date_to"></label>
  <label>Search evidence<input type="search" name="search" placeholder="Name, record or status"></label>
  <button type="submit" class="btn-primary">Apply filters</button><button type="button" class="btn-secondary" data-pc-export>Export CSV</button>
</form>
<p class="pc-runtime-note" data-pc-period aria-live="polite">Loading source records…</p>
<div data-pc-report aria-live="polite"></div>
<script defer src="<?= BASE_URL ?>/assets/js/performance-command-sections.js?v=<?= (int)filemtime(BASE_PATH.'/assets/js/performance-command-sections.js') ?>"></script>
