<?php
declare(strict_types=1);

namespace Tests\Unit\Endpoint\Api\V1;

use App\Models\Client;
use App\Models\Project;
use App\Models\Retainer;
use App\Models\RetainerProjectCap;
use Laravel\Passport\Passport;

class RetainerProjectCapEndpointTest extends ApiEndpointTestAbstract
{
    public function test_index_returns_caps(): void
    {
        $data = $this->createUserWithPermission(['retainers:view']);
        $client = Client::factory()->forOrganization($data->organization)->create();
        $retainer = Retainer::factory()->forOrganization($data->organization)->create(['client_id' => $client->id]);
        $project = Project::factory()->create(['organization_id' => $data->organization->id, 'client_id' => $client->id]);
        RetainerProjectCap::factory()->create([
            'retainer_id' => $retainer->id,
            'project_id' => $project->id,
        ]);

        Passport::actingAs($data->user);
        $response = $this->getJson(route('api.v1.retainers.project-caps.index', [
            'organization' => $data->organization->id, 'retainer' => $retainer->id,
        ]));
        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_store_creates_cap(): void
    {
        $data = $this->createUserWithPermission(['retainers:update']);
        $client = Client::factory()->forOrganization($data->organization)->create();
        $retainer = Retainer::factory()->forOrganization($data->organization)->create(['client_id' => $client->id]);
        $project = Project::factory()->create(['organization_id' => $data->organization->id, 'client_id' => $client->id]);

        Passport::actingAs($data->user);
        $response = $this->postJson(
            route('api.v1.retainers.project-caps.store', [
                'organization' => $data->organization->id, 'retainer' => $retainer->id,
            ]),
            ['project_id' => $project->id, 'seconds_per_period' => 20 * 3600],
        );
        $response->assertCreated();
    }

    public function test_store_rejects_project_from_other_client(): void
    {
        $data = $this->createUserWithPermission(['retainers:update']);
        $clientA = Client::factory()->forOrganization($data->organization)->create();
        $clientB = Client::factory()->forOrganization($data->organization)->create();
        $retainer = Retainer::factory()->forOrganization($data->organization)->create(['client_id' => $clientA->id]);
        $foreignProject = Project::factory()->create(['organization_id' => $data->organization->id, 'client_id' => $clientB->id]);

        Passport::actingAs($data->user);
        $response = $this->postJson(
            route('api.v1.retainers.project-caps.store', [
                'organization' => $data->organization->id, 'retainer' => $retainer->id,
            ]),
            ['project_id' => $foreignProject->id, 'seconds_per_period' => 100],
        );
        $response->assertUnprocessable();
    }
}
