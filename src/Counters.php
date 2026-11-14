<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules;

/**
 * Per-rule daily counters (hits, would-hits, errors), the invalid-item
 * counter, and per-rule badges. Held in memory and written once per request.
 */
final class Counters
{
    public const OPTION = 'feed_rules_counters';
    public const KEEP_DAYS = 14;
    public const INVALID = '_invalid';

    private const HIT = 0;
    private const WOULD = 1;
    private const ERROR = 2;

    /** @var array<string, array<string, array{0: int, 1: int, 2: int}>> */
    private array $pendingDays = [];

    /** @var array<string, array<string, int|string>> */
    private array $pendingRules = [];

    /** @var list<string> */
    private array $resets = [];

    private bool $resetAll = false;

    public function hit(string $ruleId): void
    {
        $this->bump($ruleId, self::HIT);
        $this->pendingRules[$ruleId]['last_hit'] = Clock::now();
    }

    public function would(string $ruleId): void
    {
        $this->bump($ruleId, self::WOULD);
        $this->pendingRules[$ruleId]['last_hit'] = Clock::now();
    }

    public function error(string $ruleId, string $message): void
    {
        $this->bump($ruleId, self::ERROR);
        $this->pendingRules[$ruleId]['last_error'] = $message;
        $this->pendingRules[$ruleId]['last_error_time'] = Clock::now();
    }

    public function warning(string $ruleId, string $message): void
    {
        $this->pendingRules[$ruleId]['warning'] = $message;
        $this->pendingRules[$ruleId]['warning_time'] = Clock::now();
    }

    public function invalid(): void
    {
        $this->bump(self::INVALID, self::HIT);
    }

    /**
     * Clear error badges and error counts for one rule, or all when null.
     */
    public function resetErrors(?string $ruleId = null): void
    {
        if ($ruleId === null) {
            $this->resetAll = true;
        } else {
            $this->resets[] = $ruleId;
        }
    }

    public function hasPending(): bool
    {
        return $this->pendingDays !== [] || $this->pendingRules !== [] || $this->resets !== [] || $this->resetAll;
    }

    /**
     * Merge pending counts into the option in one write, pruning old days.
     */
    public function flush(): void
    {
        if (!$this->hasPending()) {
            return;
        }
        $stored = $this->stored();
        foreach ($this->pendingDays as $day => $rules) {
            foreach ($rules as $ruleId => $counts) {
                $current = $stored['days'][$day][$ruleId] ?? [0, 0, 0];
                $stored['days'][$day][$ruleId] = [
                    $current[0] + $counts[0],
                    $current[1] + $counts[1],
                    $current[2] + $counts[2],
                ];
            }
        }
        foreach ($this->pendingRules as $ruleId => $fields) {
            $stored['rules'][$ruleId] = array_merge($stored['rules'][$ruleId] ?? [], $fields);
        }
        $resetIds = $this->resetAll ? array_keys($stored['rules']) : $this->resets;
        foreach ($resetIds as $ruleId) {
            unset($stored['rules'][$ruleId]['last_error'], $stored['rules'][$ruleId]['last_error_time']);
            unset($stored['rules'][$ruleId]['warning'], $stored['rules'][$ruleId]['warning_time']);
            foreach ($stored['days'] as $day => $rules) {
                if (isset($rules[$ruleId])) {
                    $stored['days'][$day][$ruleId][self::ERROR] = 0;
                }
            }
        }
        $cutoff = gmdate('Y-m-d', Clock::now() - self::KEEP_DAYS * 86400);
        foreach (array_keys($stored['days']) as $day) {
            if ($day <= $cutoff) {
                unset($stored['days'][$day]);
            }
        }
        ksort($stored['days']);
        update_option(self::OPTION, $stored, false);
        $this->pendingDays = [];
        $this->pendingRules = [];
        $this->resets = [];
        $this->resetAll = false;
    }

    /**
     * Totals for a rule over the last $days days, including today.
     *
     * @return array{hits: int, would: int, errors: int}
     */
    public function totals(string $ruleId, int $days = 1): array
    {
        $stored = $this->stored();
        $out = ['hits' => 0, 'would' => 0, 'errors' => 0];
        for ($i = 0; $i < $days; $i++) {
            $day = gmdate('Y-m-d', Clock::now() - $i * 86400);
            $counts = $stored['days'][$day][$ruleId] ?? [0, 0, 0];
            $out['hits'] += $counts[0];
            $out['would'] += $counts[1];
            $out['errors'] += $counts[2];
        }
        return $out;
    }

    public function invalidToday(): int
    {
        return $this->totals(self::INVALID)['hits'];
    }

    /**
     * Badges and last-hit time for a rule.
     *
     * @return array<string, int|string>
     */
    public function ruleInfo(string $ruleId): array
    {
        return $this->stored()['rules'][$ruleId] ?? [];
    }

    /**
     * Rule ids that carry an error badge.
     *
     * @return list<string>
     */
    public function rulesWithErrors(): array
    {
        $out = [];
        foreach ($this->stored()['rules'] as $ruleId => $info) {
            if (isset($info['last_error'])) {
                $out[] = (string) $ruleId;
            }
        }
        return $out;
    }

    /**
     * @return array{days: array<string, array<string, array{0: int, 1: int, 2: int}>>,
     *     rules: array<string, array<string, int|string>>}
     */
    public function stored(): array
    {
        $raw = get_option(self::OPTION, []);
        $days = [];
        $rules = [];
        if (is_array($raw)) {
            foreach (is_array($raw['days'] ?? null) ? $raw['days'] : [] as $day => $entries) {
                if (!is_array($entries)) {
                    continue;
                }
                foreach ($entries as $ruleId => $counts) {
                    if (is_array($counts)) {
                        $days[(string) $day][(string) $ruleId] = [
                            (int) ($counts[0] ?? 0),
                            (int) ($counts[1] ?? 0),
                            (int) ($counts[2] ?? 0),
                        ];
                    }
                }
            }
            foreach (is_array($raw['rules'] ?? null) ? $raw['rules'] : [] as $ruleId => $info) {
                if (is_array($info)) {
                    $clean = [];
                    foreach ($info as $k => $v) {
                        if (is_int($v) || is_string($v)) {
                            $clean[(string) $k] = $v;
                        }
                    }
                    $rules[(string) $ruleId] = $clean;
                }
            }
        }
        return ['days' => $days, 'rules' => $rules];
    }

    private function bump(string $ruleId, int $slot): void
    {
        $day = gmdate('Y-m-d', Clock::now());
        [$hits, $would, $errors] = $this->pendingDays[$day][$ruleId] ?? [0, 0, 0];
        $this->pendingDays[$day][$ruleId] = [
            $hits + ($slot === self::HIT ? 1 : 0),
            $would + ($slot === self::WOULD ? 1 : 0),
            $errors + ($slot === self::ERROR ? 1 : 0),
        ];
    }
}
