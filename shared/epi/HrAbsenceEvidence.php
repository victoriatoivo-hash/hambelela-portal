<?php
declare(strict_types=1);
namespace Hambelela\EPI;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/** Read-only HR evidence boundary. Never interprets an unavailable source as no leave. */
final class HrAbsenceEvidence
{
    public static function inspect(PDO $portal, ?PDO $hr, int $employee, string $at): array
    {
        if ($employee <= 0) return self::unknown('invalid_employee');
        $instant = self::date($at, 'Y-m-d H:i:s');
        if (!$instant) return self::unknown('invalid_timestamp');
        if (!$hr) return self::unknown('hr_unavailable');
        try {
            $q = $portal->prepare('SELECT id,portal_user_id,hr_employee_id,linked_at FROM employee_user_links WHERE portal_user_id=? AND active=1');
            $q->execute([$employee]);
            $links = $q->fetchAll(PDO::FETCH_ASSOC);
            $q->closeCursor();
            if (count($links) !== 1) return self::unknown('missing_or_ambiguous_link');
            $link = $links[0];
            $q = $portal->prepare('SELECT COUNT(*) FROM employee_user_links WHERE hr_employee_id=? AND active=1');
            $q->execute([$link['hr_employee_id']]);
            $count = (int) $q->fetchColumn();
            $q->closeCursor();
            if ($count !== 1) return self::unknown('shared_hr_identity');
            if (!self::date((string)$link['linked_at'], 'Y-m-d H:i:s') || $link['linked_at'] > $at) {
                return self::unknown('identity_not_established_at_deadline');
            }
            $q = $hr->prepare('SELECT id FROM employees WHERE id=?');
            $q->execute([$link['hr_employee_id']]);
            $exists = $q->fetchColumn();
            $q->closeCursor();
            if (!$exists) return self::unknown('missing_hr_employee');
            $q = $hr->prepare("SELECT id,employee_id,status,start_date,end_date,approved_at,approved_by FROM leave_requests WHERE employee_id=? AND status='approved' AND start_date<=? AND end_date>=? ORDER BY id");
            $day = $instant->format('Y-m-d');
            $q->execute([$link['hr_employee_id'], $day, $day]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            $q->closeCursor();
            $result = self::classify($rows, $at);
            $result['identity'] = ['portal_employee_id'=>$employee, 'hr_employee_id'=>(int)$link['hr_employee_id'], 'link_id'=>(int)$link['id'], 'linked_at'=>$link['linked_at']];
            return $result;
        } catch (Throwable $error) {
            // Do not expose connection details or turn failed queries into an empty leave list.
            return self::unknown('hr_evidence_unavailable');
        }
    }

    public static function classify(array $rows, string $at): array
    {
        if (!self::date($at, 'Y-m-d H:i:s')) return self::unknown('invalid_timestamp');
        $evidence = [];
        $lateApproval = false;
        foreach ($rows as $row) {
            if (($row['status'] ?? '') !== 'approved') continue;
            $from = self::date((string)($row['start_date'] ?? ''), 'Y-m-d');
            $last = self::date((string)($row['end_date'] ?? ''), 'Y-m-d');
            $approved = self::date((string)($row['approved_at'] ?? ''), 'Y-m-d H:i:s');
            if (!$from || !$last || $last < $from || !$approved || (int)($row['approved_by'] ?? 0) <= 0) {
                return self::unknown('incomplete_leave_approval');
            }
            $to = $last->modify('+1 day'); // HR end dates are inclusive; ownership intervals are not.
            if ($at < $from->format('Y-m-d H:i:s') || $at >= $to->format('Y-m-d H:i:s')) continue;
            $late = $approved->format('Y-m-d H:i:s') > $at;
            $lateApproval = $lateApproval || $late;
            $evidence[] = ['source'=>'hr.leave_requests', 'source_id'=>(int)$row['id'],
                'hr_employee_id'=>(int)$row['employee_id'], 'effective_from'=>$from->format('Y-m-d H:i:s'),
                'effective_to'=>$to->format('Y-m-d H:i:s'), 'approved_at'=>$row['approved_at'],
                'hr_reviewer_user_id'=>(int)$row['approved_by'], 'approved_after_deadline'=>$late];
        }
        return ['state'=>$lateApproval ? 'needs_review' : ($evidence ? 'approved_absence' : 'no_approved_absence'),
            'reason'=>$lateApproval ? 'approval_after_deadline' : null, 'evidence'=>$evidence];
    }

    private static function unknown(string $reason): array
    {
        return ['state'=>'needs_review', 'reason'=>$reason, 'evidence'=>[]];
    }

    private static function date(string $value, string $format): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!'.$format, $value, new DateTimeZone('Africa/Windhoek'));
        return $date && $date->format($format) === $value ? $date : null;
    }
}
