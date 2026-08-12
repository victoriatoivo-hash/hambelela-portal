import fs from 'node:fs';
import assert from 'node:assert/strict';

const page = fs.readFileSync(new URL('../apps/operations/errors.php', import.meta.url), 'utf8');
const css = fs.readFileSync(new URL('../assets/css/portal.css', import.meta.url), 'utf8');
const toolbarCss = fs.readFileSync(new URL('../assets/css/portal-view-bar.css', import.meta.url), 'utf8');

assert.match(page, /function error_occurrence_expression\([^)]*\): string \{ return "\{\$alias\}\.occurred_on"; \}/, 'Date filtering must use the authoritative occurred_on field.');
assert.match(page, /'date_mode' => \$requestedDateMode/, 'Date mode must be explicit.');
assert.match(page, /\['month'=>'Selected Month','custom'=>'Custom Date Range'\]/, 'Month and custom modes must both be available.');
assert.match(page, /\$dateExpression \. ' BETWEEN \? AND \?'/, 'Selected month must use inclusive date boundaries.');
assert.match(page, /data-error-filter-clear>Clear All/, 'Clear All action must be visible.');
assert.match(page, /type="submit">Apply Filters/, 'Apply Filters action must be explicit.');
assert.match(page, /data-error-filter-chip/, 'Applied filters must render removable chips.');
assert.match(page, /new AbortController\(\)/, 'Stale filter requests must be cancelled.');
assert.match(page, /history\.pushState/, 'Filtering must update without a full-page navigation.');
assert.match(page, /Could not load filtered Error Log records/, 'Request failures must differ from empty results.');
assert.match(page, /No Error Log records match these filters/, 'Empty filtered results must be explained.');
assert.match(css, /\.error-log-page \.error-filter-chips/, 'Active filter chips must use scoped Error Log styling.');
assert.match(toolbarCss, /Error Log filter workflow/, 'The shared popover must retain Error Log-specific sizing and sticky actions.');

console.log('Error Log filter workflow static checks passed.');
