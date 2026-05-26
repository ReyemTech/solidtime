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
use App\Service\Retainer\CapEnforcer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CapEnforcerTest extends TestCase
{
    use RefreshDatabase;

    public function test_skips_when_no_active_retainer(): void
    {
        $entry = $this->makeEntry(billable: true);
        app(CapEnforcer::class)->enforceForEntry($entry);
        $this->expectNotToPerformAssertions();
    }

    public function test_skips_when_retainer_hard_cap_disabled(): void
    {
        $entry = $this->makeEntry(billable: true);
        Retainer::factory()->create([
            'organization_id' => $entry->organization_id,
            'client_id' => $entry->project->client_id,
            'starts_at' => '2026-06-01',
            'hard_cap_enabled' => false,
        ]);
        app(CapEnforcer::class)->enforceForEntry($entry);
        $this->expectNotToPerformAssertions();
    }

    public function test_blocks_when_per_period_cap_exceeded(): void
    {
        $entry = $this->makeEntry(billable: true, start: '2026-06-15 09:00', end: '2026-06-15 19:00'); // 10h
        // Tiny 1h/month cap → 10h entry definitely exceeds
        Retainer::factory()->withHardCap('per_period', 'block')->create([
            'organization_id' => $entry->organization_id,
            'client_id' => $entry->project->client_id,
            'starts_at' => '2026-06-01',
            'seconds_per_period' => 1 * 3600,
        ]);

        $this->expectException(RetainerCapExceededException::class);
        app(CapEnforcer::class)->enforceForEntry($entry);
    }

    public function test_allows_when_under_per_period_cap(): void
    {
        $entry = $this->makeEntry(billable: true, start: '2026-06-15 09:00', end: '2026-06-15 11:00'); // 2h
        Retainer::factory()->withHardCap('per_period', 'block')->create([
            'organization_id' => $entry->organization_id,
            'client_id' => $entry->project->client_id,
            'starts_at' => '2026-06-01',
            'seconds_per_period' => 40 * 3600,
        ]);

        app(CapEnforcer::class)->enforceForEntry($entry);
        $this->expectNotToPerformAssertions();
    }

    public function test_blocks_when_cumulative_cap_exceeded(): void
    {
        $entry = $this->makeEntry(billable: true, start: '2026-06-15 09:00', end: '2026-06-15 19:00');  // 10h
        Retainer::factory()->withHardCap('cumulative', 'block')->create([
            'organization_id' => $entry->organization_id,
            'client_id' => $entry->project->client_id,
            'starts_at' => '2026-06-01',
            'hard_cap_cumulative_seconds' => 5 * 3600,
        ]);

        $this->expectException(RetainerCapExceededException::class);
        app(CapEnforcer::class)->enforceForEntry($entry);
    }

    public function test_flag_enforcement_is_no_op_in_v1(): void
    {
        $entry = $this->makeEntry(billable: true, start: '2026-06-15 09:00', end: '2026-06-15 19:00');
        Retainer::factory()->withHardCap('per_period', 'flag')->create([
            'organization_id' => $entry->organization_id,
            'client_id' => $entry->project->client_id,
            'starts_at' => '2026-06-01',
            'seconds_per_period' => 1 * 3600,  // tiny so it WOULD exceed if wired
        ]);

        // Should NOT throw — flag mode is a no-op in v1
        app(CapEnforcer::class)->enforceForEntry($entry);
        $this->expectNotToPerformAssertions();
    }

    private function makeEntry(bool $billable, string $start = '2026-06-15 09:00', string $end = '2026-06-15 11:00'): TimeEntry
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create();
        $member = Member::factory()->forUser($user)->forOrganization($org)->create();
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $project = Project::factory()->create(['organization_id' => $org->id, 'client_id' => $client->id]);

        // make() not create() — we don't want the observer to fire during test setup
        // Just construct the in-memory entry the enforcer will examine.
        $entry = TimeEntry::factory()->make([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'member_id' => $member->id,
            'project_id' => $project->id,
            'client_id' => $client->id,
            'start' => $start,
            'end' => $end,
            'billable' => $billable,
        ]);
        $entry->setRelation('project', $project);

        return $entry;
    }
}
