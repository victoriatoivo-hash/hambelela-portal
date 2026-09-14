import fs from 'node:fs';
import assert from 'node:assert/strict';

const auth = fs.readFileSync('shared/auth.php', 'utf8');
const login = fs.readFileSync('login.php', 'utf8');

assert.match(auth, /PORTAL_SESSION_MAX_SECONDS = 36000/);
assert.match(auth, /PORTAL_SESSION_TIMEZONE = 'Africa\/Windhoek'/);
assert.match(auth, /session\.gc_maxlifetime/);
assert.match(auth, /session\.cookie_lifetime/);
assert.match(auth, /'httponly' => true/);
assert.match(auth, /'samesite' => 'Lax'/);
assert.match(auth, /portal_request_is_https\(\)/);
assert.match(auth, /portal_calculate_absolute_expiry/);
assert.match(auth, /modify\('tomorrow'\)->setTime\(0, 0, 0\)/);
assert.match(auth, /\$tenHours < \$nextDay \? \$tenHours : \$nextDay/);
assert.match(auth, /\$_SESSION\['authenticated_at'\]/);
assert.match(auth, /\$_SESSION\['absolute_expires_at'\]/);
assert.match(auth, /\$_SESSION\['last_activity_at'\]/);
assert.match(auth, /\$_SESSION\['login_date'\]/);
assert.match(auth, /\$_SESSION\['session_user_id'\]/);
assert.match(auth, /\$_SESSION\['session_identifier'\]/);
assert.match(auth, /Cache-Control: private, no-store/);
assert.match(auth, /setcookie\(session_name\(\), ''/);
assert.doesNotMatch(auth, /absolute_expires_at'\]\s*=.*last_activity/);
assert.doesNotMatch(auth, /catch \(Throwable \$e\) \{\s*logout_user\(\)/);
assert.match(login, /portal_initialize_authenticated_session\(\$_SESSION\['user'\]\)/);
assert.match(login, /Your working-day session has expired\. Please log in again\./);
assert.match(login, /portal_safe_return_path/);

const windhoekExpiry = (iso) => {
  const loginMs = Date.parse(iso);
  const tenHoursMs = loginMs + 10 * 60 * 60 * 1000;
  const date = iso.slice(0, 10);
  const nextMidnightMs = Date.parse(`${date}T00:00:00+02:00`) + 24 * 60 * 60 * 1000;
  return Math.min(tenHoursMs, nextMidnightMs);
};

assert.equal(new Date(windhoekExpiry('2026-08-05T08:00:00+02:00')).toISOString(), '2026-08-05T16:00:00.000Z');
assert.equal(new Date(windhoekExpiry('2026-08-05T15:00:00+02:00')).toISOString(), '2026-08-05T22:00:00.000Z');

console.log('Working-day session safeguards passed.');
