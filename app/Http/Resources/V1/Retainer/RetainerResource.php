<?php
declare(strict_types=1);

namespace App\Http\Resources\V1\Retainer;

use App\Models\Retainer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Retainer $resource
 */
class RetainerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'organization_id' => $this->resource->organization_id,
            'client_id' => $this->resource->client_id,
            'name' => $this->resource->name,
            'description' => $this->resource->description,
            'period_mode' => $this->resource->period_mode->value,
            'period_unit' => $this->resource->period_unit?->value,
            'seconds_per_period' => $this->resource->seconds_per_period,
            'anchor_date' => $this->resource->anchor_date?->toDateString(),
            'starts_at' => $this->resource->starts_at->toDateString(),
            'ends_at' => $this->resource->ends_at?->toDateString(),
            'billable_only' => $this->resource->billable_only,
            'hard_cap_enabled' => $this->resource->hard_cap_enabled,
            'hard_cap_scope' => $this->resource->hard_cap_scope?->value,
            'hard_cap_enforcement' => $this->resource->hard_cap_enforcement?->value,
            'hard_cap_cumulative_seconds' => $this->resource->hard_cap_cumulative_seconds,
            'sub_cap_mode' => $this->resource->sub_cap_mode->value,
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }
}
