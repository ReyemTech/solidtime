<?php
declare(strict_types=1);

namespace App\Service\Retainer;

use App\Enums\RetainerHardCapEnforcement;
use App\Enums\RetainerHardCapScope;
use App\Enums\RetainerSubCapMode;
use App\Exceptions\Api\RetainerCapExceededException;
use App\Models\Retainer;
use App\Models\TimeEntry;
use Illuminate\Support\Carbon;

class CapEnforcer
{
    public function __construct(
        private readonly RetainerLookup $lookup,
        private readonly AllocationCalculator $allocation,
        private readonly ConsumptionQuery $consumption,  // intentionally uncached on write path
    ) {}

    public function enforceForEntry(TimeEntry $entry): void
    {
        if ($entry->end === null || $entry->project === null || $entry->project->client_id === null) {
            return;
        }

        $retainer = $this->lookup->findActiveForClient(
            $entry->project->client_id,
            Carbon::parse($entry->end),
        );

        if ($retainer === null || ! $retainer->hard_cap_enabled) {
            return;
        }

        if ($retainer->hard_cap_enforcement !== RetainerHardCapEnforcement::Block) {
            return; // flag/approval reserved for v1.1
        }

        $delta = $this->entryDurationDelta($entry);
        if ($delta <= 0) {
            return;
        }

        $this->checkParentCap($retainer, $entry, $delta);

        if ($retainer->sub_cap_mode === RetainerSubCapMode::Strict) {
            $this->checkSubCap($retainer, $entry, $delta);
        }
    }

    private function checkParentCap(Retainer $retainer, TimeEntry $entry, int $delta): void
    {
        $asOf = Carbon::parse($entry->end);
        $currentTracked = $this->consumption->computeTracked($retainer, $asOf);
        $cap = $retainer->hard_cap_scope === RetainerHardCapScope::Cumulative
            ? (int) $retainer->hard_cap_cumulative_seconds
            : $this->allocation->computeAllocated($retainer, $asOf);

        if ($currentTracked + $delta > $cap) {
            throw new RetainerCapExceededException($retainer->id, $cap, $currentTracked, $delta);
        }
    }

    private function checkSubCap(Retainer $retainer, TimeEntry $entry, int $delta): void
    {
        $cap = $retainer->projectCaps()->where('project_id', $entry->project_id)->first();
        if ($cap === null) {
            return;
        }
        $asOf = Carbon::parse($entry->end);
        $currentTracked = $this->consumption->computeTracked($retainer, $asOf, $entry->project_id);
        $capSeconds = $retainer->hard_cap_scope === RetainerHardCapScope::Cumulative
            ? (int) $cap->seconds_cumulative
            : (int) $cap->seconds_per_period;

        if ($currentTracked + $delta > $capSeconds) {
            throw new RetainerCapExceededException($retainer->id, $capSeconds, $currentTracked, $delta);
        }
    }

    private function entryDurationDelta(TimeEntry $entry): int
    {
        $newDuration = Carbon::parse($entry->end)->diffInSeconds(Carbon::parse($entry->start), absolute: true);

        if (! $entry->exists) {
            return (int) $newDuration;
        }

        $origStart = $entry->getOriginal('start');
        $origEnd = $entry->getOriginal('end');
        if ($origStart === null || $origEnd === null) {
            return (int) $newDuration;
        }
        $oldDuration = Carbon::parse($origEnd)->diffInSeconds(Carbon::parse($origStart), absolute: true);
        return (int) ($newDuration - $oldDuration);
    }
}
