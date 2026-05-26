<?php

declare(strict_types=1);

namespace Tests\Unit\Endpoint\Api\V1;

use App\Models\Client;
use App\Models\Retainer;
use Laravel\Passport\Passport;

class RetainerEndpointTest extends ApiEndpointTestAbstract
{
    public function test_index_returns_retainers_for_organization(): void
    {
        $data = $this->createUserWithPermission(['retainers:view']);
        $client = Client::factory()->forOrganization($data->organization)->create();
        Retainer::factory()->count(2)->forOrganization($data->organization)->create(['client_id' => $client->id]);

        Passport::actingAs($data->user);
        $response = $this->getJson(route('api.v1.retainers.index', ['organization' => $data->organization->id]));

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_index_forbidden_without_permission(): void
    {
        $data = $this->createUserWithPermission([]);
        Passport::actingAs($data->user);
        $response = $this->getJson(route('api.v1.retainers.index', ['organization' => $data->organization->id]));
        $response->assertForbidden();
    }

    public function test_store_creates_retainer_with_minimum_fields(): void
    {
        $data = $this->createUserWithPermission(['retainers:create']);
        $client = Client::factory()->forOrganization($data->organization)->create();

        $payload = [
            'client_id' => $client->id,
            'name' => 'Acme Monthly',
            'period_mode' => 'calendar',
            'period_unit' => 'monthly',
            'seconds_per_period' => 40 * 3600,
            'starts_at' => '2026-06-01',
        ];

        Passport::actingAs($data->user);
        $response = $this->postJson(route('api.v1.retainers.store', ['organization' => $data->organization->id]), $payload);

        $response->assertCreated()
            ->assertJsonPath('data.client_id', $client->id)
            ->assertJsonPath('data.name', 'Acme Monthly');
        $this->assertDatabaseCount('retainers', 1);
    }

    public function test_store_rejects_when_client_belongs_to_other_org(): void
    {
        $data = $this->createUserWithPermission(['retainers:create']);
        $otherOrgClient = Client::factory()->create();

        Passport::actingAs($data->user);
        $response = $this->postJson(
            route('api.v1.retainers.store', ['organization' => $data->organization->id]),
            [
                'client_id' => $otherOrgClient->id, 'name' => 'X',
                'period_mode' => 'calendar', 'period_unit' => 'monthly',
                'seconds_per_period' => 100, 'starts_at' => '2026-06-01',
            ],
        );
        $response->assertUnprocessable();
    }

    public function test_show_returns_retainer(): void
    {
        $data = $this->createUserWithPermission(['retainers:view']);
        $client = Client::factory()->forOrganization($data->organization)->create();
        $retainer = Retainer::factory()->forOrganization($data->organization)->create(['client_id' => $client->id]);

        Passport::actingAs($data->user);
        $response = $this->getJson(route('api.v1.retainers.show', [
            'organization' => $data->organization->id, 'retainer' => $retainer->id,
        ]));

        $response->assertOk()->assertJsonPath('data.id', $retainer->id);
    }

    public function test_update_changes_name(): void
    {
        $data = $this->createUserWithPermission(['retainers:update']);
        $client = Client::factory()->forOrganization($data->organization)->create();
        $retainer = Retainer::factory()->forOrganization($data->organization)->create(['client_id' => $client->id]);

        Passport::actingAs($data->user);
        $response = $this->putJson(
            route('api.v1.retainers.update', ['organization' => $data->organization->id, 'retainer' => $retainer->id]),
            ['name' => 'New name'],
        );

        $response->assertOk()->assertJsonPath('data.name', 'New name');
    }

    public function test_destroy_soft_deletes(): void
    {
        $data = $this->createUserWithPermission(['retainers:delete']);
        $client = Client::factory()->forOrganization($data->organization)->create();
        $retainer = Retainer::factory()->forOrganization($data->organization)->create(['client_id' => $client->id]);

        Passport::actingAs($data->user);
        $response = $this->deleteJson(route('api.v1.retainers.destroy', [
            'organization' => $data->organization->id, 'retainer' => $retainer->id,
        ]));

        $response->assertNoContent();
        $this->assertSoftDeleted('retainers', ['id' => $retainer->id]);
    }

    public function test_status_returns_allocated_tracked_delta(): void
    {
        $data = $this->createUserWithPermission(['retainers:view']);
        $client = Client::factory()->forOrganization($data->organization)->create();
        $retainer = Retainer::factory()->forOrganization($data->organization)->create([
            'client_id' => $client->id,
            'starts_at' => '2026-06-01',
            'seconds_per_period' => 40 * 3600,
        ]);

        Passport::actingAs($data->user);
        $response = $this->getJson(route('api.v1.retainers.status', [
            'organization' => $data->organization->id, 'retainer' => $retainer->id,
        ]).'?as_of=2026-06-30');

        $response->assertOk()->assertJsonStructure([
            'data' => ['as_of', 'allocated_seconds', 'tracked_seconds', 'delta_seconds', 'percent'],
        ]);
    }

    public function test_overlapping_retainer_for_same_client_rejected(): void
    {
        $data = $this->createUserWithPermission(['retainers:create']);
        $client = Client::factory()->forOrganization($data->organization)->create();
        Retainer::factory()->forOrganization($data->organization)->create([
            'client_id' => $client->id,
            'starts_at' => '2026-06-01',
            'ends_at' => '2026-12-31',
        ]);

        Passport::actingAs($data->user);
        $response = $this->postJson(
            route('api.v1.retainers.store', ['organization' => $data->organization->id]),
            [
                'client_id' => $client->id, 'name' => 'Overlap',
                'period_mode' => 'calendar', 'period_unit' => 'monthly',
                'seconds_per_period' => 100, 'starts_at' => '2026-08-01',
            ],
        );

        $response->assertUnprocessable()->assertJsonValidationErrors(['starts_at']);
    }
}
