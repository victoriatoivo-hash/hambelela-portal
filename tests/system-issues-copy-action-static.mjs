import fs from 'node:fs';
import assert from 'node:assert/strict';

const page = fs.readFileSync('apps/operations/system-issues.php', 'utf8');
const copy = fs.readFileSync('assets/js/system-issue-copy.js', 'utf8');
const endpoint = fs.readFileSync('apps/operations/system-issue-brief-copy.php', 'utf8');
const service = fs.readFileSync('shared/system-issues.php', 'utf8');

assert.match(page, /assets\/js\/system-issue-copy\.js/);
assert.match(copy, /document\.addEventListener\('click',[\s\S]*true\);/);
assert.match(copy, /event\.stopImmediatePropagation\(\)/);
assert.match(copy, /requestPending/);
assert.match(copy, /window\.isSecureContext/);
assert.match(copy, /navigator\.clipboard\.writeText/);
assert.match(copy, /document\.execCommand\('copy'\)/);
assert.match(copy, /showManualCopy/);
assert.match(copy, /Copying\.\.\./);
assert.match(copy, /Copy failed/);
assert.match(copy, /Copy Codex Brief/);
assert.match(copy, /data\.error/);
assert.match(endpoint, /Only the immutable approved brief may be copied/);
assert.match(service, /# SYSTEM ISSUE REPAIR BRIEF/);
assert.match(service, /'acceptance_criteria'=>'Acceptance Criteria'/);

console.log('System Issues copy action safeguards passed.');
