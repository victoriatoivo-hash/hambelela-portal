import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const reporting = await readFile(new URL('../apps/operations/kpi-reporting.php', import.meta.url), 'utf8');
const businessHealth = await readFile(new URL('../apps/operations/reports-data.php', import.meta.url), 'utf8');

assert.doesNotMatch(reporting, /\bmixed\s+\$/u, 'KPI reporting must not use PHP 8 mixed parameter types');
assert.doesNotMatch(reporting, /catch\s*\(\s*(?:Throwable|Exception)\s*\)/u, 'PHP 7.4 requires a catch variable');
assert.match(reporting, /function\s+kpi_metric\s*\(/u);
assert.match(businessHealth, /function\s+kpi_business_health_metric\s*\(/u);
assert.doesNotMatch(businessHealth, /function\s+kpi_metric\s*\(/u, 'The endpoint must not redeclare the shared helper');

console.log('KPI PHP 7.4 compatibility checks passed.');
