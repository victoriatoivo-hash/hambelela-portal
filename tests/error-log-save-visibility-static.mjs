import fs from 'node:fs';
import assert from 'node:assert/strict';

const page = fs.readFileSync(new URL('../apps/operations/errors.php', import.meta.url), 'utf8');
const css = fs.readFileSync(new URL('../assets/css/portal.css', import.meta.url), 'utf8');

assert.match(page, /function error_log_created_response\(/, 'Creation must return authoritative saved-record state.');
assert.match(page, /'error_id' => \$errorId/, 'The AJAX response must include the inserted error ID.');
assert.match(page, /\$_SESSION\['incident_submission_token'\] = bin2hex\(random_bytes\(32\)\)/, 'A successful commit must rotate the submission token.');
assert.match(page, /body: new FormData\(this\)/, 'Create must preserve multipart uploads during background save.');
assert.match(page, /await loadErrorFilterView\(window\.location\.href, \{ push: false \}\)/, 'The current filtered result set must refresh without a page reload.');
assert.match(page, /The saved error is hidden by the current filters\./, 'A filtered-out save must be explained.');
assert.match(page, /data-error-view-after-save/, 'A filtered-out save must provide a direct saved-record action.');
assert.match(page, /data-error-clear-after-save/, 'A filtered-out save must provide a clear-filters action.');
assert.match(page, /this\.dataset\.saving === '1'/, 'Repeated clicks must be ignored while the save is pending.');
assert.match(page, /resetErrorEvidenceFiles\(\)/, 'Successful create must clear pending evidence files.');
assert.match(page, /error-board-row--just-saved/, 'The inserted row must be highlighted after refresh.');
assert.match(css, /@keyframes error-log-saved-row/, 'The saved-row highlight must be styled.');
assert.match(css, /prefers-reduced-motion: reduce[\s\S]*?error-board-row--just-saved/, 'Saved-row feedback must respect reduced motion.');

console.log('Error Log save and visibility static checks passed.');
