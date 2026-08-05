<?php

declare(strict_types=1);

namespace Hambelela\EPI;

use DateInterval;
use DateTimeImmutable;
use PDO;

final class BusinessTimeEngine
{
    private $pdo;
    private $timezone;
    private $settings = [];
    private $calendar = [];

    public function __construct(PDO $pdo, string $timezone = 'Africa/Windhoek')
    {
        $this->pdo = $pdo;
        $this->timezone = $timezone;
    }

    public function workingMinutes($start, $end): float
    {
        $from = Support::timestamp($start, $this->timezone);
        $to = Support::timestamp($end, $this->timezone);
        if ($to <= $from) {
            return 0.0;
        }

        $minutes = 0.0;
        $cursor = $from->setTime(0, 0);
        $last = $to->setTime(0, 0);
        while ($cursor <= $last) {
            $window = $this->windowForDate($cursor);
            if ($window !== null) {
                list($open, $close) = $window;
                $segmentStart = $from > $open ? $from : $open;
                $segmentEnd = $to < $close ? $to : $close;
                if ($segmentEnd > $segmentStart) {
                    $minutes += ($segmentEnd->getTimestamp() - $segmentStart->getTimestamp()) / 60;
                }
            }
            $cursor = $cursor->add(new DateInterval('P1D'));
        }
        return round($minutes, 2);
    }

    public function addWorkingMinutes($start, int $minutes): DateTimeImmutable
    {
        $cursor = Support::timestamp($start, $this->timezone);
        $remaining = max(0, $minutes);
        while ($remaining > 0) {
            $window = $this->windowForDate($cursor);
            if ($window === null || $cursor >= $window[1]) {
                $cursor = $cursor->modify('+1 day')->setTime(0, 0);
                continue;
            }
            if ($cursor < $window[0]) {
                $cursor = $window[0];
            }
            $available = (int) floor(($window[1]->getTimestamp() - $cursor->getTimestamp()) / 60);
            if ($remaining <= $available) {
                return $cursor->modify('+' . $remaining . ' minutes');
            }
            $remaining -= $available;
            $cursor = $cursor->modify('+1 day')->setTime(0, 0);
        }
        return $cursor;
    }

    public function windowForDate(DateTimeImmutable $date): ?array
    {
        $dateKey = $date->format('Y-m-d');
        $override = $this->calendarOverride($dateKey);
        if ($override !== null) {
            if (!(bool) $override['is_working_day'] || !$override['opens_at'] || !$override['closes_at']) {
                return null;
            }
            return [$this->atTime($date, $override['opens_at']), $this->atTime($date, $override['closes_at'])];
        }

        $day = (int) $date->format('N');
        if ($day === 7) {
            return null;
        }
        if ($day === 6) {
            return [$this->atTime($date, $this->setting('saturday_open', '09:00')), $this->atTime($date, $this->setting('saturday_close', '13:00'))];
        }
        return [$this->atTime($date, $this->setting('weekday_open', '08:00')), $this->atTime($date, $this->setting('weekday_close', '17:00'))];
    }

    private function atTime(DateTimeImmutable $date, string $time): DateTimeImmutable
    {
        list($hour, $minute) = array_map('intval', explode(':', substr($time, 0, 5)));
        return $date->setTime($hour, $minute);
    }

    private function setting(string $key, string $default): string
    {
        if (!$this->settings) {
            try {
                foreach ($this->pdo->query("SELECT setting_key, setting_value FROM epi_employee_performance_settings WHERE setting_key LIKE '%_open' OR setting_key LIKE '%_close'") as $row) {
                    $this->settings[(string) $row['setting_key']] = (string) $row['setting_value'];
                }
            } catch (\Throwable $error) {
                $this->settings = ['__loaded' => '1'];
            }
        }
        return $this->settings[$key] ?? $default;
    }

    private function calendarOverride(string $date): ?array
    {
        if (!array_key_exists($date, $this->calendar)) {
            try {
                $stmt = $this->pdo->prepare('SELECT is_working_day, opens_at, closes_at FROM epi_employee_business_calendar WHERE business_date = ? LIMIT 1');
                $stmt->execute([$date]);
                $this->calendar[$date] = $stmt->fetch() ?: null;
            } catch (\Throwable $error) {
                $this->calendar[$date] = null;
            }
        }
        return $this->calendar[$date];
    }
}
