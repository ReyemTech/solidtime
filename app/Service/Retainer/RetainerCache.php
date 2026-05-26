<?php
declare(strict_types=1);

namespace App\Service\Retainer;

use App\Models\Retainer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class RetainerCache
{
    private const TTL_SECONDS = 300;

    public function __construct(private readonly ConsumptionQuery $query) {}

    public function tracked(Retainer $retainer, Carbon $asOf, ?string $projectId = null): int
    {
        $key = $this->key($retainer, $asOf, $projectId);
        return (int) Cache::tags($this->tag($retainer))->remember(
            $key,
            self::TTL_SECONDS,
            fn () => $this->query->computeTracked($retainer, $asOf, $projectId),
        );
    }

    public function invalidate(Retainer $retainer): void
    {
        Cache::tags($this->tag($retainer))->flush();
    }

    private function key(Retainer $retainer, Carbon $asOf, ?string $projectId): string
    {
        return sprintf(
            'retainer:%s:tracked:%s:%s',
            $retainer->id,
            $asOf->format('Y-m-d'),
            $projectId ?? 'all',
        );
    }

    private function tag(Retainer $retainer): string
    {
        return 'retainer:'.$retainer->id;
    }
}
