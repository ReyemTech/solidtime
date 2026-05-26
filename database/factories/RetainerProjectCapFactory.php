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
            'project_id' => Project::factory(),
            'seconds_per_period' => 20 * 3600,
            'seconds_cumulative' => null,
        ];
    }
}
