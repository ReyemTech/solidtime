<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\V1\Retainer\RetainerProjectCapStoreRequest;
use App\Http\Requests\V1\Retainer\RetainerProjectCapUpdateRequest;
use App\Http\Resources\V1\Retainer\RetainerProjectCapResource;
use App\Models\Organization;
use App\Models\Retainer;
use App\Models\RetainerProjectCap;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;

class RetainerProjectCapController extends Controller
{
    /**
     * @throws AuthorizationException
     */
    protected function checkRetainerBelongsToOrganization(Organization $organization, Retainer $retainer): void
    {
        if ($retainer->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('Retainer does not belong to organization');
        }
    }

    public function index(Organization $organization, Retainer $retainer): JsonResponse
    {
        $this->checkPermission($organization, 'retainers:view');
        $this->checkRetainerBelongsToOrganization($organization, $retainer);

        return new JsonResponse([
            'data' => RetainerProjectCapResource::collection($retainer->projectCaps()->get()),
        ]);
    }

    public function store(Organization $organization, Retainer $retainer, RetainerProjectCapStoreRequest $request): JsonResponse
    {
        $this->checkPermission($organization, 'retainers:update');
        $this->checkRetainerBelongsToOrganization($organization, $retainer);

        $cap = $retainer->projectCaps()->create($request->validated());
        return (new RetainerProjectCapResource($cap))->response()->setStatusCode(201);
    }

    public function update(Organization $organization, Retainer $retainer, RetainerProjectCap $cap, RetainerProjectCapUpdateRequest $request): RetainerProjectCapResource
    {
        $this->checkPermission($organization, 'retainers:update');
        $this->checkRetainerBelongsToOrganization($organization, $retainer);
        if ($cap->retainer_id !== $retainer->id) {
            throw new AuthorizationException('Cap does not belong to retainer');
        }

        $cap->fill($request->validated());
        $cap->save();
        return new RetainerProjectCapResource($cap);
    }

    public function destroy(Organization $organization, Retainer $retainer, RetainerProjectCap $cap): JsonResponse
    {
        $this->checkPermission($organization, 'retainers:update');
        $this->checkRetainerBelongsToOrganization($organization, $retainer);
        if ($cap->retainer_id !== $retainer->id) {
            throw new AuthorizationException('Cap does not belong to retainer');
        }
        $cap->delete();
        return new JsonResponse(null, 204);
    }
}
