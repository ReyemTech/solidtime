<?php

declare(strict_types=1);

namespace App\Service\Retainer;

use App\Models\Retainer;
use Illuminate\Support\Carbon;

class RetainerLookup
{
    public function findActiveForClient(string $clientId, Carbon $date): ?Retainer
    {
        $day = $date->copy()->startOfDay();

        return Retainer::query()
            ->where('client_id', $clientId)
            ->whereDate('starts_at', '<=', $day)
            ->where(function ($q) use ($day) {
                $q->whereNull('ends_at')->orWhereDate('ends_at', '>=', $day);
            })
            ->first();
    }
}
