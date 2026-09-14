<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../shared/ess-inspiration.php';
$catalog = ess_inspiration_catalog();
if (($argv[1] ?? '') === '--json') { echo json_encode($catalog); exit; }
function check(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
check(count($catalog['verses']) >= 60, 'At least 60 verses');
check(count($catalog['quotes']) >= 60, 'At least 60 quotes');
foreach ($catalog as $entries) {
    check(count(array_unique(array_column($entries, 'text'))) === count($entries), 'No duplicate entries');
    foreach ($entries as $entry) { check(strlen($entry['text']) < 240, 'Short enough for the hero'); }
}
$morning = new DateTimeImmutable('2026-09-11T01:00:00+02:00');
$evening = new DateTimeImmutable('2026-09-11T23:59:59+02:00');
check(ess_daily_inspiration($morning) === ess_daily_inspiration($evening), 'Stable throughout one Namibia date');
check(ess_daily_inspiration($morning) === ess_daily_inspiration($morning->setTimezone(new DateTimeZone('America/New_York'))), 'Independent of input timezone');
check(ess_daily_inspiration($evening) !== ess_daily_inspiration($evening->modify('+1 second')), 'Changes at Namibia midnight');
$pairs = []; $verses = []; $quotes = [];
for ($day = 0; $day < 7320; $day++) {
    $selected = ess_daily_inspiration($morning->modify("+$day days"));
    $pairs[] = json_encode($selected);
    if ($selected['type'] === 'verse') $verses[] = $selected['verse']['text'];
    else $quotes[] = $selected['quote']['text'];
    $next = ess_daily_inspiration($morning->modify('+' . ($day + 1) . ' days'));
    check($selected['type'] !== $next['type'], 'Verse and quote alternate each day');
}
check(count(array_unique($pairs)) === 7320, 'Alternating 60/61 item cycles cover all selections');
check(count(array_unique($verses)) === count($catalog['verses']), 'Every verse is reachable');
check(count(array_unique($quotes)) === count($catalog['quotes']), 'Every quote is reachable');
echo "Daily inspiration: catalog, timezone, midnight and full rotation checks passed.\n";
