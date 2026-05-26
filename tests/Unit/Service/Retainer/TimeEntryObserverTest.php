<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Retainer;

use App\Exceptions\Api\RetainerCapExceededException;
use App\Models\Client;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Retainer;
use App\Models\TimeEntry;
use App\Models\User;
use App\Service\Retainer\RetainerCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TimeEntryObserverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_block_mode_prevents_save_of_over_cap_entry(): void
    {
        [$org, $user, $member, $client, $project] = $this->scaffold();

        Retainer::factory()->withHardCap('per_period', 'block')->create([
            'organization_id' => $org->id,
            'client_id' => $client->id,
            'starts_at' => '2026-06-01',
            'seconds_per_period' => 1 * 3600,  // 1h cap
        ]);

        $this->expectException(RetainerCapExceededException::class);

        TimeEntry::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'member_id' => $member->id,
            'project_id' => $project->id,
            'client_id' => $client->id,
            'start' => '2026-06-15 09:00',
            'end' => '2026-06-15 19:00',   // 10h — exceeds 1h cap
            'billable' => true,
        ]);
    }

    public function test_cache_invalidated_after_time_entry_save(): void
    {
        [$org, $user, $member, $client, $project] = $this->scaffold();

        $retainer = Retainer::factory()->create([
            'organization_id' => $org->id,
            'client_id' => $client->id,
            'starts_at' => '2026-06-01',
        ]);

        // Warm the cache (returns 0 — no entries yet)
        $cache = app(RetainerCache::class);
        $this->assertSame(0, $cache->tracked($retainer, Carbon::parse('2026-06-30 23:59:59')));

        // Save a new entry → observer's saved() should invalidate
        TimeEntry::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'member_id' => $member->id,
            'project_id' => $project->id,
            'client_id' => $client->id,
            'start' => '2026-06-15 09:00',
            'end' => '2026-06-15 11:00',
            'billable' => true,
        ]);

        // Re-read tracked: should now reflect the new entry (2h = 7200s)
        $this->assertSame(2 * 3600, $cache->tracked($retainer, Carbon::parse('2026-06-30 23:59:59')));
    }

    private function scaffold(): array
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create();
        $member = Member::factory()->forUser($user)->forOrganization($org)->create();
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $project = Project::factory()->create(['organization_id' => $org->id, 'client_id' => $client->id]);

        return [$org, $user, $member, $client, $project];
    }
}
