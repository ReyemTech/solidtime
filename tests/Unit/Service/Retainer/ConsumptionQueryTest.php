<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Retainer;

use App\Models\Client;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Retainer;
use App\Models\TimeEntry;
use App\Models\User;
use App\Service\Retainer\ConsumptionQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ConsumptionQueryTest extends TestCase
{
    use RefreshDatabase;

    private ConsumptionQuery $query;

    protected function setUp(): void
    {
        parent::setUp();
        $this->query = new ConsumptionQuery;
    }

    private function makeContext(): array
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create();
        $member = Member::factory()->forUser($user)->forOrganization($org)->create();
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $project = Project::factory()->create(['organization_id' => $org->id, 'client_id' => $client->id]);

        return compact('org', 'user', 'member', 'client', 'project');
    }

    private function makeEntry(array $context, string $start, string $end, bool $billable = true, ?Project $project = null): TimeEntry
    {
        return TimeEntry::factory()->create([
            'organization_id' => $context['org']->id,
            'user_id' => $context['user']->id,
            'member_id' => $context['member']->id,
            'project_id' => ($project ?? $context['project'])->id,
            'client_id' => $context['client']->id,
            'start' => $start,
            'end' => $end,
            'billable' => $billable,
        ]);
    }

    public function test_sums_billable_entries_within_window(): void
    {
        $ctx = $this->makeContext();
        $this->makeEntry($ctx, '2026-06-01 09:00', '2026-06-01 11:00'); // 2h
        $this->makeEntry($ctx, '2026-06-02 14:00', '2026-06-02 15:30'); // 1.5h

        $retainer = Retainer::factory()->create([
            'organization_id' => $ctx['org']->id,
            'client_id' => $ctx['client']->id,
            'starts_at' => '2026-06-01',
            'billable_only' => true,
        ]);

        $result = $this->query->computeTracked($retainer, Carbon::parse('2026-06-30 23:59:59'));
        $this->assertSame((int) (3.5 * 3600), $result);
    }

    public function test_excludes_non_billable_when_billable_only(): void
    {
        $ctx = $this->makeContext();
        $this->makeEntry($ctx, '2026-06-01 09:00', '2026-06-01 11:00', billable: true);
        $this->makeEntry($ctx, '2026-06-01 12:00', '2026-06-01 14:00', billable: false);

        $retainer = Retainer::factory()->create([
            'organization_id' => $ctx['org']->id,
            'client_id' => $ctx['client']->id,
            'starts_at' => '2026-06-01',
            'billable_only' => true,
        ]);

        $this->assertSame(2 * 3600, $this->query->computeTracked($retainer, Carbon::parse('2026-06-30 23:59:59')));
    }

    public function test_includes_non_billable_when_billable_only_disabled(): void
    {
        $ctx = $this->makeContext();
        $this->makeEntry($ctx, '2026-06-01 09:00', '2026-06-01 11:00', billable: true);
        $this->makeEntry($ctx, '2026-06-01 12:00', '2026-06-01 14:00', billable: false);

        $retainer = Retainer::factory()->create([
            'organization_id' => $ctx['org']->id,
            'client_id' => $ctx['client']->id,
            'starts_at' => '2026-06-01',
            'billable_only' => false,
        ]);

        $this->assertSame(4 * 3600, $this->query->computeTracked($retainer, Carbon::parse('2026-06-30 23:59:59')));
    }

    public function test_excludes_entries_before_retainer_starts_at(): void
    {
        $ctx = $this->makeContext();
        $this->makeEntry($ctx, '2026-05-30 09:00', '2026-05-30 11:00');
        $this->makeEntry($ctx, '2026-06-01 09:00', '2026-06-01 10:00');

        $retainer = Retainer::factory()->create([
            'organization_id' => $ctx['org']->id,
            'client_id' => $ctx['client']->id,
            'starts_at' => '2026-06-01',
        ]);

        $this->assertSame(3600, $this->query->computeTracked($retainer, Carbon::parse('2026-06-30 23:59:59')));
    }

    public function test_excludes_still_running_entries(): void
    {
        $ctx = $this->makeContext();
        $this->makeEntry($ctx, '2026-06-01 09:00', '2026-06-01 10:00');
        TimeEntry::factory()->create([
            'organization_id' => $ctx['org']->id,
            'user_id' => $ctx['user']->id,
            'member_id' => $ctx['member']->id,
            'project_id' => $ctx['project']->id,
            'client_id' => $ctx['client']->id,
            'start' => '2026-06-15 09:00',
            'end' => null,
            'billable' => true,
        ]);

        $retainer = Retainer::factory()->create([
            'organization_id' => $ctx['org']->id,
            'client_id' => $ctx['client']->id,
            'starts_at' => '2026-06-01',
        ]);

        $this->assertSame(3600, $this->query->computeTracked($retainer, Carbon::parse('2026-06-30 23:59:59')));
    }

    public function test_filters_by_project_id_for_sub_cap(): void
    {
        $ctx = $this->makeContext();
        $project2 = Project::factory()->create([
            'organization_id' => $ctx['org']->id,
            'client_id' => $ctx['client']->id,
        ]);
        $this->makeEntry($ctx, '2026-06-01 09:00', '2026-06-01 11:00');                       // 2h on project 1
        $this->makeEntry($ctx, '2026-06-02 09:00', '2026-06-02 13:00', project: $project2);  // 4h on project 2

        $retainer = Retainer::factory()->create([
            'organization_id' => $ctx['org']->id,
            'client_id' => $ctx['client']->id,
            'starts_at' => '2026-06-01',
        ]);

        $this->assertSame(2 * 3600, $this->query->computeTracked($retainer, Carbon::parse('2026-06-30 23:59:59'), $ctx['project']->id));
        $this->assertSame(4 * 3600, $this->query->computeTracked($retainer, Carbon::parse('2026-06-30 23:59:59'), $project2->id));
        $this->assertSame(6 * 3600, $this->query->computeTracked($retainer, Carbon::parse('2026-06-30 23:59:59')));
    }

    public function test_returns_zero_when_no_entries(): void
    {
        $ctx = $this->makeContext();
        $retainer = Retainer::factory()->create([
            'organization_id' => $ctx['org']->id,
            'client_id' => $ctx['client']->id,
            'starts_at' => '2026-06-01',
        ]);

        $this->assertSame(0, $this->query->computeTracked($retainer, Carbon::parse('2026-06-30 23:59:59')));
    }

    public function test_compute_tracked_in_window_respects_custom_bounds(): void
    {
        $ctx = $this->makeContext();
        $this->makeEntry($ctx, '2026-05-20 09:00', '2026-05-20 11:00'); // 2h before window
        $this->makeEntry($ctx, '2026-05-25 09:00', '2026-05-25 12:00'); // 3h inside window
        $this->makeEntry($ctx, '2026-06-01 09:00', '2026-06-01 10:00'); // 1h after window

        $retainer = Retainer::factory()->create([
            'organization_id' => $ctx['org']->id,
            'client_id' => $ctx['client']->id,
            'starts_at' => '2026-05-01',
            'billable_only' => true,
        ]);

        $result = $this->query->computeTrackedInWindow(
            $retainer,
            Carbon::parse('2026-05-25 00:00'),
            Carbon::parse('2026-05-31 23:59:59'),
        );
        $this->assertSame(3 * 3600, $result);
    }
}
