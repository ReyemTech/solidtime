# Time-Budgeted Retainers — Design Spec

**Status:** Approved for planning
**Date:** 2026-05-26
**Branch:** reyem (local-only feature)
**Author:** Mario Meyer
**Spec ID:** 2026-05-26-time-budgeted-retainers

## 1. Summary

Add **client retainer tracking** to solidtime: configurable time-budgeted allocations (weekly / monthly / quarterly) attached to clients, with optional per-project sub-caps. The system defaults to a **cumulative trend view** (compare allocated-to-date against tracked-to-date) and optionally enforces a **hard cap** with configurable scope and enforcement strategy. All computation is on-the-fly from raw `time_entries`; no materialized period rows for the common case. A small Redis cache fronts the read path for dashboards.

This is the **first** of three local-only subsystems planned for the reyem fork. The other two (statistics view, report layout builder) will be specified separately and depend on this one for data.

## 2. Goals

- Track contractual retainers ("Acme: 40h/month") at the client level, with optional per-project sub-caps.
- Default behavior is visibility-only: see whether tracked hours are converging with, overrunning, or under-running allocated hours over time.
- Allow opting into **hard caps** per retainer, configurable scope (per-period or cumulative) and enforcement (v1 ships `block` only).
- Provide the data foundation that the upcoming statistics-view subsystem (#2) and report-builder subsystem (#3) will consume.

## 3. Non-goals (v1)

- Client-facing portal / shareable retainer status URL. (Existing `Report` shareable-link pattern can be extended in v1.1.)
- Email or Slack notifications on threshold crossings.
- Monetary computation (`$ allocated vs $ tracked`). Hours only.
- Approval workflow for over-cap time entries. (`flag` and `approval` enforcement modes are reserved in schema but not wired in v1.)
- Bulk operations (clone retainer to next period, CSV import).
- Dashboard "retainers at risk" widget (depends on subsystem #2, tagged v1.5).

## 4. Architecture

Four cleanly bounded units that communicate through narrow interfaces:

| Unit | Type | Responsibility |
|---|---|---|
| `RetainerConfig` | Eloquent models + tables | Persistent configuration. Pure data, no behavior. |
| `AllocationCalculator` | Service class | Given a `RetainerConfig` and a date, returns `allocated_to_date` (seconds). Pure calendar math, no DB access. |
| `ConsumptionQuery` | Service class | Given a retainer (+ optional project filter) and a date window, returns `tracked_to_date` (seconds) via one aggregated SQL query. |
| `CapEnforcer` | Eloquent observer + service | Hooks into `TimeEntry::saving`. Reads `AllocationCalculator` + `ConsumptionQuery`, applies enforcement. |

A `RetainerCache` facade wraps `ConsumptionQuery` reads with Redis tag-based caching (5-minute TTL). Reads from the write path **bypass the cache** to avoid race conditions where two near-simultaneous saves both see "under cap" from stale cache and together push over.

The four units can be tested independently and any one's internals can change without touching the others (e.g., swap the cache, change calendar math, add enforcement strategies).

### What does NOT change

`Project.estimated_time` and `Task.estimated_time` (single integer columns introduced in 2024) remain untouched. They represent "lifetime project budget" — a different concept from a recurring retainer. They coexist. Deprecation of those fields can be revisited in a later milestone if users find them redundant.

## 5. Data model

Three new tables. UUID primary keys (matches `HasUuids` throughout solidtime). Soft deletes on `retainers`. `organization_id` denormalized for multi-tenant isolation. All durations stored as **integer seconds**, matching the existing `estimated_time` / `time_entries.duration` convention.

### `retainers`

| Column | Type | Notes |
|---|---|---|
| `id` | uuid pk | |
| `organization_id` | uuid fk → organizations | for tenant scoping in policies |
| `client_id` | uuid fk → clients | |
| `name` | string | e.g. "Acme Monthly Retainer" |
| `description` | text nullable | |
| `period_mode` | enum | `calendar` \| `anchor` \| `explicit` |
| `period_unit` | enum nullable | `weekly` \| `monthly` \| `quarterly`; null for explicit |
| `seconds_per_period` | unsigned int nullable | null for explicit |
| `anchor_date` | date nullable | required when `period_mode = anchor` |
| `starts_at` | date | retainer validity start |
| `ends_at` | date nullable | null = open-ended |
| `billable_only` | bool, default true | whether non-billable entries count |
| `hard_cap_enabled` | bool, default false | master switch for enforcement |
| `hard_cap_scope` | enum nullable | `per_period` \| `cumulative`; null when disabled |
| `hard_cap_enforcement` | enum nullable | `block` \| `flag` \| `approval`; **only `block` wired in v1** |
| `hard_cap_cumulative_seconds` | unsigned int nullable | required when scope = cumulative |
| `sub_cap_mode` | enum, default `soft` | `soft` \| `strict` |
| `created_at`, `updated_at`, `deleted_at` | timestamps | soft delete |

**Indexes:**
- `(organization_id, client_id)`
- `(client_id, starts_at, ends_at)` — for "active retainer for client at date X" lookups

### `retainer_periods` (used only when `period_mode = 'explicit'`)

| Column | Type | Notes |
|---|---|---|
| `id` | uuid pk | |
| `retainer_id` | uuid fk → retainers (cascade delete) | |
| `starts_at` | date | |
| `ends_at` | date | |
| `seconds_allocated` | unsigned int | |

**Constraints:**
- `UNIQUE (retainer_id, starts_at)`
- Periods within a retainer must not overlap (validated in application; DB index supports the check)

### `retainer_project_caps` (optional sub-caps)

| Column | Type | Notes |
|---|---|---|
| `id` | uuid pk | |
| `retainer_id` | uuid fk → retainers (cascade delete) | |
| `project_id` | uuid fk → projects | must belong to retainer's client (validated) |
| `seconds_per_period` | unsigned int nullable | independent cap on this project per recurring period (used when parent retainer's `hard_cap_scope = per_period` or when sub-caps display as soft targets) |
| `seconds_cumulative` | unsigned int nullable | independent cumulative cap on this project (used when parent retainer's `hard_cap_scope = cumulative`) |

**Semantics:** Sub-caps are **independent limits on a single project**, not shares of the parent pool. In `sub_cap_mode = soft` they are informational (displayed alongside actuals; not enforced). In `sub_cap_mode = strict` they are enforced exactly like the parent cap, scoped to the project. A retainer can have zero, one, or many sub-caps; projects without a sub-cap row have no project-level limit (only the parent pool applies).

**Constraint:** `UNIQUE (retainer_id, project_id)`

### Validation rules

- `seconds_per_period` required when `period_mode ∈ {calendar, anchor}`
- `anchor_date` required when `period_mode = anchor`
- ≥1 row in `retainer_periods` required when `period_mode = explicit`; rows must not overlap
- `hard_cap_scope` + `hard_cap_enforcement` required when `hard_cap_enabled = true`
- `hard_cap_cumulative_seconds` required when `hard_cap_scope = cumulative`
- `ends_at >= starts_at` when both present
- **At most one active retainer per client at any date** — overlapping validity windows rejected
- `retainer_project_caps.project_id` must belong to `retainers.client_id`

### What we deliberately do NOT store

- Materialized period rows for recurring retainers (computed on the fly).
- Cached `tracked_to_date` columns (Redis cache only; never persisted).
- Pre-computed allocations (always derived from config).

Single source of truth: `retainers` config + raw `time_entries`. Everything else is derived.

## 6. Read path (display)

```
display(retainer) →
  allocated = AllocationCalculator::computeAllocated(retainer, now)
  tracked   = RetainerCache::tracked(retainer, now)
      → on miss: ConsumptionQuery::computeTracked(retainer, now)
      → cache for 5 min, tagged with "retainer:{id}"
  return {
    allocated_seconds,
    tracked_seconds,
    delta_seconds,    # tracked - allocated; negative = under
    percent,          # tracked / allocated
    hard_cap_state    # ok | warning | exceeded (only meaningful when cap enabled)
  }
```

### `AllocationCalculator` (pure math, no DB)

- **Calendar mode** (`weekly`, `monthly`, `quarterly`): `allocated = completed_periods × seconds_per_period + (elapsed_in_current_period / period_length) × seconds_per_period`. Current-period proration is intentional — it gives a smooth line that doesn't snap on period boundaries. Matches the "averages over time" user framing.
- **Anchor mode**: same formula, but periods are anchored to `anchor_date` rather than calendar boundaries.
- **Explicit mode**: sum `seconds_allocated` from `retainer_periods` where `ends_at <= now`, plus prorated row containing `now`.

### `ConsumptionQuery` (one indexed SQL aggregate)

```sql
SELECT COALESCE(SUM(time_entries.duration_seconds), 0)
FROM time_entries
JOIN projects ON projects.id = time_entries.project_id
WHERE projects.client_id = :client_id
  AND time_entries.start >= :retainer_starts_at
  AND time_entries.end   <= :as_of
  AND (:billable_only = false OR time_entries.billable = true);
```

For a project sub-cap: same query, additional `AND time_entries.project_id = :project_id`.

### `RetainerCache`

- Backed by Laravel's Redis cache driver with tag support.
- Key pattern: `retainer:{retainer_id}:tracked:{as_of:Y-m-d}` (rounded to day).
- Tag: `retainer:{retainer_id}` for invalidation.
- TTL: 5 minutes (read-path freshness ceiling even without invalidation).
- Invalidation: `TimeEntryObserver::saved`/`deleted` calls `RetainerCache::invalidate(tag)`.

The cache layer is one file with two methods (`tracked()`, `invalidate()`). It can be removed in a single commit if performance measurements later show it's unnecessary, without changing any consumer's API.

## 7. Write path (cap enforcement)

```
TimeEntryObserver::saving($entry):
  retainer = findActiveRetainer($entry->project->client_id, $entry->end)
  if (!retainer || !retainer->hard_cap_enabled) return

  delta = $entry->duration_seconds - ($entry->getOriginal('duration_seconds') ?? 0)
  current_tracked = ConsumptionQuery::computeTracked(retainer, $entry->end)  -- UNCACHED on write
  cap = retainer->hard_cap_scope === 'cumulative'
          ? retainer->hard_cap_cumulative_seconds
          : AllocationCalculator::computeAllocated(retainer, $entry->end)

  if (current_tracked + delta <= cap) goto sub_cap_check

  -- v1: only 'block' is wired
  if (retainer->hard_cap_enforcement === 'block') throw RetainerCapExceededException
  -- 'flag' and 'approval' modes are no-ops in v1 (reserved in schema for v1.1+)

sub_cap_check:
  if (retainer->sub_cap_mode === 'strict') {
    cap = matching retainer_project_caps row for this project, if any
    repeat the same enforcement check filtered to this project
  }

TimeEntryObserver::saved / deleted:
  RetainerCache::invalidate("retainer:{retainer->id}")
```

**Why uncached on the write path:** two near-simultaneous saves reading a stale cached "under cap" could both pass enforcement and together push over. Uncached read costs one indexed aggregate query per save — fine.

**Determinism of `findActiveRetainer`:** the "at most one active retainer per client at any date" constraint (validated on retainer save) makes this lookup unambiguous.

## 8. API surface

All routes follow existing solidtime V1 conventions; all gated by `RetainerPolicy` (org-membership; mutation requires `clients:manage` ability — verify exact ability name during implementation).

```
GET    /api/v1/organizations/{org}/retainers
POST   /api/v1/organizations/{org}/retainers
GET    /api/v1/organizations/{org}/retainers/{retainer}
PUT    /api/v1/organizations/{org}/retainers/{retainer}
DELETE /api/v1/organizations/{org}/retainers/{retainer}

GET    /api/v1/organizations/{org}/retainers/{retainer}/status        ?as_of=YYYY-MM-DD
GET    /api/v1/organizations/{org}/retainers/{retainer}/periods       ?from=...&to=...

GET    /api/v1/organizations/{org}/clients/{client}/retainers

GET    /api/v1/organizations/{org}/retainers/{retainer}/project-caps
POST   /api/v1/organizations/{org}/retainers/{retainer}/project-caps
PUT    /api/v1/organizations/{org}/retainers/{retainer}/project-caps/{cap}
DELETE /api/v1/organizations/{org}/retainers/{retainer}/project-caps/{cap}

PUT    /api/v1/organizations/{org}/retainers/{retainer}/periods       -- whole-set replacement, body: [{starts_at, ends_at, seconds_allocated}, ...]
```

`status` response shape:

```json
{
  "as_of": "2026-05-26",
  "allocated_seconds": 86400,
  "tracked_seconds": 79200,
  "delta_seconds": -7200,
  "percent": 0.917,
  "hard_cap_state": "ok",
  "hard_cap_enabled": true,
  "hard_cap_scope": "per_period",
  "current_period": { "starts_at": "2026-05-01", "ends_at": "2026-05-31" }
}
```

## 9. Frontend (Inertia + Vue 3 + Tailwind)

Three surfaces:

### 9.1 Client detail page → new "Retainer" tab (primary UI)

- Shows the active retainer (if any) with a status card: allocated vs tracked progress bar, delta (e.g. "−2.0h vs plan"), period selector (this period / last 3 periods / cumulative since start).
- CRUD form lives on this tab.
- Form uses **opinionated defaults** — visible fields:
  - Name
  - Period unit (weekly / monthly / quarterly dropdown)
  - Hours per period
  - Starts at
- Everything else is in a collapsible **Advanced** section:
  - Period mode (calendar / anchor / explicit) — defaults to calendar
  - Billable only — defaults to true
  - Hard cap enabled — defaults to false
    - When toggled on, reveals scope + enforcement + (optional) cumulative seconds
  - Sub-cap mode — defaults to soft
  - Sub-cap rows (per project) — empty by default
  - End date — null by default
- Edit form same as create with all fields editable. Rate/period fields show a soft warning: "Changing rate will recompute historical allocations and may change the status display for past dates."

### 9.2 Time entry form widget

When a user selects a project belonging to a client with an active retainer:

- A small badge appears: "Acme retainer: 28h/40h this month (70%)"
- If hard cap is `block` mode and saving would exceed cap, the save button disables with a tooltip explaining why.
- Visual is enough — actual enforcement happens server-side via the observer regardless.

### 9.3 Dashboard tile (deferred to v1.5)

"Retainers at risk" widget — clients trending >100% of allocation. Deferred because it depends on the statistics-view subsystem (#2) for chart infrastructure.

## 10. Testing

| Layer | Test type | Coverage focus |
|---|---|---|
| `AllocationCalculator` | Pure unit (no DB) | Table-driven: calendar/anchor/explicit × {weekly, monthly, quarterly} × edge cases (DST boundaries, leap years, retainer starting mid-period, ending mid-period, single-day window) |
| `ConsumptionQuery` | Feature (with factories) | `billable_only` filter, project-restricted query (for sub-caps), entries that straddle `starts_at` boundary, soft-deleted projects excluded |
| `CapEnforcer` / `TimeEntryObserver` | Feature | `block` throws on over-cap save; cache invalidation fires on save/update/delete; uncached read at write time (race safety); sub-cap strict mode enforced |
| `RetainerCache` | Unit | TTL behavior, tag invalidation, cache miss → query |
| API endpoints | Controller tests | Each verb/route; payload validation; org isolation via `RetainerPolicy`; sub-cap project-belongs-to-client validation |
| UI | Manual (golden path + edge cases) for v1 | E2E (Playwright) deferred to v1.1 once UI stabilizes |

## 11. Audit & observability

- `Retainer`, `RetainerProjectCap`, `RetainerPeriod` use solidtime's existing `Audit` model wiring (same pattern as `Project`/`Client`). No new infra.
- Hard-cap blocks log an info-level entry naming the retainer, user, attempted entry duration, and current consumption.

## 12. Migration & rollout

- Three migration files (`retainers`, `retainer_periods`, `retainer_project_caps`). All additive — no changes to existing tables.
- Feature gate: not strictly required since the feature is purely additive and inert until a user creates a retainer. Optional `RETAINERS_ENABLED` config flag if we want to hide the UI tab during initial deployment.
- No data backfill. Existing `Project.estimated_time` left intact.

## 13. Dependencies on the other two subsystems

- **Subsystem #2 (statistics view)** will consume `GET /retainers/{id}/periods` for budget-vs-actual charts. Building #1 first means #2 has real data to render.
- **Subsystem #3 (report layout builder)** will be able to embed retainer status in custom reports. Independent — no schema coupling.

## 14. Open implementation-time questions

These do not block planning but will need answers during implementation:

- Exact name of the `clients:manage` ability (or equivalent) in solidtime's permission system — verify by inspecting an existing client controller.
- Whether `time_entries.duration_seconds` is a stored column or a derived field — confirm column name when writing `ConsumptionQuery` SQL.
- Whether solidtime's Inertia layouts have a tabbed-page pattern for client detail or whether this introduces a new pattern.

## 15. Acceptance criteria (v1)

- Org admin can create a retainer for a client (calendar/anchor/explicit modes, weekly/monthly/quarterly, optional hard cap with `block` enforcement, optional per-project sub-caps).
- Client detail page shows retainer status with allocated/tracked/delta/percent.
- Time entry form shows a retainer badge when the project belongs to a client with an active retainer.
- When hard cap is enabled in `block` mode, server-side enforcement rejects time entries that would exceed cap; UI surfaces the error.
- All endpoints respect org isolation and the existing permission model.
- Cache invalidates within 1 read after a relevant time entry is created/updated/deleted.
- The "at most one active retainer per client at any date" constraint is enforced on save.
- Existing solidtime functionality (projects, time entries, reports, exports) is unaffected.
