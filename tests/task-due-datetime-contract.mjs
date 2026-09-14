import fs from 'node:fs';
import assert from 'node:assert/strict';

const source = fs.readFileSync(new URL('../apps/operations/checklists.php', import.meta.url), 'utf8');
const parserSource = source.match(/const parsePortalDateTime = (\(value\) => \{[\s\S]*?\n  \});/)?.[1];
assert.ok(parserSource, 'Create Task canonical parser must be discoverable');
const parsePortalDateTime = Function(`return (${parserSource})`)();

const iso = (value) => parsePortalDateTime(value)?.toISOString();
assert.equal(iso('2026-08-25 08:00'), '2026-08-25T06:00:00.000Z');
assert.equal(iso('2026-08-25 17:00'), '2026-08-25T15:00:00.000Z');
assert.equal(iso('2026-08-25 00:00'), '2026-08-24T22:00:00.000Z');
assert.equal(iso('2026-08-25 12:00'), '2026-08-25T10:00:00.000Z');
assert.equal(iso('2026-08-31 17:00'), '2026-08-31T15:00:00.000Z');
assert.equal(parsePortalDateTime('2026-02-30 17:00'), null);
assert.equal(parsePortalDateTime('25/08/2026 05:00 PM'), null, 'display strings are never canonical frontend values');

const successfulControls = (mode, values) => ({
  task_mode: mode,
  ...(mode === 'one_off' ? { due_at: values.due_at } : {}),
  ...(mode === 'scheduled' ? { scheduled_at: values.scheduled_at, due_at: values.scheduled_due_at } : {}),
  ...(mode === 'recurring' ? { recurrence_start_date: values.recurrence_start_date, recurrence_time: values.recurrence_time } : {}),
});
assert.deepEqual(successfulControls('one_off', {due_at:'2026-08-25 17:00'}), {task_mode:'one_off', due_at:'2026-08-25 17:00'});
assert.deepEqual(successfulControls('scheduled', {scheduled_at:'2026-08-25 09:00',scheduled_due_at:'2026-08-25 17:00'}), {task_mode:'scheduled',scheduled_at:'2026-08-25 09:00',due_at:'2026-08-25 17:00'});
assert.deepEqual(successfulControls('recurring', {recurrence_start_date:'2026-08-25',recurrence_time:'08:00'}), {task_mode:'recurring',recurrence_start_date:'2026-08-25',recurrence_time:'08:00'});

console.log('Task due date-time contract checks passed.');
