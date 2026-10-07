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
        $output = []; $overall = 0.0; $complete = true;
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
                $sample = $observations[$categoryKey][$key] ?? null;
                $row = ['eligible_volume'=>0, 'numerator'=>0, 'rate_hundredths'=>null,
                    'score_hundredths'=>null, 'status'=>'insufficient_data',
                    'minimum_volume'=>$minimum, 'weight_hundredths'=>$metric['weight_hundredths']];
                if ($sample !== null) {
                    $denominator = $sample['eligible_volume'] ?? null;
                    $numerator = $sample['numerator'] ?? null;
                    if (!is_int($denominator) || !is_int($numerator) || $denominator < 0 ||
                        $numerator < 0 || $numerator > $denominator) {
                        throw new InvalidArgumentException('Invalid eligible observation counts.');
                    }
                    $row['eligible_volume'] = $denominator;
                    $row['numerator'] = $numerator;
                    if ($denominator > 0) {
                        $rate = $numerator / $denominator;
                        $score = ($direction === 'success' ? $rate : 1 - $rate) * 10000;
                        $row['rate_hundredths'] = (int)round($rate * 10000);
                        $row['score_hundredths'] = (int)round($score);
                        // Missing or incomplete source coverage cannot become official.
                        $row['status'] = ($sample['source_complete'] ?? false) === true &&
                            $denominator >= $minimum ? 'calculated' : 'provisional';
                        $categoryScore += $score * $metric['weight_hundredths'] / 10000;
                    }
                }
                if ($row['status'] !== 'calculated') $categoryComplete = false;
                $metricRows[$key] = $row;
            }
            $output[$categoryKey] = ['metrics'=>$metricRows,
                'weight_hundredths'=>$category['weight_hundredths'],
                'status'=>$categoryComplete ? 'calculated' : 'insufficient_data',
                'official_score_hundredths'=>$categoryComplete ? (int)round($categoryScore) : null];
            if (!$categoryComplete) $complete = false;
            $overall += $categoryScore * $category['weight_hundredths'] / 10000;
        }
        return ['calculation_version'=>'epi-v2-rate-1', 'scorecard_version'=>$policy['version'],
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
