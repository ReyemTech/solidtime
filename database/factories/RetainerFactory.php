<?php
declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RetainerPeriodMode;
use App\Enums\RetainerPeriodUnit;
use App\Enums\RetainerSubCapMode;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Retainer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Retainer>
 */
class RetainerFactory extends Factory
{
    protected $model = Retainer::class;

    public function definition(): array
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->create(['organization_id' => $organization->id]);

        return [
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'name' => 'Retainer for '.$client->name,
            'description' => null,
            'period_mode' => RetainerPeriodMode::Calendar,
            'period_unit' => RetainerPeriodUnit::Monthly,
            'seconds_per_period' => 40 * 3600,
            'anchor_date' => null,
            'starts_at' => Carbon::today()->startOfMonth(),
            'ends_at' => null,
            'billable_only' => true,
            'hard_cap_enabled' => false,
            'hard_cap_scope' => null,
            'hard_cap_enforcement' => null,
            'hard_cap_cumulative_seconds' => null,
            'sub_cap_mode' => RetainerSubCapMode::Soft,
        ];
    }

    public function forOrganization(Organization $organization): self
    {
        return $this->state(function () use ($organization) {
            $client = Client::factory()->create(['organization_id' => $organization->id]);

            return [
                'organization_id' => $organization->id,
                'client_id' => $client->id,
            ];
        });
    }

    public function withHardCap(string $scope = 'per_period', string $enforcement = 'block'): self
    {
        return $this->state(fn () => [
            'hard_cap_enabled' => true,
            'hard_cap_scope' => $scope,
            'hard_cap_enforcement' => $enforcement,
            'hard_cap_cumulative_seconds' => $scope === 'cumulative' ? 200 * 3600 : null,
        ]);
    }
}
