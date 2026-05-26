<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Retainer;

use App\Http\Resources\V1\BaseResource;
use App\Models\Retainer;
use Illuminate\Http\Request;

/**
 * @property Retainer $resource
 */
class RetainerResource extends BaseResource
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
            'anchor_date' => $this->formatDate($this->resource->anchor_date),
            'starts_at' => $this->formatDate($this->resource->starts_at),
            'ends_at' => $this->formatDate($this->resource->ends_at),
            'billable_only' => $this->resource->billable_only,
            'hard_cap_enabled' => $this->resource->hard_cap_enabled,
            'hard_cap_scope' => $this->resource->hard_cap_scope?->value,
            'hard_cap_enforcement' => $this->resource->hard_cap_enforcement?->value,
            'hard_cap_cumulative_seconds' => $this->resource->hard_cap_cumulative_seconds,
            'sub_cap_mode' => $this->resource->sub_cap_mode->value,
            'created_at' => $this->formatDateTime($this->resource->created_at),
            'updated_at' => $this->formatDateTime($this->resource->updated_at),
        ];
    }
}
