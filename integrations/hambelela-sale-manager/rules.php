<?php
namespace Hambelela\Sales;

function matches(array $rule, array $row): bool {
    $ids = array_filter([$row['id'], $row['parent']]);
    if (array_intersect($ids, $rule['exclude'])) return false;
    if (array_intersect($ids, $rule['include'])) return true;
    return $rule['scope'] === 'all' || ($rule['scope'] === 'category' && array_intersect($row['categories'], $rule['categories']));
}

function quote(array $rules, array $row, int $qty, int $now): array {
    $base = $row['base'];
    $best = ['total' => $base * $qty, 'rule' => null];
    if ($qty < 1 || $row['regular'] <= 0) return $best;
    foreach ($rules as $rule) {
        if (!$rule['enabled'] || ($rule['from'] && $now < $rule['from']) ||
            ($rule['until'] && $now >= $rule['until']) || !matches($rule, $row)) continue;
        switch ($rule['kind']) {
            case 'percent':
                $total = round($row['regular'] * (10000 - $rule['value']) / 10000) * $qty;
                break;
            case 'fixed':
                $total = $rule['value'] * $qty;
                break;
            case 'bundle':
                $count = $rule['quantity'];
                if ($qty < $count) continue 2;
                $total = intdiv($qty, $count) * $rule['value'] + ($qty % $count) * $base;
                break;
            default: continue 2;
        }
        if ($total < $best['total']) $best = ['total' => (int)$total, 'rule' => $rule];
    }
    return $best;
}

function date_stamp($value): int {
    if ($value === '') return 0;
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $value, new \DateTimeZone('Africa/Windhoek'));
    if (!$date || $date->format('Y-m-d\\TH:i') !== $value) throw new \InvalidArgumentException('Enter a valid Windhoek date and time.');
    return $date->getTimestamp();
}
function clean_rule(array $in): array {
    $name = trim(strip_tags((string)($in['name'] ?? '')));
    $scope = $in['scope'] ?? '';
    $kind = $in['kind'] ?? '';
    $value = (string)($in['amount'] ?? '');
    $qty = filter_var($in['quantity'] ?? 2, FILTER_VALIDATE_INT);
    if ($name === '' || strlen($name) > 120) throw new \InvalidArgumentException('Enter a campaign name of up to 120 bytes.');
    if (!in_array($scope, ['all','selected','category'], true) || !in_array($kind, ['percent','fixed','bundle'], true)) throw new \InvalidArgumentException('Choose a scope and an offer type.');
    if (!preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D', $value) || (float)$value <= 0 || ($kind === 'percent' && (float)$value > 100)) throw new \InvalidArgumentException('Enter a positive offer value. Percentages may be up to 100.');
    if ($kind === 'bundle' && ($qty < 2 || $qty > 999)) throw new \InvalidArgumentException('Bundle size must be 2–999.');
    $r = ['name'=>$name,'scope'=>$scope,'kind'=>$kind,'value'=>(int)round((float)$value*100),'quantity'=>$kind === 'bundle' ? $qty : 1,
        'stack'=>($in['stack'] ?? false) === true, 'enabled'=>($in['enabled'] ?? false) === true];
    foreach (['categories','include','exclude'] as $key) {
        if (!is_array($in[$key] ?? null)) throw new \InvalidArgumentException('Invalid product selection.');
        $r[$key] = array_values(array_unique(array_map('intval',$in[$key])));
        if ($r[$key] && min($r[$key]) < 1) throw new \InvalidArgumentException('Invalid catalogue ID.');
    }
    if (($scope === 'category' && !$r['categories']) || ($scope === 'selected' && !$r['include'])) throw new \InvalidArgumentException('Choose at least one category or product for this scope.');
    $r['from'] = date_stamp((string)($in['start'] ?? ''));
    $r['until'] = date_stamp((string)($in['end'] ?? ''));
    if ($r['until'] && $r['from'] >= $r['until']) throw new \InvalidArgumentException('End time must be after start time.');
    return $r;
}
