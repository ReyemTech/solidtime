<?php

declare(strict_types=1);

namespace Tests\Unit\Endpoint\Api\V1;

use App\Enums\RetainerPeriodMode;
use App\Models\Client;
use App\Models\Retainer;
use Laravel\Passport\Passport;

class RetainerPeriodEndpointTest extends ApiEndpointTestAbstract
{
    public function test_replace_sets_periods(): void
    {
        $data = $this->createUserWithPermission(['retainers:update']);
        $client = Client::factory()->forOrganization($data->organization)->create();
        $retainer = Retainer::factory()->forOrganization($data->organization)->create([
            'client_id' => $client->id,
            'period_mode' => RetainerPeriodMode::Explicit,
            'period_unit' => null,
            'seconds_per_period' => null,
        ]);

        Passport::actingAs($data->user);
        $response = $this->putJson(
            route('api.v1.retainers.periods.replace', [
                'organization' => $data->organization->id, 'retainer' => $retainer->id,
            ]),
            ['periods' => [
                ['starts_at' => '2026-01-01', 'ends_at' => '2026-01-31', 'seconds_allocated' => 144000],
                ['starts_at' => '2026-02-01', 'ends_at' => '2026-02-28', 'seconds_allocated' => 216000],
            ]],
        );
        $response->assertOk();
        $this->assertSame(2, $retainer->periods()->count());
    }

    public function test_replace_rejects_overlapping_periods(): void
    {
        $data = $this->createUserWithPermission(['retainers:update']);
        $client = Client::factory()->forOrganization($data->organization)->create();
        $retainer = Retainer::factory()->forOrganization($data->organization)->create([
            'client_id' => $client->id,
            'period_mode' => RetainerPeriodMode::Explicit,
        ]);

        Passport::actingAs($data->user);
        $response = $this->putJson(
            route('api.v1.retainers.periods.replace', [
                'organization' => $data->organization->id, 'retainer' => $retainer->id,
            ]),
            ['periods' => [
                ['starts_at' => '2026-01-01', 'ends_at' => '2026-02-15', 'seconds_allocated' => 100],
                ['starts_at' => '2026-02-01', 'ends_at' => '2026-02-28', 'seconds_allocated' => 100],
            ]],
        );
        $response->assertUnprocessable();
    }
}
