<?php

declare(strict_types=1);

namespace Tests\Unit\Model;

use App\Enums\RetainerPeriodMode;
use App\Enums\RetainerPeriodUnit;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Retainer;
use App\Models\RetainerProjectCap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RetainerTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_valid_retainer_with_relationships(): void
    {
        $retainer = Retainer::factory()->create();

        $this->assertNotNull($retainer->id);
        $this->assertInstanceOf(RetainerPeriodMode::class, $retainer->period_mode);
        $this->assertInstanceOf(Organization::class, $retainer->organization);
        $this->assertInstanceOf(Client::class, $retainer->client);
    }

    public function test_default_factory_state_is_monthly_calendar_billable_only_no_cap(): void
    {
        $retainer = Retainer::factory()->create();

        $this->assertSame(RetainerPeriodMode::Calendar, $retainer->period_mode);
        $this->assertSame(RetainerPeriodUnit::Monthly, $retainer->period_unit);
        $this->assertTrue($retainer->billable_only);
        $this->assertFalse($retainer->hard_cap_enabled);
        $this->assertSame(40 * 3600, $retainer->seconds_per_period);
    }

    public function test_retainer_project_cap_factory_creates_project_under_retainer_client(): void
    {
        $cap = RetainerProjectCap::factory()->create();
        $retainer = $cap->retainer;
        $project = $cap->project;

        $this->assertNotNull($retainer);
        $this->assertNotNull($project);
        $this->assertSame($retainer->organization_id, $project->organization_id);
        $this->assertSame($retainer->client_id, $project->client_id);
    }
}
