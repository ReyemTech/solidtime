<?php
declare(strict_types=1);

namespace Database\Factories;

use App\Models\Project;
use App\Models\Retainer;
use App\Models\RetainerProjectCap;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RetainerProjectCap>
 */
class RetainerProjectCapFactory extends Factory
{
    protected $model = RetainerProjectCap::class;

    public function definition(): array
    {
        return [
            'retainer_id' => Retainer::factory(),
            'project_id' => null,
            'seconds_per_period' => 20 * 3600,
            'seconds_cumulative' => null,
        ];
    }

    public function configure(): self
    {
        return $this->afterMaking(function (RetainerProjectCap $cap) {
            if ($cap->project_id === null) {
                $retainer = $cap->retainer ?? Retainer::find($cap->retainer_id);
                $project = Project::factory()->create([
                    'organization_id' => $retainer->organization_id,
                    'client_id' => $retainer->client_id,
                ]);
                $cap->project_id = $project->id;
            }
        });
    }

    public function forRetainer(Retainer $retainer): self
    {
        return $this->state(fn () => [
            'retainer_id' => $retainer->id,
            'project_id' => Project::factory()->create([
                'organization_id' => $retainer->organization_id,
                'client_id' => $retainer->client_id,
            ])->id,
        ]);
    }
}
