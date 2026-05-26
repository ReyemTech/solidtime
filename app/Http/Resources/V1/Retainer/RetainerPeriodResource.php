<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Retainer;

use App\Http\Resources\V1\BaseResource;
use App\Models\RetainerPeriod;
use Illuminate\Http\Request;

/**
 * @property RetainerPeriod $resource
 */
class RetainerPeriodResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'retainer_id' => $this->resource->retainer_id,
            'starts_at' => $this->formatDate($this->resource->starts_at),
            'ends_at' => $this->formatDate($this->resource->ends_at),
            'seconds_allocated' => $this->resource->seconds_allocated,
        ];
    }
}
