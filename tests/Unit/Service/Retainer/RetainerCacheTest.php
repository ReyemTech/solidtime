<?php
declare(strict_types=1);

namespace Tests\Unit\Service\Retainer;

use App\Models\Retainer;
use App\Service\Retainer\ConsumptionQuery;
use App\Service\Retainer\RetainerCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class RetainerCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_first_call_misses_and_caches_result(): void
    {
        $retainer = Retainer::factory()->create();
        $asOf = Carbon::parse('2026-06-15');

        $query = Mockery::mock(ConsumptionQuery::class);
        $query->shouldReceive('computeTracked')->once()->andReturn(12345);

        $cache = new RetainerCache($query);
        $this->assertSame(12345, $cache->tracked($retainer, $asOf));
    }

    public function test_second_call_within_ttl_returns_cached_value_without_query(): void
    {
        $retainer = Retainer::factory()->create();
        $asOf = Carbon::parse('2026-06-15');

        $query = Mockery::mock(ConsumptionQuery::class);
        $query->shouldReceive('computeTracked')->once()->andReturn(12345);

        $cache = new RetainerCache($query);
        $cache->tracked($retainer, $asOf);
        $second = $cache->tracked($retainer, $asOf);
        $this->assertSame(12345, $second);
    }

    public function test_invalidate_clears_cached_value(): void
    {
        $retainer = Retainer::factory()->create();
        $asOf = Carbon::parse('2026-06-15');

        $query = Mockery::mock(ConsumptionQuery::class);
        $query->shouldReceive('computeTracked')->twice()->andReturn(100, 200);

        $cache = new RetainerCache($query);
        $this->assertSame(100, $cache->tracked($retainer, $asOf));
        $cache->invalidate($retainer);
        $this->assertSame(200, $cache->tracked($retainer, $asOf));
    }
}
