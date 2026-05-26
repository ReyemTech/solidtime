<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Retainer;

use App\Models\Client;
use App\Models\Organization;
use App\Models\Retainer;
use App\Service\Retainer\RetainerLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RetainerLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_null_when_no_retainer_exists(): void
    {
        $client = Client::factory()->create();
        $this->assertNull((new RetainerLookup)->findActiveForClient($client->id, Carbon::today()));
    }

    public function test_returns_retainer_when_date_is_within_window(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $retainer = Retainer::factory()->create([
            'organization_id' => $org->id,
            'client_id' => $client->id,
            'starts_at' => '2026-06-01',
            'ends_at' => '2026-12-31',
        ]);

        $result = (new RetainerLookup)->findActiveForClient($client->id, Carbon::parse('2026-09-15'));
        $this->assertNotNull($result);
        $this->assertSame($retainer->id, $result->id);
    }

    public function test_returns_null_when_date_before_starts_at(): void
    {
        $client = Client::factory()->create();
        Retainer::factory()->create([
            'organization_id' => $client->organization_id,
            'client_id' => $client->id,
            'starts_at' => '2026-06-01',
        ]);

        $this->assertNull((new RetainerLookup)->findActiveForClient($client->id, Carbon::parse('2026-05-31')));
    }

    public function test_returns_null_when_date_after_ends_at(): void
    {
        $client = Client::factory()->create();
        Retainer::factory()->create([
            'organization_id' => $client->organization_id,
            'client_id' => $client->id,
            'starts_at' => '2026-06-01',
            'ends_at' => '2026-08-31',
        ]);

        $this->assertNull((new RetainerLookup)->findActiveForClient($client->id, Carbon::parse('2026-09-01')));
    }

    public function test_open_ended_retainer_matches_any_date_after_start(): void
    {
        $client = Client::factory()->create();
        Retainer::factory()->create([
            'organization_id' => $client->organization_id,
            'client_id' => $client->id,
            'starts_at' => '2026-06-01',
            'ends_at' => null,
        ]);

        $this->assertNotNull((new RetainerLookup)->findActiveForClient($client->id, Carbon::parse('2030-01-01')));
    }

    public function test_excludes_soft_deleted_retainers(): void
    {
        $client = Client::factory()->create();
        $retainer = Retainer::factory()->create([
            'organization_id' => $client->organization_id,
            'client_id' => $client->id,
            'starts_at' => '2026-06-01',
        ]);
        $retainer->delete();

        $this->assertNull((new RetainerLookup)->findActiveForClient($client->id, Carbon::parse('2026-07-01')));
    }
}
