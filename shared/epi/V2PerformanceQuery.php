<?php

declare(strict_types=1);

namespace Hambelela\EPI;

use PDO;

/** Read-only V2 risk, history and evidence drill-down queries. */
final class V2PerformanceQuery
{
    private $pdo;
    public function __construct(PDO$pdo){$this->pdo=$pdo;}

    public function personalRisk(int$employeeId):array
    {
        $stmt=$this->pdo->prepare("SELECT i.*,r.description event_description,r.category_key,d.obligation_key,d.starts_at,d.fulfilled_at FROM epi_v2_performance_incidents i JOIN epi_v2_event_registry r ON r.event_key=i.event_key LEFT JOIN epi_v2_operational_deadlines d ON d.deadline_uuid=i.deadline_uuid WHERE i.responsible_employee_at_breach=? AND i.current_risk_state='open' ORDER BY i.due_at,i.id");$stmt->execute([$employeeId]);return$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    }

    public function teamRisk(string$team):array
    {
        $stmt=$this->pdo->prepare("SELECT d.*,r.description event_description,r.category_key FROM epi_v2_operational_deadlines d JOIN epi_v2_event_registry r ON r.event_key=d.breach_event_key WHERE d.responsible_team=? AND d.state IN('open','needs_attribution','breached') AND(d.responsible_employee_snapshot IS NULL OR d.state='needs_attribution') ORDER BY d.due_at,d.id");$stmt->execute([trim($team)]);return$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    }

    public function history(int$employeeId,string$from,string$to):array
    {
        $stmt=$this->pdo->prepare('SELECT i.*,r.description event_description,r.category_key,r.polarity,r.score_eligible,r.owner_review_required FROM epi_v2_performance_incidents i JOIN epi_v2_event_registry r ON r.event_key=i.event_key WHERE i.responsible_employee_at_breach=? AND DATE(i.occurred_at) BETWEEN ? AND ? ORDER BY i.occurred_at DESC,i.id DESC');$stmt->execute([$employeeId,$from,$to]);return$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    }

    public function explain(string$incidentUuid):array
    {
        $stmt=$this->pdo->prepare('SELECT i.*,r.description event_description,r.responsibility_type,r.polarity,r.score_eligible,r.owner_review_required,r.severity_handling,r.sla_obligation_key,r.category_key,d.starts_at,d.due_at deadline_due_at,d.fulfilled_at,d.fulfilled_by,d.grace_minutes,d.exception_id deadline_exception_id,o.ownership_uuid,o.employee_id ownership_employee_id,o.role_key ownership_role,o.ownership_reason,o.effective_from ownership_from,o.effective_to ownership_to,o.assigned_by,o.accepted_by,o.accepted_at,o.transfer_reason,o.source ownership_source FROM epi_v2_performance_incidents i JOIN epi_v2_event_registry r ON r.event_key=i.event_key LEFT JOIN epi_v2_operational_deadlines d ON d.deadline_uuid=i.deadline_uuid LEFT JOIN epi_v2_ownership_periods o ON o.ownership_uuid=JSON_UNQUOTE(JSON_EXTRACT(i.metadata_json,\'$.ownership_uuid\')) WHERE i.incident_uuid=? LIMIT 1');$stmt->execute([$incidentUuid]);$row=$stmt->fetch(PDO::FETCH_ASSOC);return$row?:[];
    }
}
