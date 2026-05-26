<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Retainer;

use App\Http\Resources\V1\BaseResource;
use Illuminate\Http\Request;

class RetainerStatusResource extends BaseResource
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
