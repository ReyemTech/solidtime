<?php
declare(strict_types=1);

namespace App\Http\Resources\V1\Retainer;

use App\Models\RetainerPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property RetainerPeriod $resource
 */
class RetainerPeriodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'retainer_id' => $this->resource->retainer_id,
            'starts_at' => $this->resource->starts_at->toDateString(),
            'ends_at' => $this->resource->ends_at->toDateString(),
            'seconds_allocated' => $this->resource->seconds_allocated,
        ];
    }
}
