import fs from 'node:fs';

const courier = fs.readFileSync('apps/operations/courier.php', 'utf8');
const css = fs.readFileSync('assets/css/courier-essentials.css', 'utf8');

const required = [
  "w.sent_at IS NOT NULL",
  "w.sent_at IS NULL AND w.status IN ('pending','overdue')",
  "SUM(CASE WHEN w.sent_at IS NOT NULL THEN 1 ELSE 0 END) AS sent_count",
  "SUM(CASE WHEN sent_at IS NULL AND status = 'pending' THEN 1 ELSE 0 END) AS pending",
  "WHERE batch_id = ? AND sent_at IS NULL AND archived_at IS NULL AND deleted_at IS NULL",
  "SET status = 'sent', sent_by = ?, sent_at = ?",
  "['owner_admin', 'front_desk_admin', 'front_desk_admin_employee', 'supervisor_manager', 'marketing_sales']",
  '$showOrderAssignment = $isPacker || $canManageWaybills;',
  "if (!$canUploadWaybills && !$canManageWaybills) throw new RuntimeException('Only packers and admin can assign waybills to orders.');",
  "($GLOBALS['showOrderAssignment'] ?? false) && !$sent",
  "data-waybill-count",
  "data-downloaded-count",
  "function invalidateCourierRefresh()",
  "if (version !== courierRefreshVersion) return null",
  "markButton.disabled = true",
  "markButton.innerHTML = '<i data-lucide=\"loader-circle\"></i> Sending...'",
  "markButton.innerHTML = originalMarkup",
  "showToast(error.message || 'Could not mark this waybill as sent. Please try again.', 'error')",
  "href=\"courier.php?action=waybill_download_zip&amp;batch_id=",
  "data-waybill-file-download",
  "hambelela_waybill_orders",
];

for (const needle of required) {
  if (!courier.includes(needle)) throw new Error(`Courier sent-state invariant missing: ${needle}`);
}

const requiredCss = [
  '.ess-courier-page .btn-mark-sent{min-height:38px',
  'background:#AF542B',
  "font:500 12.5px/1 'Jost',sans-serif",
  '.ess-courier-page .badge:is(.sent,.ontime){color:#477356;background:#E7F1EA',
  '.ess-courier-page .badge:is(.ok,.duesoon){color:#9A6818;background:#FBF1D8',
  '.ess-courier-page .courier-toast.is-success',
  '.ess-courier-page .courier-toast.is-error',
  '.ess-courier-page .queue-list .courier-actions-cell :is(.btn-secondary,.btn-mark-sent){flex:1 1 132px;}',
];

for (const needle of requiredCss) {
  if (!css.includes(needle)) throw new Error(`Courier sent-state style missing: ${needle}`);
}

if (courier.includes("WHERE batch_id = ? AND status IN ('pending','overdue') AND sent_at IS NULL")) {
  throw new Error('Mark Sent still depends on mutable display status instead of canonical sent_at.');
}
if (/sent_by\s*=\s*[^?,\n]*Secilia/i.test(courier)) {
  throw new Error('Courier action attribution appears to be hard-coded to Secilia.');
}

console.log('Courier sent-state consistency checks passed.');
