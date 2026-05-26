<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Retainer;

use App\Enums\RetainerPeriodMode;
use App\Enums\RetainerPeriodUnit;
use App\Models\Retainer;
use App\Service\Retainer\AllocationCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AllocationCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private AllocationCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new AllocationCalculator;
    }

    public function test_returns_zero_before_retainer_starts(): void
    {
        $retainer = Retainer::factory()->make([
            'period_mode' => RetainerPeriodMode::Calendar,
            'period_unit' => RetainerPeriodUnit::Monthly,
            'seconds_per_period' => 40 * 3600,
            'starts_at' => Carbon::parse('2026-06-01'),
            'ends_at' => null,
        ]);

        $this->assertSame(0, $this->calculator->computeAllocated($retainer, Carbon::parse('2026-05-15')));
    }

    public function test_calendar_monthly_first_day_of_first_period(): void
    {
        $retainer = Retainer::factory()->make([
            'period_mode' => RetainerPeriodMode::Calendar,
            'period_unit' => RetainerPeriodUnit::Monthly,
            'seconds_per_period' => 40 * 3600,
            'starts_at' => Carbon::parse('2026-06-01'),
        ]);

        $this->assertSame(0, $this->calculator->computeAllocated($retainer, Carbon::parse('2026-06-01')));
    }

    public function test_calendar_monthly_half_through_first_period(): void
    {
        $retainer = Retainer::factory()->make([
            'period_mode' => RetainerPeriodMode::Calendar,
            'period_unit' => RetainerPeriodUnit::Monthly,
            'seconds_per_period' => 40 * 3600,
            'starts_at' => Carbon::parse('2026-06-01'),
        ]);

        $result = $this->calculator->computeAllocated($retainer, Carbon::parse('2026-06-15'));
        $this->assertEqualsWithDelta(20 * 3600, $result, 3600);
    }

    public function test_calendar_monthly_completed_period_plus_partial(): void
    {
        $retainer = Retainer::factory()->make([
            'period_mode' => RetainerPeriodMode::Calendar,
            'period_unit' => RetainerPeriodUnit::Monthly,
            'seconds_per_period' => 40 * 3600,
            'starts_at' => Carbon::parse('2026-06-01'),
        ]);

        $result = $this->calculator->computeAllocated($retainer, Carbon::parse('2026-07-15'));
        $this->assertEqualsWithDelta(60 * 3600, $result, 3600);
    }

    public function test_calendar_monthly_retainer_starting_mid_period_only_counts_from_start(): void
    {
        $retainer = Retainer::factory()->make([
            'period_mode' => RetainerPeriodMode::Calendar,
            'period_unit' => RetainerPeriodUnit::Monthly,
            'seconds_per_period' => 40 * 3600,
            'starts_at' => Carbon::parse('2026-06-15'),
        ]);

        $result = $this->calculator->computeAllocated($retainer, Carbon::parse('2026-06-30'));
        $this->assertEqualsWithDelta(20 * 3600, $result, 3600);
    }

    public function test_calendar_weekly_one_full_week(): void
    {
        $retainer = Retainer::factory()->make([
            'period_mode' => RetainerPeriodMode::Calendar,
            'period_unit' => RetainerPeriodUnit::Weekly,
            'seconds_per_period' => 10 * 3600,
            'starts_at' => Carbon::parse('2026-06-01'),
        ]);

        $result = $this->calculator->computeAllocated($retainer, Carbon::parse('2026-06-08'));
        $this->assertSame(10 * 3600, $result);
    }

    public function test_calendar_quarterly_completed_q(): void
    {
        $retainer = Retainer::factory()->make([
            'period_mode' => RetainerPeriodMode::Calendar,
            'period_unit' => RetainerPeriodUnit::Quarterly,
            'seconds_per_period' => 120 * 3600,
            'starts_at' => Carbon::parse('2026-01-01'),
        ]);

        $result = $this->calculator->computeAllocated($retainer, Carbon::parse('2026-04-01'));
        $this->assertSame(120 * 3600, $result);
    }

    public function test_anchor_monthly_anchored_to_mid_month(): void
    {
        $retainer = Retainer::factory()->make([
            'period_mode' => RetainerPeriodMode::Anchor,
            'period_unit' => RetainerPeriodUnit::Monthly,
            'seconds_per_period' => 40 * 3600,
            'anchor_date' => Carbon::parse('2026-06-15'),
            'starts_at' => Carbon::parse('2026-06-15'),
        ]);

        $result = $this->calculator->computeAllocated($retainer, Carbon::parse('2026-07-15'));
        $this->assertSame(40 * 3600, $result);
    }

    public function test_explicit_mode_sums_completed_plus_partial(): void
    {
        $retainer = Retainer::factory()->create([
            'period_mode' => RetainerPeriodMode::Explicit,
            'period_unit' => null,
            'seconds_per_period' => null,
            'starts_at' => Carbon::parse('2026-01-01'),
        ]);
        $retainer->periods()->create([
            'starts_at' => '2026-01-01', 'ends_at' => '2026-01-31', 'seconds_allocated' => 40 * 3600,
        ]);
        $retainer->periods()->create([
            'starts_at' => '2026-02-01', 'ends_at' => '2026-02-28', 'seconds_allocated' => 60 * 3600,
        ]);
        $retainer->load('periods');

        $result = $this->calculator->computeAllocated($retainer, Carbon::parse('2026-02-14'));
        $this->assertEqualsWithDelta(70 * 3600, $result, 3600);
    }

    public function test_calendar_monthly_spans_dst_spring_forward_without_phantom_day_loss(): void
    {
        // US DST 2026: spring forward March 8 (lose 1h). A March retainer should still get full month allocation when fully elapsed.
        $retainer = Retainer::factory()->make([
            'period_mode' => RetainerPeriodMode::Calendar,
            'period_unit' => RetainerPeriodUnit::Monthly,
            'seconds_per_period' => 40 * 3600,
            'starts_at' => Carbon::parse('2026-03-01'),
        ]);

        // Fully elapsed March: should be exactly 40h regardless of DST
        $result = $this->calculator->computeAllocated($retainer, Carbon::parse('2026-04-01'));
        $this->assertSame(40 * 3600, $result);
    }

    public function test_clamps_to_ends_at(): void
    {
        $retainer = Retainer::factory()->make([
            'period_mode' => RetainerPeriodMode::Calendar,
            'period_unit' => RetainerPeriodUnit::Monthly,
            'seconds_per_period' => 40 * 3600,
            'starts_at' => Carbon::parse('2026-06-01'),
            'ends_at' => Carbon::parse('2026-07-31'),
        ]);

        $result = $this->calculator->computeAllocated($retainer, Carbon::parse('2026-12-31'));
        $this->assertEqualsWithDelta(80 * 3600, $result, 3600);
    }
}
