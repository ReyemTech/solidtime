<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Retainer;
use App\Models\RetainerPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<RetainerPeriod>
 */
class RetainerPeriodFactory extends Factory
{
    protected $model = RetainerPeriod::class;

    public function definition(): array
    {
        return [
            'retainer_id' => Retainer::factory(),
            'starts_at' => Carbon::today()->startOfMonth(),
            'ends_at' => Carbon::today()->endOfMonth(),
            'seconds_allocated' => 40 * 3600,
        ];
    }
}
