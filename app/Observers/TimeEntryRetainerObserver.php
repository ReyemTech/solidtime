<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\TimeEntry;
use App\Service\Retainer\CapEnforcer;
use App\Service\Retainer\RetainerCache;
use App\Service\Retainer\RetainerLookup;
use Illuminate\Support\Carbon;

class TimeEntryRetainerObserver
{
    public function __construct(
        private readonly CapEnforcer $enforcer,
        private readonly RetainerLookup $lookup,
        private readonly RetainerCache $cache,
    ) {}

    public function saving(TimeEntry $entry): void
    {
        $this->enforcer->enforceForEntry($entry);
    }

    public function saved(TimeEntry $entry): void
    {
        $this->invalidateFor($entry);
    }

    public function deleted(TimeEntry $entry): void
    {
        $this->invalidateFor($entry);
    }

    private function invalidateFor(TimeEntry $entry): void
    {
        if ($entry->end === null || $entry->project === null || $entry->project->client_id === null) {
            return;
        }
        $retainer = $this->lookup->findActiveForClient(
            $entry->project->client_id,
            Carbon::parse($entry->end),
        );
        if ($retainer !== null) {
            $this->cache->invalidate($retainer);
        }
    }
}
