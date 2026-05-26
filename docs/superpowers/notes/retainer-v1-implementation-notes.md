# Retainers v1 — implementation notes & smoke checklist

**Branch:** `reyem` · **Status:** Implementation complete, awaiting manual UI smoke test
**Spec:** `docs/superpowers/specs/2026-05-26-time-budgeted-retainers-design.md`
**Plan:** `docs/superpowers/plans/2026-05-26-time-budgeted-retainers.md`

## What shipped

- Backend: 52 unit/integration tests passing
  - `AllocationCalculator` (11 tests) — calendar/anchor/explicit periods, DST-safe day diffing
  - `ConsumptionQuery` (7 tests) — SUM(EXTRACT EPOCH …) with billable + project filters
  - `RetainerLookup` (6 tests) — active-retainer-for-client-at-date
  - `RetainerCache` (3 tests) — Redis tag-based, 5min TTL
  - `CapEnforcer` (6 tests) — block-mode only in v1; uncached on write path
  - `TimeEntryRetainerObserver` (2 tests) — enforces cap on save; invalidates cache on saved/deleted
  - Models + factories (3 tests) — correlated factory FKs
  - API endpoint tests (14 tests) — RetainerController (9) + RetainerProjectCapController (3) + RetainerPeriodController (2)
- 13 API endpoints under `/api/v1/organizations/{organization}/...`
- Frontend: Pinia store (`useRetainersStore`), TanStack composables, Vue components
- Local-only Redis service in `docker-compose.yml`

## Deviations from the original plan

| Plan said | Reality | Reason |
|---|---|---|
| Plan §6 SQL referenced `time_entries.duration_seconds` | Actual schema has `start` + `end` (dateTime), no duration column | Pre-implementation recon; `ConsumptionQuery` computes via `EXTRACT(EPOCH FROM (end - start))::int` |
| Plan §Task 6 mentioned best-effort audit wiring | Wired `CustomAuditable` + `AuditableContract` on all three models | Followed existing `Client.php` pattern |
| Plan §Task 6 didn't mention morph maps | `AppServiceProvider` morph map registration added for `retainer`, `retainer-period`, `retainer-project-cap` | solidtime enforces morph maps via `Relation::enforceMorphMap()` |
| Plan §9.1 assumed a client detail page existed | None exists; created new `/clients/{client}/retainers` Inertia route + `ClientRetainers.vue` page | Discovery in Task 19 (`docs/superpowers/notes/retainer-ui-patterns.md`) |
| Plan §9 used `Inertia::useForm` + raw fetch | Used codebase pattern: Pinia store + TanStack Vue Query + Zodios-generated `api` client + toast errors via `handleApiRequestNotifications` | Convention consistency |
| Plan §Task 7 used `diffInSeconds` | Implementer rewrote to `diffInDays`, then DST-safe helper after code review | Day-granularity better matches retainer accounting; UTC-normalized to avoid DST phantom-day bug |
| Plan §16 `periods` endpoint was a stub returning `[]` | Still a stub — to be filled when subsystem #2 (statistics view) lands | Out of v1 scope per plan |
| Plan §Task 10 assumed `phpunit.xml` already uses Redis | Added `<env name="CACHE_DRIVER" value="redis"/>` | Default test cache driver is array, which doesn't support tags |

## Manual smoke test checklist (TODO — please run before declaring v1 GA)

Start dev environment (Docker + sail must be up; see CLAUDE.md). Then in a browser:

1. **Create a client** — `Acme Corp`
2. **Create a project** under Acme — `Site work`
3. **Click "Retainers" on Acme's row** in `/clients` (via the more-options dropdown)
4. **You should land on `/clients/<acme-id>/retainers`** showing "No retainers for this client yet."
5. **Click "New retainer"** → form opens
6. **Defaults visible**: name, period unit (monthly), hours per period (40), starts at (today)
7. **Click "Advanced"** → reveals period mode, billable_only, hard cap, sub-cap mode, end date
8. **Submit with name "Acme Monthly"** → status card appears
9. **Status card** shows 0h/0h on day 1 (or some prorated allocation if past day 1 of month)
10. **Log a 2h time entry** on `Site work` (via the tracker)
11. **Refresh `/clients/<acme-id>/retainers`** — tracked seconds should now show 2h (cache invalidates on time entry save)
12. **Edit the retainer**, enable hard cap, scope `per_period`, enforcement `block`, hours = 1
13. **Save** → close modal
14. **Try to log another 2h time entry** → save should fail with 422 `retainer_cap_exceeded` (visible in network panel or as toast)
15. **Delete the retainer** → status card disappears, "No retainers" returns
16. **Time entry tracker badge**: pick a project on a client *with* an active retainer → badge appears showing `${name}: Xh / Yh (Z%)`
17. **Pick a project on a client *without* a retainer** → badge hidden

## Known issues / follow-ups for future iterations

- **OpenAPI status endpoint schema:** Scramble inferred `z.string()` for the `/status` response because the controller passes an associative array directly. Either (a) annotate the controller with `@response` PHPDoc, or (b) wrap the response in a typed DTO. The `useRetainerStatusQuery` composable currently has a `cast via unknown` workaround.
- **TimeEntry export tests** are failing on this dev box because the Minio service expects `storage.solidtime.test` hostname which only resolves via the reverse-proxy traefik (not running here). Pre-existing infra concern, unrelated to retainers — but worth either fixing in local docker setup or skipping those tests in CI runs that don't have the proxy.
- **`periods` endpoint is a stub** returning `[]`. To be filled when subsystem #2 (statistics view) lands.
- **`flag` and `approval` enforcement modes** are reserved in the enum + schema but no-op'd at runtime. Wire when product needs them.
- **Client-facing retainer status URL** (shareable link) deferred to v1.1 per spec §3.
- **Dashboard "retainers at risk" widget** deferred to v1.5 per spec §9.3.

## Files of interest (for the next person)

- Service entry points: `app/Service/Retainer/`
- HTTP layer: `app/Http/Controllers/Api/V1/Retainer*Controller.php`, `app/Http/Requests/V1/Retainer/`, `app/Http/Resources/V1/Retainer/`
- Observer: `app/Observers/TimeEntryRetainerObserver.php` (registered in `app/Providers/AppServiceProvider.php`)
- Frontend data: `resources/js/utils/useRetainers.ts`, `resources/js/utils/useRetainersQuery.ts`, `resources/js/utils/useRetainerStatusQuery.ts`
- Frontend UI: `resources/js/Components/Common/Retainer/`, `resources/js/Pages/ClientRetainers.vue`
- Tests: `tests/Unit/Service/Retainer/`, `tests/Unit/Endpoint/Api/V1/Retainer*EndpointTest.php`, `tests/Unit/Model/RetainerTest.php`
