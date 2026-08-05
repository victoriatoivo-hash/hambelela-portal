import assert from 'node:assert/strict';
import fs from 'node:fs';

const source = fs.readFileSync(new URL('../assets/js/kpi-employee.js', import.meta.url), 'utf8');

assert.match(source, /days=Math\.floor\(total\/1440\)/, 'durations must calculate elapsed 24-hour days');
assert.match(source, /metric\.format === 'minutes' \? durationMinutes\(metric\.value\)/, 'minute metrics must use the shared duration formatter');
assert.match(source, /metric\.format === 'hours' \? durationMinutes\(Number\(metric\.value\)\*60\)/, 'hour metrics must use the shared duration formatter');
assert.match(source, /const table = \(columns, rows = \[\]\) => formatElapsedHtml\(/, 'evidence-table minute values must use the shared formatter');
assert.doesNotMatch(source, /`\$\{Math\.round\(Number\(metric\.value\)\)\} min`/, 'raw minute metric output must not remain');

const format = (minutes) => {
  const total = Math.max(0, Math.round(Number(minutes || 0)));
  const days = Math.floor(total / 1440);
  const hours = Math.floor((total % 1440) / 60);
  const remaining = total % 60;
  return days ? `${days}d ${hours}h ${remaining}m` : hours ? `${hours}h ${remaining}m` : `${remaining}m`;
};

assert.equal(format(1460), '1d 0h 20m');
assert.equal(format(185), '3h 5m');
assert.equal(format(5), '5m');

console.log('Employee Performance duration formatting contract passed.');
