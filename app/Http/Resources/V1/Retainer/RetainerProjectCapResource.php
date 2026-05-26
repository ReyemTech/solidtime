<?php
declare(strict_types=1);

namespace App\Http\Resources\V1\Retainer;

use App\Models\RetainerProjectCap;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property RetainerProjectCap $resource
 */
class RetainerProjectCapResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'retainer_id' => $this->resource->retainer_id,
            'project_id' => $this->resource->project_id,
            'seconds_per_period' => $this->resource->seconds_per_period,
            'seconds_cumulative' => $this->resource->seconds_cumulative,
        ];
    }
}
