<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Retainer;

use Illuminate\Http\Resources\Json\ResourceCollection;

class RetainerCollection extends ResourceCollection
{
    /** @var class-string */
    public $collects = RetainerResource::class;
}
