<?php

declare(strict_types=1);

namespace Hambelela\EPI;

use InvalidArgumentException;
use PDO;

/** Read-only access to the versioned canonical event contract. */
final class CanonicalEventRegistry
{
    private $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function event(string $eventKey): array
    {
        $eventKey = strtolower(trim($eventKey));
        if (!preg_match('/^[a-z][a-z0-9_]{2,99}$/', $eventKey)) {
            throw new InvalidArgumentException('Invalid canonical event key.');
        }
        $stmt = $this->pdo->prepare('SELECT * FROM epi_v2_event_registry WHERE event_key=? AND active=1 LIMIT 1');
        $stmt->execute([$eventKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new InvalidArgumentException('Unknown or inactive canonical event: ' . $eventKey);
        return $row;
    }

    public function scoreEligible(string $eventKey): bool
    {
        return (int) $this->event($eventKey)['score_eligible'] === 1;
    }

    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM epi_v2_event_registry WHERE active=1 ORDER BY module,event_key')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
