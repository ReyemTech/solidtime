<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\V1\Retainer\RetainerPeriodsReplaceRequest;
use App\Http\Resources\V1\Retainer\RetainerPeriodResource;
use App\Models\Organization;
use App\Models\Retainer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class RetainerPeriodController extends Controller
{
    public function replace(Organization $organization, Retainer $retainer, RetainerPeriodsReplaceRequest $request): JsonResponse
    {
        $this->checkPermission($organization, 'retainers:update');
        if ($retainer->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('Retainer does not belong to organization');
        }

        DB::transaction(function () use ($retainer, $request) {
            $retainer->periods()->delete();
            foreach ($request->input('periods') as $p) {
                $retainer->periods()->create([
                    'starts_at' => $p['starts_at'],
                    'ends_at' => $p['ends_at'],
                    'seconds_allocated' => $p['seconds_allocated'],
                ]);
            }
        });

        return new JsonResponse([
            'data' => RetainerPeriodResource::collection($retainer->periods()->orderBy('starts_at')->get()),
        ]);
    }
}
