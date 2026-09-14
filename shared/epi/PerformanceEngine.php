<?php

declare(strict_types=1);

namespace Hambelela\EPI;

use InvalidArgumentException;

final class PerformanceEngine
{
    private $evidence;

    public function __construct(EvidenceEngine $evidence)
    {
        $this->evidence = $evidence;
    }

    public function receive(array $payload): ?string
    {
        $validated = $this->validate($payload);
        $validated['metadata'] = array_merge((array) ($validated['metadata'] ?? []), [
            'epi_category' => $this->categorise($validated),
            'epi_framework_phase' => 'foundation',
        ]);
        return $this->evidence->record($validated);
    }

    public function validate(array $payload): array
    {
        Support::requireModule((string) ($payload['module'] ?? ''));
        foreach (['reference_number', 'action', 'activity_source'] as $required) {
            if (trim((string) ($payload[$required] ?? '')) === '') {
                throw new InvalidArgumentException('Missing EPI evidence field: ' . $required);
            }
        }
        // Phase 1 deliberately accepts score_impact as evidence metadata only.
        // It never computes, aggregates or writes employee scores.
        return $payload;
    }

    public function categorise(array $payload): string
    {
        $action = strtolower((string) ($payload['action'] ?? ''));
        if (strpos($action, 'error') !== false || strpos($action, 'reject') !== false) return 'quality';
        if (strpos($action, 'complete') !== false || strpos($action, 'progress') !== false) return 'workflow';
        if (strpos($action, 'assign') !== false || strpos($action, 'owner') !== false) return 'ownership';
        if (strpos($action, 'login') !== false || strpos($action, 'logout') !== false) return 'presence';
        return 'activity';
    }
}
