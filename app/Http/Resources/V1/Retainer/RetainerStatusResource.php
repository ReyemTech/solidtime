<?php
declare(strict_types=1);

namespace App\Http\Resources\V1\Retainer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RetainerStatusResource extends JsonResource
{
    /**
     * The controller passes an associative array as $resource.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return is_array($this->resource) ? $this->resource : [];
    }
}
