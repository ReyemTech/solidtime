<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\V1\Retainer\RetainerStoreRequest;
use App\Http\Requests\V1\Retainer\RetainerUpdateRequest;
use App\Http\Resources\V1\Retainer\RetainerCollection;
use App\Http\Resources\V1\Retainer\RetainerResource;
use App\Http\Resources\V1\Retainer\RetainerStatusResource;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Retainer;
use App\Service\Retainer\AllocationCalculator;
use App\Service\Retainer\RetainerCache;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class RetainerController extends Controller
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

    public function index(Organization $organization): RetainerCollection
    {
        $this->checkPermission($organization, 'retainers:view');

        return new RetainerCollection(
            Retainer::query()
                ->whereBelongsTo($organization, 'organization')
                ->orderBy('created_at', 'desc')
                ->paginate(config('app.pagination_per_page_default'))
        );
    }

    public function store(Organization $organization, RetainerStoreRequest $request): RetainerResource
    {
        $this->checkPermission($organization, 'retainers:create');
        $this->assertNoOverlap(
            $organization,
            $request->input('client_id'),
            $request->input('starts_at'),
            $request->input('ends_at'),
        );

        $retainer = new Retainer($request->validated());
        $retainer->organization_id = $organization->id;
        $retainer->save();
        $retainer->refresh();

        return new RetainerResource($retainer);
    }

    public function show(Organization $organization, Retainer $retainer): RetainerResource
    {
        $this->checkPermission($organization, 'retainers:view');
        $this->checkRetainerBelongsToOrganization($organization, $retainer);

        return new RetainerResource($retainer);
    }

    public function update(Organization $organization, Retainer $retainer, RetainerUpdateRequest $request): RetainerResource
    {
        $this->checkPermission($organization, 'retainers:update');
        $this->checkRetainerBelongsToOrganization($organization, $retainer);

        if ($request->has('starts_at') || $request->has('ends_at')) {
            $this->assertNoOverlap(
                $organization,
                $retainer->client_id,
                $request->input('starts_at', $retainer->starts_at->toDateString()),
                $request->input('ends_at', $retainer->ends_at?->toDateString()),
                exceptId: $retainer->id,
            );
        }

        $retainer->fill($request->validated());
        $retainer->save();

        return new RetainerResource($retainer);
    }

    public function destroy(Organization $organization, Retainer $retainer): JsonResponse
    {
        $this->checkPermission($organization, 'retainers:delete');
        $this->checkRetainerBelongsToOrganization($organization, $retainer);

        $retainer->delete();

        return new JsonResponse(null, 204);
    }

    public function status(
        Organization $organization,
        Retainer $retainer,
        Request $request,
        AllocationCalculator $calculator,
        RetainerCache $cache,
    ): RetainerStatusResource {
        $this->checkPermission($organization, 'retainers:view');
        $this->checkRetainerBelongsToOrganization($organization, $retainer);

        $asOf = $request->query('as_of')
            ? Carbon::parse((string) $request->query('as_of'))->endOfDay()
            : Carbon::now();

        $allocated = $calculator->computeAllocated($retainer, $asOf);
        $tracked = $cache->tracked($retainer, $asOf);
        $delta = $tracked - $allocated;
        $percent = $allocated > 0 ? round($tracked / $allocated, 4) : 0.0;

        return new RetainerStatusResource([
            'as_of' => $asOf->toDateString(),
            'allocated_seconds' => $allocated,
            'tracked_seconds' => $tracked,
            'delta_seconds' => $delta,
            'percent' => $percent,
            'hard_cap_enabled' => $retainer->hard_cap_enabled,
            'hard_cap_scope' => $retainer->hard_cap_scope?->value,
        ]);
    }

    public function periods(Organization $organization, Retainer $retainer): JsonResponse
    {
        $this->checkPermission($organization, 'retainers:view');
        $this->checkRetainerBelongsToOrganization($organization, $retainer);

        // v1 stub — see plan §14: subsystem #2 (statistics view) will populate this with per-period breakdown
        return new JsonResponse(['data' => []]);
    }

    public function forClient(Organization $organization, Client $client): RetainerCollection
    {
        $this->checkPermission($organization, 'retainers:view');
        if ($client->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('Client does not belong to organization');
        }

        return new RetainerCollection(
            Retainer::query()->where('client_id', $client->id)->get()
        );
    }

    /**
     * @throws ValidationException
     */
    private function assertNoOverlap(
        Organization $organization,
        string $clientId,
        string $startsAt,
        ?string $endsAt,
        ?string $exceptId = null,
    ): void {
        $start = Carbon::parse($startsAt);
        $end = $endsAt ? Carbon::parse($endsAt) : null;

        $query = Retainer::query()
            ->where('client_id', $clientId)
            ->where('organization_id', $organization->id);
        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }
        $candidates = $query->get();

        foreach ($candidates as $other) {
            $oStart = $other->starts_at;
            $oEnd = $other->ends_at;
            // overlap: ($end is null or $end >= $oStart) AND ($oEnd is null or $start <= $oEnd)
            $startBeforeOtherEnds = $oEnd === null || $start->lte($oEnd);
            $endAfterOtherStarts = $end === null || $end->gte($oStart);
            if ($startBeforeOtherEnds && $endAfterOtherStarts) {
                throw ValidationException::withMessages([
                    'starts_at' => 'Overlaps with another retainer for this client.',
                ]);
            }
        }
    }
}
