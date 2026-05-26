<?php

declare(strict_types=1);

namespace App\Service\Retainer;

use App\Enums\RetainerPeriodMode;
use App\Enums\RetainerPeriodUnit;
use App\Models\Retainer;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class AllocationCalculator
{
    /**
     * @throws \InvalidArgumentException if recurring retainer is missing period_unit or seconds_per_period
     */
    public function computeAllocated(Retainer $retainer, Carbon $asOf): int
    {
        $start = $retainer->starts_at->copy()->startOfDay();
        $asOf = $asOf->copy()->startOfDay();

        if ($asOf->lte($start)) {
            return 0;
        }

        if ($retainer->ends_at !== null && $asOf->gt($retainer->ends_at->copy()->startOfDay())) {
            $asOf = $retainer->ends_at->copy()->startOfDay();
        }

        return match ($retainer->period_mode) {
            RetainerPeriodMode::Calendar => $this->computeRecurring($retainer, $asOf, anchored: false),
            RetainerPeriodMode::Anchor => $this->computeRecurring($retainer, $asOf, anchored: true),
            RetainerPeriodMode::Explicit => $this->computeExplicit($retainer, $asOf),
        };
    }

    private function computeRecurring(Retainer $retainer, Carbon $asOf, bool $anchored): int
    {
        if ($retainer->period_unit === null || $retainer->seconds_per_period === null) {
            throw new InvalidArgumentException('Recurring retainer requires period_unit and seconds_per_period');
        }

        $rate = $retainer->seconds_per_period;
        $cursor = $anchored
            ? $retainer->anchor_date->copy()->startOfDay()
            : $this->periodStartFor($retainer->starts_at, $retainer->period_unit);

        $allocated = 0;
        $effectiveStart = $retainer->starts_at->copy()->startOfDay();

        while (true) {
            $nextPeriodStart = $this->nextPeriodStartFor($cursor, $retainer->period_unit, $anchored);
            $effPeriodStart = $cursor->lt($effectiveStart) ? $effectiveStart->copy() : $cursor->copy();

            if ($nextPeriodStart->lte($asOf)) {
                // This period is complete — count all effective days in it
                $fullDays = $this->daysBetween($cursor, $nextPeriodStart);
                $effDays = $this->daysBetween($effPeriodStart, $nextPeriodStart);
                if ($fullDays > 0) {
                    $allocated += (int) round(($effDays / $fullDays) * $rate);
                }
                $cursor = $nextPeriodStart->copy();

                continue;
            }

            // Partial (current) period
            $fullDays = $this->daysBetween($cursor, $nextPeriodStart);
            $usedDays = $this->usedDays($effPeriodStart, $cursor, $asOf);
            if ($fullDays > 0) {
                $allocated += (int) round((max(0, $usedDays) / $fullDays) * $rate);
            }
            break;
        }

        return $allocated;
    }

    /**
     * Compute used days in the current (partial) period.
     *
     * When the retainer started at the very beginning of this period (effPeriodStart == cursor),
     * we count the asOf day itself as a full elapsed day (+1 inclusive), unless asOf is the
     * cursor itself (meaning no time has elapsed in this period yet).
     *
     * When the retainer started mid-period (effPeriodStart > cursor), we count only complete
     * days from the retainer start to asOf (exclusive), keeping the delta accurate.
     */
    private function usedDays(Carbon $effPeriodStart, Carbon $cursor, Carbon $asOf): int
    {
        $days = $this->daysBetween($effPeriodStart, $asOf);

        // Apply inclusive counting only when retainer started at the period boundary
        // and asOf is strictly after effPeriodStart (i.e., at least one day has elapsed).
        if ($effPeriodStart->isSameDay($cursor) && $asOf->gt($effPeriodStart)) {
            $days += 1;
        }

        return $days;
    }

    private function computeExplicit(Retainer $retainer, Carbon $asOf): int
    {
        $periods = $retainer->periods()->orderBy('starts_at')->get();

        $allocated = 0;
        foreach ($periods as $p) {
            $pStart = $p->starts_at->copy()->startOfDay();
            // Treat ends_at as inclusive; next day is the exclusive boundary
            $pNextStart = $p->ends_at->copy()->startOfDay()->addDay();

            if ($pNextStart->lte($asOf)) {
                $allocated += $p->seconds_allocated;

                continue;
            }
            if ($pStart->gte($asOf)) {
                break;
            }

            $fullDays = $this->daysBetween($pStart, $pNextStart);
            // In explicit periods the retainer always starts at pStart, so use inclusive counting
            $usedDays = $this->daysBetween($pStart, $asOf) + 1;
            if ($fullDays > 0) {
                $allocated += (int) round(($usedDays / $fullDays) * $p->seconds_allocated);
            }
        }

        return $allocated;
    }

    private function daysBetween(Carbon $a, Carbon $b): int
    {
        return (int) $a->copy()->startOfDay()->utc()->diffInDays($b->copy()->startOfDay()->utc(), absolute: true);
    }

    private function periodStartFor(Carbon $date, RetainerPeriodUnit $unit): Carbon
    {
        return match ($unit) {
            RetainerPeriodUnit::Weekly => $date->copy()->startOfWeek(Carbon::MONDAY),
            RetainerPeriodUnit::Monthly => $date->copy()->startOfMonth(),
            RetainerPeriodUnit::Quarterly => $date->copy()->firstOfQuarter()->startOfDay(),
        };
    }

    private function nextPeriodStartFor(Carbon $cursor, RetainerPeriodUnit $unit, bool $anchored): Carbon
    {
        if ($anchored) {
            return match ($unit) {
                RetainerPeriodUnit::Weekly => $cursor->copy()->addWeek()->startOfDay(),
                RetainerPeriodUnit::Monthly => $cursor->copy()->addMonth()->startOfDay(),
                RetainerPeriodUnit::Quarterly => $cursor->copy()->addMonths(3)->startOfDay(),
            };
        }

        return match ($unit) {
            RetainerPeriodUnit::Weekly => $cursor->copy()->addWeek()->startOfDay(),
            RetainerPeriodUnit::Monthly => $cursor->copy()->endOfMonth()->addDay()->startOfDay(),
            RetainerPeriodUnit::Quarterly => $cursor->copy()->lastOfQuarter()->addDay()->startOfDay(),
        };
    }
}
