import assert from 'node:assert/strict';
import fs from 'node:fs';

const page = fs.readFileSync(new URL('../apps/operations/checklists.php', import.meta.url), 'utf8');
const templates = fs.readFileSync(new URL('../apps/operations/task-templates.php', import.meta.url), 'utf8');
const migration = fs.readFileSync(new URL('../operations-task-templates-migration.sql', import.meta.url), 'utf8');

assert.match(page, /monthDayInput\.disabled=!usesMonthDay/, 'hidden day-of-month must be omitted from submission');
assert.match(page, /floatingRole\.disabled = !floating/, 'inactive eligibility must be omitted from submission');
assert.match(page, /assignee\.disabled = floating/, 'inactive assignee must be omitted from submission');
assert.match(page, /const renderTaskFormState = \(clearInactive = false\) => \{ syncAssignmentType\(\); setTaskMode\(currentTaskMode\(\),clearInactive\); renderRecurringFields\(\); \}/, 'one renderer must synchronize authoritative task and assignment state');
assert.match(page, /recurrenceSelect\.disabled=!recurring/, 'non-recurring tasks omit recurrence configuration');
assert.doesNotMatch(page, /syncAssignmentMode\(\)/, 'successful creation must not call the removed assignment renderer');
assert.match(page, /const parsePortalDateTime = \(value\) =>/, 'date-time parsing must use the explicit portal parser');
assert.match(page, /Date\.UTC\(year,month-1,day,hour-2,minute,second\)/, 'date-time parsing must construct Windhoek instants explicitly');
assert.match(page, /finally \{ saving=false;submit\.disabled=false;submit\.textContent=originalLabel; \}/, 'submit state must always recover');
assert.match(page, /mode==='recurring'[^]*Choose at least one repeat day/, 'custom weekdays require a selected weekday');
assert.match(page, /Choose a valid occurrence time/, 'recurring tasks require a valid occurrence time');
assert.match(page, /weekly_days:\[1-7\]/, 'server accepts the weekday recurrence rule');
assert.match(page, /Choose a day of month from 1 to 31/, 'monthly recurrence validates the calendar day');
assert.doesNotMatch(page, /recurrenceStartDate[^\n]*repeatDays/, 'start date must not be forced to a selected weekday');

assert.match(templates, /weekly_days:\[1-7\]/, 'template backend accepts weekday recurrence rules');
assert.match(templates, /recurrence_time/, 'templates preserve occurrence time');
assert.match(templates, /recurrence_start_date/, 'templates preserve optional start-date behaviour');
assert.match(page, /selectedDays\.includes\(input\.value\)/, 'template reload restores selected weekdays');
assert.match(migration, /recurrence_time TIME NULL/, 'fresh installs include recurrence template metadata');
assert.match(page,/release_offset_minutes/,'recurring release rule is stored independently');
assert.match(page,/occurrence_due_time/,'recurring due rule is stored independently');

// Contract check for the portal algorithm: first matching weekday on/after the boundary.
function nextWeekday(startIso, afterIso, selected, time) {
  const after = new Date(`${afterIso}Z`);
  const start = new Date(`${startIso}T00:00:00Z`);
  let candidate = new Date(after);
  candidate.setUTCHours(0, 0, 0, 0);
  if (after.toISOString().slice(11, 19) > `${time}:00`) candidate.setUTCDate(candidate.getUTCDate() + 1);
  if (candidate < start) candidate = start;
  for (let i = 0; i < 370; i += 1) {
    const isoDay = candidate.getUTCDay() === 0 ? 7 : candidate.getUTCDay();
    if (selected.includes(isoDay)) return `${candidate.toISOString().slice(0, 10)} ${time}`;
    candidate.setUTCDate(candidate.getUTCDate() + 1);
  }
  return null;
}

assert.equal(nextWeekday('2026-08-23', '2026-08-23T12:00:00', [1, 3], '08:00'), '2026-08-24 08:00');
assert.equal(nextWeekday('2026-08-24', '2026-08-24T07:00:00', [1, 3], '08:00'), '2026-08-24 08:00');
assert.equal(nextWeekday('2026-08-24', '2026-08-24T09:00:00', [1, 3], '08:00'), '2026-08-26 08:00');

console.log('Task recurring validation static checks passed.');
