import fs from 'node:fs';
import assert from 'node:assert/strict';

const service=fs.readFileSync(new URL('../shared/epi/AttendancePerformance.php',import.meta.url),'utf8');
const bridge=fs.readFileSync(new URL('../shared/epi/AttendanceActivityBridge.php',import.meta.url),'utf8');
const login=fs.readFileSync(new URL('../login.php',import.meta.url),'utf8');
const migration=fs.readFileSync(new URL('../operations-epi-attendance-performance-migration.sql',import.meta.url),'utf8');
for(const method of ['getSummary','getEmployeeSummary','getDailyAttendance','getSessions','getOnlineEmployees','getArrivalPerformance','getPortalActivity','getNotificationResponse','getCurrentIssues','getEvidence','getTimeline']) assert.match(service,new RegExp('function\\s+'+method+'\\s*\\('));
for(const excluded of ['heartbeat','poll','auto_refresh','background','presence']) assert.ok(service.includes(excluded));
assert.match(service,/Portal activity is not total working time/);
assert.match(service,/Possible Unverified Absence/);
assert.doesNotMatch(service,/calculateScore|bonus_amount|payroll deduction/i);
assert.match(bridge,/recordLogin/);assert.match(bridge,/recordLogout/);assert.match(bridge,/catch \(Throwable/);
assert.match(login,/record_epi_attendance_event\('login'/);assert.match(login,/record_epi_attendance_event\('logout'/);
for(const table of ['epi_attendance_schedules','epi_attendance_exceptions','epi_attendance_coverage','epi_attendance_monthly_summaries']) assert.ok(migration.includes(table));
console.log('Phase 8 EPI attendance static checks passed.');
