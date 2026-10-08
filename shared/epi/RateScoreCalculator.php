<?php
declare(strict_types=1);
namespace Hambelela\EPI;

use InvalidArgumentException;

/** Pure V2 calculation. Callers must supply versioned policy and eligible observations.
 * No database access, default business weights, persistence or official activation.
 */
final class RateScoreCalculator
{
    public static function calculate(array $policy, array $observations): array
    {
        if (trim((string)($policy['version'] ?? '')) === '') {
            throw new InvalidArgumentException('A scorecard version is required.');
        }
        $categories = $policy['categories'] ?? [];
        self::weights($categories);
        $output = []; $overall = 0.0; $complete = true; $measuredWeight = 0.0;
        $missing = [];
        foreach ($categories as $categoryKey => $category) {
            $metrics = $category['metrics'] ?? [];
            self::weights($metrics);
            $metricRows = []; $categoryScore = 0.0; $categoryComplete = true;
            foreach ($metrics as $key => $metric) {
                $minimum = $metric['minimum_volume'] ?? null;
                if (!is_int($minimum) || $minimum < 1) {
                    throw new InvalidArgumentException('A positive minimum volume is required.');
                }
                $direction = $metric['direction'] ?? '';
                if (!in_array($direction, ['success', 'error'], true)) {
                    throw new InvalidArgumentException('Explicit success/error direction is required.');
                }
                $moderate = $metric['moderate_volume'] ?? $minimum;
                $high = $metric['high_volume'] ?? max($moderate, $minimum * 5);
                if (!is_int($moderate) || !is_int($high) || $moderate < $minimum || $high < $moderate) {
                    throw new InvalidArgumentException('Confidence thresholds must be ordered integers.');
                }
                $sample = $observations[$categoryKey][$key] ?? null;
                $target=$metric['target_hundredths']??null;
                if($target!==null&&(!is_int($target)||$target<0||$target>10000))
                    throw new InvalidArgumentException('A configured target must be an integer percentage in hundredths.');
                $row = ['eligible_volume'=>0, 'numerator'=>0, 'rate_hundredths'=>null,
                    'score_hundredths'=>null, 'status'=>'insufficient_data',
                    'confidence'=>'not_measured', 'reason'=>'missing_evidence',
                    'label'=>$metric['label'] ?? $key, 'direction'=>$direction,
                    'moderate_volume'=>$moderate, 'high_volume'=>$high,
                    'minimum_volume'=>$minimum, 'weight_hundredths'=>$metric['weight_hundredths'],
                    'target_hundredths'=>$target];
                if ($sample !== null) {
                    $denominator = $sample['eligible_volume'] ?? null;
                    $numerator = $sample['numerator'] ?? null;
                    if (!is_int($denominator) || !is_int($numerator) || $denominator < 0 ||
                        $numerator < 0 || $numerator > $denominator) {
                        throw new InvalidArgumentException('Invalid eligible observation counts.');
                    }
                    $row['eligible_volume'] = $denominator;
                    $row['numerator'] = $numerator;
                    $applicable = ($sample['applicable'] ?? true) !== false;
                    $row['reason'] = !$applicable ? 'not_applicable_requires_scorecard_revision' :
                        (($sample['source_complete'] ?? false) !== true ? 'incomplete_source' :
                        ($denominator === 0 ? 'no_workload' : ($denominator < $minimum ? 'low_sample' : null)));
                    if (!$applicable) $row['status'] = 'not_applicable';
                    if ($denominator > 0 && $applicable) {
                        $rate = $numerator / $denominator;
                        $score = ($direction === 'success' ? $rate : 1 - $rate) * 10000;
                        $row['rate_hundredths'] = (int)round($rate * 10000);
                        $row['score_hundredths'] = (int)round($score);
                        $row['confidence'] = $denominator >= $high ? 'high' :
                            ($denominator >= $moderate ? 'moderate' : 'low_sample');
                        // Missing or incomplete source coverage cannot become official.
                        $row['status'] = ($sample['source_complete'] ?? false) === true &&
                            $denominator >= $minimum ? 'calculated' : 'provisional';
                        $categoryScore += $score * $metric['weight_hundredths'] / 10000;
                    }
                }
                if ($row['status'] !== 'calculated') {
                    $categoryComplete = false;
                    $missing[] = ['category'=>$categoryKey, 'metric'=>$key, 'reason'=>$row['reason'],
                        'weight_hundredths'=>$category['weight_hundredths'] * $metric['weight_hundredths'] / 10000];
                } else {
                    $measuredWeight += $category['weight_hundredths'] * $metric['weight_hundredths'] / 10000;
                }
                $metricRows[$key] = $row;
            }
            $output[$categoryKey] = ['metrics'=>$metricRows,
                'label'=>$category['label'] ?? $categoryKey,
                'weight_hundredths'=>$category['weight_hundredths'],
                'status'=>$categoryComplete ? 'calculated' : 'insufficient_data',
                'measured_contribution_hundredths'=>$categoryComplete ?
                    (int)round($categoryScore * $category['weight_hundredths'] / 10000) : null,
                'official_score_hundredths'=>$categoryComplete ? (int)round($categoryScore) : null];
            if (!$categoryComplete) $complete = false;
            $overall += $categoryScore * $category['weight_hundredths'] / 10000;
        }
        return ['calculation_version'=>'epi-v2-rate-1', 'scorecard_version'=>$policy['version'],
            'measured_weight_hundredths'=>(int)round($measuredWeight),
            'missing_required_weight_hundredths'=>10000-(int)round($measuredWeight),
            'missing_evidence'=>$missing, 'renormalised'=>false,
            'categories'=>$output, 'status'=>$complete ? 'calculated' : 'insufficient_data',
            'official_score_hundredths'=>$complete ? (int)round($overall) : null];
    }

    private static function weights(array $rows): void
    {
        $total = 0;
        if (!$rows) throw new InvalidArgumentException('Weighted configuration cannot be empty.');
        foreach ($rows as $key => $row) {
            $weight = $row['weight_hundredths'] ?? null;
            if (!is_string($key) || $key === '' || !is_int($weight) || $weight < 1 || $weight > 10000) {
                throw new InvalidArgumentException('Named metrics/categories need positive integer weights.');
            }
            $total += $weight;
        }
        if ($total !== 10000) throw new InvalidArgumentException('Weights must total 10000.');
    }
}
