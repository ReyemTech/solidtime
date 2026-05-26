# Time-Budgeted Retainers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add client retainer tracking to solidtime — configurable weekly/monthly/quarterly hour allocations at the client level, with optional per-project sub-caps and optional hard-cap enforcement.

**Architecture:** Four cleanly bounded units: `RetainerConfig` (Eloquent models), `AllocationCalculator` (pure calendar math, no DB), `ConsumptionQuery` (one indexed SQL aggregate), `CapEnforcer` (TimeEntry observer). A `RetainerCache` facade wraps consumption reads with Redis tag-based caching (5-min TTL). Reads from the write path bypass the cache to avoid races.

**Tech Stack:** Laravel 11, PHP 8.3, PostgreSQL, Redis (tag cache), Pest/PHPUnit, Inertia + Vue 3 + Tailwind. Follow solidtime's existing V1 API conventions (HasUuids, `checkPermission`, ApiEndpointTestAbstract).

**Spec:** `docs/superpowers/specs/2026-05-26-time-budgeted-retainers-design.md`

**Spec correction baked into this plan:** the spec references `time_entries.duration_seconds`. The actual schema stores `start` and `end` (dateTime). All consumption SQL in this plan uses `EXTRACT(EPOCH FROM (end - start))::int` and excludes still-running entries (`end IS NOT NULL`).

---

## File Structure

### New files

```
app/
  Enums/
    RetainerPeriodMode.php            -- calendar | anchor | explicit
    RetainerPeriodUnit.php            -- weekly | monthly | quarterly
    RetainerHardCapScope.php          -- per_period | cumulative
    RetainerHardCapEnforcement.php    -- block | flag | approval
    RetainerSubCapMode.php            -- soft | strict
  Models/
    Retainer.php
    RetainerPeriod.php
    RetainerProjectCap.php
  Service/
    Retainer/
      AllocationCalculator.php        -- pure math, no DB
      ConsumptionQuery.php            -- SQL aggregate
      RetainerCache.php               -- Redis tag wrapper
      RetainerLookup.php              -- "active retainer for client at date X"
      CapEnforcer.php                 -- enforcement logic
  Observers/
    TimeEntryRetainerObserver.php
  Exceptions/Api/
    RetainerCapExceededException.php
  Http/
    Controllers/Api/V1/
      RetainerController.php
      RetainerProjectCapController.php
      RetainerPeriodController.php
    Requests/V1/Retainer/
      RetainerStoreRequest.php
      RetainerUpdateRequest.php
      RetainerProjectCapStoreRequest.php
      RetainerProjectCapUpdateRequest.php
      RetainerPeriodsReplaceRequest.php
    Resources/V1/Retainer/
      RetainerResource.php
      RetainerCollection.php
      RetainerStatusResource.php
      RetainerProjectCapResource.php
      RetainerPeriodResource.php

database/
  migrations/
    2026_05_26_120001_create_retainers_table.php
    2026_05_26_120002_create_retainer_periods_table.php
    2026_05_26_120003_create_retainer_project_caps_table.php
  factories/
    RetainerFactory.php
    RetainerPeriodFactory.php
    RetainerProjectCapFactory.php

tests/Unit/
  Service/Retainer/
    AllocationCalculatorTest.php
    ConsumptionQueryTest.php
    RetainerLookupTest.php
    RetainerCacheTest.php
    CapEnforcerTest.php
  Model/
    RetainerTest.php
  Endpoint/Api/V1/
    RetainerEndpointTest.php
    RetainerProjectCapEndpointTest.php
    RetainerPeriodEndpointTest.php

resources/js/
  packages/api/src/schema.d.ts        -- regenerated, not hand-edited
  Components/Retainer/
    RetainerStatusCard.vue
    RetainerForm.vue
    RetainerProjectCapTable.vue
    RetainerExplicitPeriodTable.vue
    TimeEntryRetainerBadge.vue
  Pages/Client/
    RetainerTab.vue                   -- mounted inside client detail page (existing tab pattern TBD on first read)
```

### Modified files

```
app/Providers/JetstreamServiceProvider.php   -- add retainers:* permissions to each role
app/Providers/AppServiceProvider.php         -- register TimeEntryRetainerObserver
routes/api.php                                -- add retainer routes (CRUD + status + sub-caps + periods)
resources/js/                                 -- mount RetainerTab into existing client detail page
```

---

## Conventions used throughout this plan

- All durations stored and exchanged as **integer seconds** (matches existing `estimated_time`, `start`/`end` derivations).
- All migrations use `YYYY_MM_DD_HHMMSS_*` naming with today's date `2026_05_26`.
- All models use `HasUuids` and `HasFactory`.
- All API controllers extend `App\Http\Controllers\Api\V1\Controller` and call `$this->checkPermission($organization, '...')`.
- Tests use Pest where the repo uses Pest; PHPUnit class-style otherwise. Match existing files in the same directory.
- Commits on this branch use the `local:` prefix per the reyem workflow.

---

## Task 1: Permission strings

**Files:**
- Modify: `app/Providers/JetstreamServiceProvider.php`
- Test: `tests/Unit/Service/PermissionStoreTest.php` (likely exists — verify; if not, skip the test step and verify by booting Laravel)

**Why first:** Every later task uses `checkPermission($org, 'retainers:...')`. Adding the strings first means downstream code doesn't fail authorization in tests.

- [ ] **Step 1: Read the existing role/permission registration to see exact structure**

Run: `grep -n "clients:" app/Providers/JetstreamServiceProvider.php`

Note which roles get `clients:view`, `clients:create`, `clients:update`, `clients:delete`. We add the equivalent retainer strings to the same roles.

- [ ] **Step 2: Add retainer permissions to JetstreamServiceProvider**

Inside each `Jetstream::role(...)` call where a `clients:*` permission already exists, add the matching `retainers:*` permission with the same access semantics:

| Role | Permissions to add |
|---|---|
| Owner | `retainers:view`, `retainers:view:all`, `retainers:create`, `retainers:update`, `retainers:delete` |
| Admin | `retainers:view`, `retainers:view:all`, `retainers:create`, `retainers:update`, `retainers:delete` |
| Manager | `retainers:view`, `retainers:view:all`, `retainers:create`, `retainers:update`, `retainers:delete` |
| Employee | `retainers:view` only (read-only) |
| Placeholder | (none — placeholders have no access) |

- [ ] **Step 3: Verify Laravel can boot**

Run: `php artisan about | head -5`
Expected: Output prints, no exceptions.

- [ ] **Step 4: Commit**

```bash
git add app/Providers/JetstreamServiceProvider.php
git commit -m "local: add retainers:* permissions to roles"
```

---

## Task 2: Enums

**Files:**
- Create: `app/Enums/RetainerPeriodMode.php`
- Create: `app/Enums/RetainerPeriodUnit.php`
- Create: `app/Enums/RetainerHardCapScope.php`
- Create: `app/Enums/RetainerHardCapEnforcement.php`
- Create: `app/Enums/RetainerSubCapMode.php`

**Why before migrations:** Migrations reference enum values; defining first means the migration can `RetainerPeriodMode::cases()` for the check constraint or just use string defaults.

- [ ] **Step 1: Create RetainerPeriodMode enum**

```php
<?php
declare(strict_types=1);
namespace App\Enums;

enum RetainerPeriodMode: string
{
    case Calendar = 'calendar';
    case Anchor = 'anchor';
    case Explicit = 'explicit';
}
```

- [ ] **Step 2: Create RetainerPeriodUnit enum**

```php
<?php
declare(strict_types=1);
namespace App\Enums;

enum RetainerPeriodUnit: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
}
```

- [ ] **Step 3: Create RetainerHardCapScope enum**

```php
<?php
declare(strict_types=1);
namespace App\Enums;

enum RetainerHardCapScope: string
{
    case PerPeriod = 'per_period';
    case Cumulative = 'cumulative';
}
```

- [ ] **Step 4: Create RetainerHardCapEnforcement enum**

```php
<?php
declare(strict_types=1);
namespace App\Enums;

enum RetainerHardCapEnforcement: string
{
    case Block = 'block';
    case Flag = 'flag';        // reserved for v1.1
    case Approval = 'approval'; // reserved for v1.1
}
```

- [ ] **Step 5: Create RetainerSubCapMode enum**

```php
<?php
declare(strict_types=1);
namespace App\Enums;

enum RetainerSubCapMode: string
{
    case Soft = 'soft';
    case Strict = 'strict';
}
```

- [ ] **Step 6: Verify autoload + syntax**

Run: `composer dump-autoload && php -r "var_dump(App\Enums\RetainerPeriodMode::Calendar->value);"`
Expected: `string(8) "calendar"`

- [ ] **Step 7: Commit**

```bash
git add app/Enums/Retainer*.php
git commit -m "local: add retainer enums (period mode/unit, hard cap, sub-cap mode)"
```

---

## Task 3: `retainers` migration

**Files:**
- Create: `database/migrations/2026_05_26_120001_create_retainers_table.php`

- [ ] **Step 1: Create the migration file**

```php
<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retainers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('client_id');
            $table->string('name', 255);
            $table->text('description')->nullable();

            $table->string('period_mode', 32);                   // calendar|anchor|explicit
            $table->string('period_unit', 32)->nullable();       // weekly|monthly|quarterly (null for explicit)
            $table->integer('seconds_per_period')->unsigned()->nullable();
            $table->date('anchor_date')->nullable();

            $table->date('starts_at');
            $table->date('ends_at')->nullable();

            $table->boolean('billable_only')->default(true);
            $table->boolean('hard_cap_enabled')->default(false);
            $table->string('hard_cap_scope', 32)->nullable();         // per_period|cumulative
            $table->string('hard_cap_enforcement', 32)->nullable();   // block|flag|approval
            $table->integer('hard_cap_cumulative_seconds')->unsigned()->nullable();
            $table->string('sub_cap_mode', 32)->default('soft');

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();

            $table->index(['organization_id', 'client_id']);
            $table->index(['client_id', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retainers');
    }
};
```

- [ ] **Step 2: Run the migration**

Run: `php artisan migrate`
Expected: `INFO  Running migrations. ... 2026_05_26_120001_create_retainers_table ...... DONE`

- [ ] **Step 3: Verify the table**

Run: `php artisan db:show retainers --json 2>/dev/null | head -40` (or `\d retainers` in psql if simpler)
Expected: 19 columns present including `period_mode`, `hard_cap_enabled`, `deleted_at`.

- [ ] **Step 4: Test rollback works**

Run: `php artisan migrate:rollback --step=1 && php artisan migrate`
Expected: rollback drops the table; re-running the migration recreates it cleanly.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_05_26_120001_create_retainers_table.php
git commit -m "local: create retainers table"
```

---

## Task 4: `retainer_periods` migration

**Files:**
- Create: `database/migrations/2026_05_26_120002_create_retainer_periods_table.php`

- [ ] **Step 1: Create the migration**

```php
<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retainer_periods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('retainer_id');
            $table->date('starts_at');
            $table->date('ends_at');
            $table->integer('seconds_allocated')->unsigned();
            $table->timestamps();

            $table->foreign('retainer_id')->references('id')->on('retainers')->cascadeOnDelete();
            $table->unique(['retainer_id', 'starts_at']);
            $table->index(['retainer_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retainer_periods');
    }
};
```

- [ ] **Step 2: Migrate and verify**

Run: `php artisan migrate`
Expected: migration runs cleanly.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_05_26_120002_create_retainer_periods_table.php
git commit -m "local: create retainer_periods table"
```

---

## Task 5: `retainer_project_caps` migration

**Files:**
- Create: `database/migrations/2026_05_26_120003_create_retainer_project_caps_table.php`

- [ ] **Step 1: Create the migration**

```php
<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retainer_project_caps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('retainer_id');
            $table->uuid('project_id');
            $table->integer('seconds_per_period')->unsigned()->nullable();
            $table->integer('seconds_cumulative')->unsigned()->nullable();
            $table->timestamps();

            $table->foreign('retainer_id')->references('id')->on('retainers')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->unique(['retainer_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retainer_project_caps');
    }
};
```

- [ ] **Step 2: Migrate and verify**

Run: `php artisan migrate`

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_05_26_120003_create_retainer_project_caps_table.php
git commit -m "local: create retainer_project_caps table"
```

---

## Task 6: Models + factories

**Files:**
- Create: `app/Models/Retainer.php`
- Create: `app/Models/RetainerPeriod.php`
- Create: `app/Models/RetainerProjectCap.php`
- Create: `database/factories/RetainerFactory.php`
- Create: `database/factories/RetainerPeriodFactory.php`
- Create: `database/factories/RetainerProjectCapFactory.php`
- Test: `tests/Unit/Model/RetainerTest.php`

- [ ] **Step 1: Read an existing model + factory to match conventions exactly**

Run: `cat app/Models/Client.php database/factories/ClientFactory.php`

Look for: `HasUuids`, `HasFactory`, `casts`, `fillable`, factory's `definition()` shape, how `organization_id` is set.

- [ ] **Step 2: Write the failing factory test for `Retainer`**

Create `tests/Unit/Model/RetainerTest.php`:

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Model;

use App\Enums\RetainerPeriodMode;
use App\Enums\RetainerPeriodUnit;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Retainer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RetainerTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_valid_retainer_with_relationships(): void
    {
        $retainer = Retainer::factory()->create();

        $this->assertNotNull($retainer->id);
        $this->assertInstanceOf(RetainerPeriodMode::class, $retainer->period_mode);
        $this->assertInstanceOf(Organization::class, $retainer->organization);
        $this->assertInstanceOf(Client::class, $retainer->client);
    }

    public function test_default_factory_state_is_monthly_calendar_billable_only_no_cap(): void
    {
        $retainer = Retainer::factory()->create();

        $this->assertSame(RetainerPeriodMode::Calendar, $retainer->period_mode);
        $this->assertSame(RetainerPeriodUnit::Monthly, $retainer->period_unit);
        $this->assertTrue($retainer->billable_only);
        $this->assertFalse($retainer->hard_cap_enabled);
        $this->assertSame(40 * 3600, $retainer->seconds_per_period);
    }
}
```

- [ ] **Step 3: Run test to verify failure**

Run: `./vendor/bin/phpunit tests/Unit/Model/RetainerTest.php`
Expected: failures (`Retainer` class not found).

- [ ] **Step 4: Create `Retainer` model**

```php
<?php
declare(strict_types=1);

namespace App\Models;

use App\Enums\RetainerHardCapEnforcement;
use App\Enums\RetainerHardCapScope;
use App\Enums\RetainerPeriodMode;
use App\Enums\RetainerPeriodUnit;
use App\Enums\RetainerSubCapMode;
use App\Models\Concerns\HasUuids;
use Database\Factories\RetainerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $client_id
 * @property string $name
 * @property string|null $description
 * @property RetainerPeriodMode $period_mode
 * @property RetainerPeriodUnit|null $period_unit
 * @property int|null $seconds_per_period
 * @property Carbon|null $anchor_date
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 * @property bool $billable_only
 * @property bool $hard_cap_enabled
 * @property RetainerHardCapScope|null $hard_cap_scope
 * @property RetainerHardCapEnforcement|null $hard_cap_enforcement
 * @property int|null $hard_cap_cumulative_seconds
 * @property RetainerSubCapMode $sub_cap_mode
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Retainer extends Model
{
    /** @use HasFactory<RetainerFactory> */
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $casts = [
        'period_mode' => RetainerPeriodMode::class,
        'period_unit' => RetainerPeriodUnit::class,
        'anchor_date' => 'date',
        'starts_at' => 'date',
        'ends_at' => 'date',
        'billable_only' => 'bool',
        'hard_cap_enabled' => 'bool',
        'hard_cap_scope' => RetainerHardCapScope::class,
        'hard_cap_enforcement' => RetainerHardCapEnforcement::class,
        'sub_cap_mode' => RetainerSubCapMode::class,
    ];

    protected $fillable = [
        'organization_id',
        'client_id',
        'name',
        'description',
        'period_mode',
        'period_unit',
        'seconds_per_period',
        'anchor_date',
        'starts_at',
        'ends_at',
        'billable_only',
        'hard_cap_enabled',
        'hard_cap_scope',
        'hard_cap_enforcement',
        'hard_cap_cumulative_seconds',
        'sub_cap_mode',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function periods(): HasMany
    {
        return $this->hasMany(RetainerPeriod::class);
    }

    public function projectCaps(): HasMany
    {
        return $this->hasMany(RetainerProjectCap::class);
    }
}
```

- [ ] **Step 5: Create `RetainerPeriod` model**

```php
<?php
declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuids;
use Database\Factories\RetainerPeriodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $retainer_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property int $seconds_allocated
 */
class RetainerPeriod extends Model
{
    /** @use HasFactory<RetainerPeriodFactory> */
    use HasFactory;
    use HasUuids;

    protected $casts = [
        'starts_at' => 'date',
        'ends_at' => 'date',
        'seconds_allocated' => 'int',
    ];

    protected $fillable = ['retainer_id', 'starts_at', 'ends_at', 'seconds_allocated'];

    public function retainer(): BelongsTo
    {
        return $this->belongsTo(Retainer::class);
    }
}
```

- [ ] **Step 6: Create `RetainerProjectCap` model**

```php
<?php
declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuids;
use Database\Factories\RetainerProjectCapFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $retainer_id
 * @property string $project_id
 * @property int|null $seconds_per_period
 * @property int|null $seconds_cumulative
 */
class RetainerProjectCap extends Model
{
    /** @use HasFactory<RetainerProjectCapFactory> */
    use HasFactory;
    use HasUuids;

    protected $casts = [
        'seconds_per_period' => 'int',
        'seconds_cumulative' => 'int',
    ];

    protected $fillable = ['retainer_id', 'project_id', 'seconds_per_period', 'seconds_cumulative'];

    public function retainer(): BelongsTo
    {
        return $this->belongsTo(Retainer::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
```

- [ ] **Step 7: Create `RetainerFactory`**

```php
<?php
declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RetainerPeriodMode;
use App\Enums\RetainerPeriodUnit;
use App\Enums\RetainerSubCapMode;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Retainer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Retainer>
 */
class RetainerFactory extends Factory
{
    protected $model = Retainer::class;

    public function definition(): array
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->create(['organization_id' => $organization->id]);

        return [
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'name' => 'Retainer for '.$client->name,
            'description' => null,
            'period_mode' => RetainerPeriodMode::Calendar,
            'period_unit' => RetainerPeriodUnit::Monthly,
            'seconds_per_period' => 40 * 3600,
            'anchor_date' => null,
            'starts_at' => Carbon::today()->startOfMonth(),
            'ends_at' => null,
            'billable_only' => true,
            'hard_cap_enabled' => false,
            'hard_cap_scope' => null,
            'hard_cap_enforcement' => null,
            'hard_cap_cumulative_seconds' => null,
            'sub_cap_mode' => RetainerSubCapMode::Soft,
        ];
    }

    public function forOrganization(Organization $organization): self
    {
        return $this->state(function () use ($organization) {
            $client = Client::factory()->create(['organization_id' => $organization->id]);
            return [
                'organization_id' => $organization->id,
                'client_id' => $client->id,
            ];
        });
    }

    public function withHardCap(string $scope = 'per_period', string $enforcement = 'block'): self
    {
        return $this->state(fn () => [
            'hard_cap_enabled' => true,
            'hard_cap_scope' => $scope,
            'hard_cap_enforcement' => $enforcement,
            'hard_cap_cumulative_seconds' => $scope === 'cumulative' ? 200 * 3600 : null,
        ]);
    }
}
```

- [ ] **Step 8: Create `RetainerPeriodFactory`**

```php
<?php
declare(strict_types=1);

namespace Database\Factories;

use App\Models\Retainer;
use App\Models\RetainerPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<RetainerPeriod>
 */
class RetainerPeriodFactory extends Factory
{
    protected $model = RetainerPeriod::class;

    public function definition(): array
    {
        return [
            'retainer_id' => Retainer::factory(),
            'starts_at' => Carbon::today()->startOfMonth(),
            'ends_at' => Carbon::today()->endOfMonth(),
            'seconds_allocated' => 40 * 3600,
        ];
    }
}
```

- [ ] **Step 9: Create `RetainerProjectCapFactory`**

```php
<?php
declare(strict_types=1);

namespace Database\Factories;

use App\Models\Project;
use App\Models\Retainer;
use App\Models\RetainerProjectCap;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RetainerProjectCap>
 */
class RetainerProjectCapFactory extends Factory
{
    protected $model = RetainerProjectCap::class;

    public function definition(): array
    {
        return [
            'retainer_id' => Retainer::factory(),
            'project_id' => Project::factory(),
            'seconds_per_period' => 20 * 3600,
            'seconds_cumulative' => null,
        ];
    }
}
```

- [ ] **Step 10: Run tests**

Run: `./vendor/bin/phpunit tests/Unit/Model/RetainerTest.php`
Expected: 2 passing tests.

- [ ] **Step 11: Commit**

```bash
git add app/Models/Retainer*.php database/factories/Retainer*.php tests/Unit/Model/RetainerTest.php
git commit -m "local: add Retainer/RetainerPeriod/RetainerProjectCap models + factories"
```

---

## Task 7: `AllocationCalculator` service (pure math)

**Files:**
- Create: `app/Service/Retainer/AllocationCalculator.php`
- Test: `tests/Unit/Service/Retainer/AllocationCalculatorTest.php`

The calculator is **pure math**. It receives a `Retainer` model (read-only) and a `Carbon` date, returns an integer. No DB queries. This isolation is the key reason this unit is trivially testable.

**Method signature:**

```php
public function computeAllocated(Retainer $retainer, Carbon $asOf): int;
```

Returns total seconds allocated as of `$asOf`. Includes proration of the current in-progress period.

**Algorithm:**

- If `$asOf < retainer->starts_at`: return 0.
- If `retainer->ends_at` is set and `$asOf > retainer->ends_at`: clamp `$asOf = retainer->ends_at`.
- For `calendar` and `anchor` modes:
  - Determine period boundaries for the period containing `$asOf` (`periodStart`, `periodEnd`).
  - `completedPeriods = number of complete periods from retainer->starts_at up to (but not including) periodStart`
  - `elapsedInCurrent = max(0, $asOf - max(periodStart, retainer->starts_at))` in seconds
  - `periodLength = periodEnd - periodStart` in seconds
  - `proratedCurrent = round((elapsedInCurrent / periodLength) * seconds_per_period)`
  - return `completedPeriods * seconds_per_period + proratedCurrent`
- For `explicit` mode:
  - Sum `seconds_allocated` for every period where `ends_at <= $asOf`.
  - For the period containing `$asOf` (if any): prorate same as above.

- [ ] **Step 1: Write failing tests for calendar monthly mode**

Create `tests/Unit/Service/Retainer/AllocationCalculatorTest.php`:

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Service\Retainer;

use App\Enums\RetainerPeriodMode;
use App\Enums\RetainerPeriodUnit;
use App\Models\Retainer;
use App\Service\Retainer\AllocationCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AllocationCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private AllocationCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new AllocationCalculator;
    }

    public function test_returns_zero_before_retainer_starts(): void
    {
        $retainer = Retainer::factory()->make([
            'period_mode' => RetainerPeriodMode::Calendar,
            'period_unit' => RetainerPeriodUnit::Monthly,
            'seconds_per_period' => 40 * 3600,
            'starts_at' => Carbon::parse('2026-06-01'),
            'ends_at' => null,
        ]);

        $this->assertSame(0, $this->calculator->computeAllocated($retainer, Carbon::parse('2026-05-15')));
    }

    public function test_calendar_monthly_first_day_of_first_period(): void
    {
        $retainer = Retainer::factory()->make([
            'period_mode' => RetainerPeriodMode::Calendar,
            'period_unit' => RetainerPeriodUnit::Monthly,
            'seconds_per_period' => 40 * 3600,
            'starts_at' => Carbon::parse('2026-06-01'),
        ]);

        // On day 1 of a 30-day month, elapsed = 0 → allocated = 0
        $this->assertSame(0, $this->calculator->computeAllocated($retainer, Carbon::parse('2026-06-01')));
    }

    public function test_calendar_monthly_half_through_first_period(): void
    {
        $retainer = Retainer::factory()->make([
            'period_mode' => RetainerPeriodMode::Calendar,
            'period_unit' => RetainerPeriodUnit::Monthly,
            'seconds_per_period' => 40 * 3600,
            'starts_at' => Carbon::parse('2026-06-01'),
        ]);

        // Mid-June (15 / 30 days) → ~50% of 40h = ~20h
        $result = $this->calculator->computeAllocated($retainer, Carbon::parse('2026-06-15'));
        $this->assertEqualsWithDelta(20 * 3600, $result, 3600); // within 1h tolerance for proration rounding
    }

    public function test_calendar_monthly_completed_period_plus_partial(): void
    {
        $retainer = Retainer::factory()->make([
            'period_mode' => RetainerPeriodMode::Calendar,
            'period_unit' => RetainerPeriodUnit::Monthly,
            'seconds_per_period' => 40 * 3600,
            'starts_at' => Carbon::parse('2026-06-01'),
        ]);

        // June complete (40h) + mid-July (~50% of 40h = 20h) → ~60h
        $result = $this->calculator->computeAllocated($retainer, Carbon::parse('2026-07-15'));
        $this->assertEqualsWithDelta(60 * 3600, $result, 3600);
    }

    public function test_calendar_monthly_retainer_starting_mid_period_only_counts_from_start(): void
    {
        $retainer = Retainer::factory()->make([
            'period_mode' => RetainerPeriodMode::Calendar,
            'period_unit' => RetainerPeriodUnit::Monthly,
            'seconds_per_period' => 40 * 3600,
            'starts_at' => Carbon::parse('2026-06-15'),
        ]);

        // Retainer started June 15, asOf June 30 → 15/30 days of June only
        $result = $this->calculator->computeAllocated($retainer, Carbon::parse('2026-06-30'));
        $this->assertEqualsWithDelta(20 * 3600, $result, 3600);
    }

    public function test_calendar_weekly_one_full_week(): void
    {
        $retainer = Retainer::factory()->make([
            'period_mode' => RetainerPeriodMode::Calendar,
            'period_unit' => RetainerPeriodUnit::Weekly,
            'seconds_per_period' => 10 * 3600,
            'starts_at' => Carbon::parse('2026-06-01'), // Monday
        ]);

        // After one full week (Mon-Sun → next Mon) the elapsed in NEW week = 0
        $result = $this->calculator->computeAllocated($retainer, Carbon::parse('2026-06-08'));
        $this->assertSame(10 * 3600, $result);
    }

    public function test_calendar_quarterly_completed_q(): void
    {
        $retainer = Retainer::factory()->make([
            'period_mode' => RetainerPeriodMode::Calendar,
            'period_unit' => RetainerPeriodUnit::Quarterly,
            'seconds_per_period' => 120 * 3600,
            'starts_at' => Carbon::parse('2026-01-01'),
        ]);

        // Q1 complete on April 1
        $result = $this->calculator->computeAllocated($retainer, Carbon::parse('2026-04-01'));
        $this->assertSame(120 * 3600, $result);
    }

    public function test_anchor_monthly_anchored_to_mid_month(): void
    {
        $retainer = Retainer::factory()->make([
            'period_mode' => RetainerPeriodMode::Anchor,
            'period_unit' => RetainerPeriodUnit::Monthly,
            'seconds_per_period' => 40 * 3600,
            'anchor_date' => Carbon::parse('2026-06-15'),
            'starts_at' => Carbon::parse('2026-06-15'),
        ]);

        // Period 1 is Jun 15 - Jul 14. On Jul 15 the period is complete.
        $result = $this->calculator->computeAllocated($retainer, Carbon::parse('2026-07-15'));
        $this->assertSame(40 * 3600, $result);
    }

    public function test_explicit_mode_sums_completed_plus_partial(): void
    {
        $retainer = Retainer::factory()->create([
            'period_mode' => RetainerPeriodMode::Explicit,
            'period_unit' => null,
            'seconds_per_period' => null,
            'starts_at' => Carbon::parse('2026-01-01'),
        ]);
        $retainer->periods()->create([
            'starts_at' => '2026-01-01', 'ends_at' => '2026-01-31', 'seconds_allocated' => 40 * 3600,
        ]);
        $retainer->periods()->create([
            'starts_at' => '2026-02-01', 'ends_at' => '2026-02-28', 'seconds_allocated' => 60 * 3600,
        ]);
        $retainer->load('periods');

        // Asof Feb 14: Jan period complete (40h) + half of Feb (~30h) = ~70h
        $result = $this->calculator->computeAllocated($retainer, Carbon::parse('2026-02-14'));
        $this->assertEqualsWithDelta(70 * 3600, $result, 3600);
    }

    public function test_clamps_to_ends_at(): void
    {
        $retainer = Retainer::factory()->make([
            'period_mode' => RetainerPeriodMode::Calendar,
            'period_unit' => RetainerPeriodUnit::Monthly,
            'seconds_per_period' => 40 * 3600,
            'starts_at' => Carbon::parse('2026-06-01'),
            'ends_at' => Carbon::parse('2026-07-31'),
        ]);

        // Asof Dec — clamps to July 31. Should equal 2 full periods (80h).
        $result = $this->calculator->computeAllocated($retainer, Carbon::parse('2026-12-31'));
        $this->assertEqualsWithDelta(80 * 3600, $result, 3600);
    }
}
```

- [ ] **Step 2: Run tests to verify failure**

Run: `./vendor/bin/phpunit tests/Unit/Service/Retainer/AllocationCalculatorTest.php`
Expected: failures (class not found).

- [ ] **Step 3: Implement `AllocationCalculator`**

```php
<?php
declare(strict_types=1);

namespace App\Service\Retainer;

use App\Enums\RetainerPeriodMode;
use App\Enums\RetainerPeriodUnit;
use App\Models\Retainer;
use App\Models\RetainerPeriod;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class AllocationCalculator
{
    public function computeAllocated(Retainer $retainer, Carbon $asOf): int
    {
        $start = $retainer->starts_at->copy()->startOfDay();
        $asOf = $asOf->copy()->endOfDay();

        if ($asOf->lt($start)) {
            return 0;
        }

        if ($retainer->ends_at !== null && $asOf->gt($retainer->ends_at)) {
            $asOf = $retainer->ends_at->copy()->endOfDay();
        }

        return match ($retainer->period_mode) {
            RetainerPeriodMode::Calendar => $this->computeRecurring($retainer, $asOf, anchored: false),
            RetainerPeriodMode::Anchor => $this->computeRecurring($retainer, $asOf, anchored: true),
            RetainerPeriodMode::Explicit => $this->computeExplicit($retainer, $asOf),
        };
    }

    private function computeRecurring(Retainer $retainer, Carbon $asOf, bool $anchored): int
    {
        if ($retainer->period_unit === null || $retainer->seconds_per_period === null) {
            throw new InvalidArgumentException('Recurring retainer requires period_unit and seconds_per_period');
        }

        $rate = $retainer->seconds_per_period;
        $cursor = $anchored
            ? $retainer->anchor_date->copy()->startOfDay()
            : $this->periodStartFor($retainer->starts_at, $retainer->period_unit);

        // Walk forward in whole periods until the period containing $asOf
        $allocated = 0;
        $effectiveStart = $retainer->starts_at->copy()->startOfDay();

        while (true) {
            $periodEnd = $this->periodEndFor($cursor, $retainer->period_unit, $anchored);
            $effPeriodStart = $cursor->lt($effectiveStart) ? $effectiveStart : $cursor;

            if ($periodEnd->lt($asOf)) {
                // Complete period
                $fullLen = $periodEnd->diffInSeconds($cursor);
                $effLen  = $periodEnd->diffInSeconds($effPeriodStart);
                $allocated += (int) round(($effLen / $fullLen) * $rate);
                $cursor = $periodEnd->copy()->addDay()->startOfDay();
                continue;
            }

            // Partial period containing $asOf
            $fullLen = $periodEnd->diffInSeconds($cursor);
            $usedLen = $asOf->diffInSeconds($effPeriodStart);
            $allocated += (int) round((max(0, $usedLen) / $fullLen) * $rate);
            break;
        }

        return $allocated;
    }

    private function computeExplicit(Retainer $retainer, Carbon $asOf): int
    {
        $periods = $retainer->periods()->orderBy('starts_at')->get();

        $allocated = 0;
        foreach ($periods as $p) {
            $pStart = $p->starts_at->copy()->startOfDay();
            $pEnd = $p->ends_at->copy()->endOfDay();
            if ($pEnd->lte($asOf)) {
                $allocated += $p->seconds_allocated;
                continue;
            }
            if ($pStart->gt($asOf)) {
                break;
            }
            $full = $pEnd->diffInSeconds($pStart);
            $used = $asOf->diffInSeconds($pStart);
            $allocated += (int) round(($used / $full) * $p->seconds_allocated);
        }

        return $allocated;
    }

    private function periodStartFor(Carbon $date, RetainerPeriodUnit $unit): Carbon
    {
        return match ($unit) {
            RetainerPeriodUnit::Weekly => $date->copy()->startOfWeek(Carbon::MONDAY),
            RetainerPeriodUnit::Monthly => $date->copy()->startOfMonth(),
            RetainerPeriodUnit::Quarterly => $date->copy()->firstOfQuarter(),
        };
    }

    private function periodEndFor(Carbon $cursor, RetainerPeriodUnit $unit, bool $anchored): Carbon
    {
        if ($anchored) {
            // Anchor mode: fixed period length from cursor
            return match ($unit) {
                RetainerPeriodUnit::Weekly => $cursor->copy()->addWeek()->subDay()->endOfDay(),
                RetainerPeriodUnit::Monthly => $cursor->copy()->addMonth()->subDay()->endOfDay(),
                RetainerPeriodUnit::Quarterly => $cursor->copy()->addMonths(3)->subDay()->endOfDay(),
            };
        }

        return match ($unit) {
            RetainerPeriodUnit::Weekly => $cursor->copy()->endOfWeek(Carbon::SUNDAY),
            RetainerPeriodUnit::Monthly => $cursor->copy()->endOfMonth(),
            RetainerPeriodUnit::Quarterly => $cursor->copy()->lastOfQuarter()->endOfDay(),
        };
    }
}
```

- [ ] **Step 4: Run tests until all pass**

Run: `./vendor/bin/phpunit tests/Unit/Service/Retainer/AllocationCalculatorTest.php`
Expected: 10 passing tests.

If any fail, debug the specific test in isolation, fix the algorithm, re-run.

- [ ] **Step 5: Commit**

```bash
git add app/Service/Retainer/AllocationCalculator.php tests/Unit/Service/Retainer/AllocationCalculatorTest.php
git commit -m "local: add AllocationCalculator (calendar/anchor/explicit, prorated current period)"
```

---

## Task 8: `ConsumptionQuery` service

**Files:**
- Create: `app/Service/Retainer/ConsumptionQuery.php`
- Test: `tests/Unit/Service/Retainer/ConsumptionQueryTest.php`

**Method signature:**

```php
public function computeTracked(Retainer $retainer, Carbon $asOf, ?string $projectId = null): int;
```

Returns total seconds of time entries against the retainer's client, between `retainer->starts_at` and `$asOf`, respecting `billable_only`. If `$projectId` is provided, restricts to that project (for sub-cap consumption).

**SQL** (PostgreSQL):

```sql
SELECT COALESCE(SUM(EXTRACT(EPOCH FROM (time_entries.end - time_entries.start))::int), 0) AS seconds
FROM time_entries
JOIN projects ON projects.id = time_entries.project_id
WHERE projects.client_id = :client_id
  AND time_entries.end IS NOT NULL
  AND time_entries.start >= :starts_at
  AND time_entries.end <= :as_of
  AND (:billable_only = false OR time_entries.billable = true)
  AND (:project_id IS NULL OR time_entries.project_id = :project_id)
```

Running entries (`end IS NULL`) are excluded.

- [ ] **Step 1: Write failing tests**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Service\Retainer;

use App\Models\Client;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Retainer;
use App\Models\TimeEntry;
use App\Models\User;
use App\Service\Retainer\ConsumptionQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ConsumptionQueryTest extends TestCase
{
    use RefreshDatabase;

    private ConsumptionQuery $query;

    protected function setUp(): void
    {
        parent::setUp();
        $this->query = new ConsumptionQuery;
    }

    private function makeContext(): array
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withPersonalOrganization()->create();
        $org->users()->attach($user->id, ['role' => 'admin']);
        $member = Member::factory()->create(['user_id' => $user->id, 'organization_id' => $org->id]);
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $project = Project::factory()->create(['organization_id' => $org->id, 'client_id' => $client->id]);
        return compact('org', 'user', 'member', 'client', 'project');
    }

    private function makeEntry(array $context, string $start, string $end, bool $billable = true, ?Project $project = null): TimeEntry
    {
        return TimeEntry::factory()->create([
            'organization_id' => $context['org']->id,
            'user_id' => $context['user']->id,
            'member_id' => $context['member']->id,
            'project_id' => ($project ?? $context['project'])->id,
            'client_id' => $context['client']->id,
            'start' => $start,
            'end' => $end,
            'billable' => $billable,
        ]);
    }

    public function test_sums_billable_entries_within_window(): void
    {
        $ctx = $this->makeContext();
        $this->makeEntry($ctx, '2026-06-01 09:00', '2026-06-01 11:00'); // 2h billable
        $this->makeEntry($ctx, '2026-06-02 14:00', '2026-06-02 15:30'); // 1.5h billable

        $retainer = Retainer::factory()->create([
            'organization_id' => $ctx['org']->id,
            'client_id' => $ctx['client']->id,
            'starts_at' => '2026-06-01',
            'billable_only' => true,
        ]);

        $result = $this->query->computeTracked($retainer, Carbon::parse('2026-06-30 23:59:59'));
        $this->assertSame((int) (3.5 * 3600), $result);
    }

    public function test_excludes_non_billable_when_billable_only(): void
    {
        $ctx = $this->makeContext();
        $this->makeEntry($ctx, '2026-06-01 09:00', '2026-06-01 11:00', billable: true);
        $this->makeEntry($ctx, '2026-06-01 12:00', '2026-06-01 14:00', billable: false);

        $retainer = Retainer::factory()->create([
            'organization_id' => $ctx['org']->id,
            'client_id' => $ctx['client']->id,
            'starts_at' => '2026-06-01',
            'billable_only' => true,
        ]);

        $this->assertSame(2 * 3600, $this->query->computeTracked($retainer, Carbon::parse('2026-06-30 23:59:59')));
    }

    public function test_includes_non_billable_when_billable_only_disabled(): void
    {
        $ctx = $this->makeContext();
        $this->makeEntry($ctx, '2026-06-01 09:00', '2026-06-01 11:00', billable: true);
        $this->makeEntry($ctx, '2026-06-01 12:00', '2026-06-01 14:00', billable: false);

        $retainer = Retainer::factory()->create([
            'organization_id' => $ctx['org']->id,
            'client_id' => $ctx['client']->id,
            'starts_at' => '2026-06-01',
            'billable_only' => false,
        ]);

        $this->assertSame(4 * 3600, $this->query->computeTracked($retainer, Carbon::parse('2026-06-30 23:59:59')));
    }

    public function test_excludes_entries_before_retainer_starts_at(): void
    {
        $ctx = $this->makeContext();
        $this->makeEntry($ctx, '2026-05-30 09:00', '2026-05-30 11:00');
        $this->makeEntry($ctx, '2026-06-01 09:00', '2026-06-01 10:00');

        $retainer = Retainer::factory()->create([
            'organization_id' => $ctx['org']->id,
            'client_id' => $ctx['client']->id,
            'starts_at' => '2026-06-01',
        ]);

        $this->assertSame(3600, $this->query->computeTracked($retainer, Carbon::parse('2026-06-30 23:59:59')));
    }

    public function test_excludes_still_running_entries(): void
    {
        $ctx = $this->makeContext();
        $this->makeEntry($ctx, '2026-06-01 09:00', '2026-06-01 10:00');
        TimeEntry::factory()->create([
            'organization_id' => $ctx['org']->id,
            'user_id' => $ctx['user']->id,
            'member_id' => $ctx['member']->id,
            'project_id' => $ctx['project']->id,
            'client_id' => $ctx['client']->id,
            'start' => '2026-06-15 09:00',
            'end' => null,
            'billable' => true,
        ]);

        $retainer = Retainer::factory()->create([
            'organization_id' => $ctx['org']->id,
            'client_id' => $ctx['client']->id,
            'starts_at' => '2026-06-01',
        ]);

        $this->assertSame(3600, $this->query->computeTracked($retainer, Carbon::parse('2026-06-30 23:59:59')));
    }

    public function test_filters_by_project_id_for_sub_cap(): void
    {
        $ctx = $this->makeContext();
        $project2 = Project::factory()->create([
            'organization_id' => $ctx['org']->id,
            'client_id' => $ctx['client']->id,
        ]);
        $this->makeEntry($ctx, '2026-06-01 09:00', '2026-06-01 11:00');                       // 2h on project 1
        $this->makeEntry($ctx, '2026-06-02 09:00', '2026-06-02 13:00', project: $project2);  // 4h on project 2

        $retainer = Retainer::factory()->create([
            'organization_id' => $ctx['org']->id,
            'client_id' => $ctx['client']->id,
            'starts_at' => '2026-06-01',
        ]);

        $this->assertSame(2 * 3600, $this->query->computeTracked($retainer, Carbon::parse('2026-06-30 23:59:59'), $ctx['project']->id));
        $this->assertSame(4 * 3600, $this->query->computeTracked($retainer, Carbon::parse('2026-06-30 23:59:59'), $project2->id));
        $this->assertSame(6 * 3600, $this->query->computeTracked($retainer, Carbon::parse('2026-06-30 23:59:59')));
    }

    public function test_returns_zero_when_no_entries(): void
    {
        $ctx = $this->makeContext();
        $retainer = Retainer::factory()->create([
            'organization_id' => $ctx['org']->id,
            'client_id' => $ctx['client']->id,
            'starts_at' => '2026-06-01',
        ]);

        $this->assertSame(0, $this->query->computeTracked($retainer, Carbon::parse('2026-06-30 23:59:59')));
    }
}
```

- [ ] **Step 2: Run tests to verify failure**

Run: `./vendor/bin/phpunit tests/Unit/Service/Retainer/ConsumptionQueryTest.php`
Expected: class not found.

- [ ] **Step 3: Implement `ConsumptionQuery`**

```php
<?php
declare(strict_types=1);

namespace App\Service\Retainer;

use App\Models\Retainer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ConsumptionQuery
{
    public function computeTracked(Retainer $retainer, Carbon $asOf, ?string $projectId = null): int
    {
        $sql = <<<'SQL'
            SELECT COALESCE(SUM(EXTRACT(EPOCH FROM (time_entries.end - time_entries.start))::int), 0) AS seconds
            FROM time_entries
            JOIN projects ON projects.id = time_entries.project_id
            WHERE projects.client_id = :client_id
              AND time_entries.end IS NOT NULL
              AND time_entries.start >= :starts_at
              AND time_entries.end <= :as_of
              AND (NOT :billable_only OR time_entries.billable = true)
              AND (:project_id::uuid IS NULL OR time_entries.project_id = :project_id::uuid)
        SQL;

        $row = DB::selectOne($sql, [
            'client_id' => $retainer->client_id,
            'starts_at' => $retainer->starts_at->copy()->startOfDay(),
            'as_of' => $asOf,
            'billable_only' => $retainer->billable_only,
            'project_id' => $projectId,
        ]);

        return (int) ($row->seconds ?? 0);
    }
}
```

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/phpunit tests/Unit/Service/Retainer/ConsumptionQueryTest.php`
Expected: 7 passing tests.

- [ ] **Step 5: Commit**

```bash
git add app/Service/Retainer/ConsumptionQuery.php tests/Unit/Service/Retainer/ConsumptionQueryTest.php
git commit -m "local: add ConsumptionQuery (SUM(end-start) with billable + project filters)"
```

---

## Task 9: `RetainerLookup` service

**Files:**
- Create: `app/Service/Retainer/RetainerLookup.php`
- Test: `tests/Unit/Service/Retainer/RetainerLookupTest.php`

**Method:** `findActiveForClient(string $clientId, Carbon $date): ?Retainer`

Returns the (single, by validation constraint) non-deleted retainer for the client whose `[starts_at, ends_at]` window contains `$date`, or `null` if none.

- [ ] **Step 1: Write tests**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Service\Retainer;

use App\Models\Client;
use App\Models\Organization;
use App\Models\Retainer;
use App\Service\Retainer\RetainerLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RetainerLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_null_when_no_retainer_exists(): void
    {
        $client = Client::factory()->create();
        $this->assertNull((new RetainerLookup)->findActiveForClient($client->id, Carbon::today()));
    }

    public function test_returns_retainer_when_date_is_within_window(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $retainer = Retainer::factory()->create([
            'organization_id' => $org->id,
            'client_id' => $client->id,
            'starts_at' => '2026-06-01',
            'ends_at' => '2026-12-31',
        ]);

        $result = (new RetainerLookup)->findActiveForClient($client->id, Carbon::parse('2026-09-15'));
        $this->assertNotNull($result);
        $this->assertSame($retainer->id, $result->id);
    }

    public function test_returns_null_when_date_before_starts_at(): void
    {
        $client = Client::factory()->create();
        Retainer::factory()->create([
            'organization_id' => $client->organization_id,
            'client_id' => $client->id,
            'starts_at' => '2026-06-01',
        ]);

        $this->assertNull((new RetainerLookup)->findActiveForClient($client->id, Carbon::parse('2026-05-31')));
    }

    public function test_returns_null_when_date_after_ends_at(): void
    {
        $client = Client::factory()->create();
        Retainer::factory()->create([
            'organization_id' => $client->organization_id,
            'client_id' => $client->id,
            'starts_at' => '2026-06-01',
            'ends_at' => '2026-08-31',
        ]);

        $this->assertNull((new RetainerLookup)->findActiveForClient($client->id, Carbon::parse('2026-09-01')));
    }

    public function test_open_ended_retainer_matches_any_date_after_start(): void
    {
        $client = Client::factory()->create();
        Retainer::factory()->create([
            'organization_id' => $client->organization_id,
            'client_id' => $client->id,
            'starts_at' => '2026-06-01',
            'ends_at' => null,
        ]);

        $this->assertNotNull((new RetainerLookup)->findActiveForClient($client->id, Carbon::parse('2030-01-01')));
    }

    public function test_excludes_soft_deleted_retainers(): void
    {
        $client = Client::factory()->create();
        $retainer = Retainer::factory()->create([
            'organization_id' => $client->organization_id,
            'client_id' => $client->id,
            'starts_at' => '2026-06-01',
        ]);
        $retainer->delete();

        $this->assertNull((new RetainerLookup)->findActiveForClient($client->id, Carbon::parse('2026-07-01')));
    }
}
```

- [ ] **Step 2: Verify failure**

Run: `./vendor/bin/phpunit tests/Unit/Service/Retainer/RetainerLookupTest.php`

- [ ] **Step 3: Implement**

```php
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
```

- [ ] **Step 4: Run tests, all pass**

- [ ] **Step 5: Commit**

```bash
git add app/Service/Retainer/RetainerLookup.php tests/Unit/Service/Retainer/RetainerLookupTest.php
git commit -m "local: add RetainerLookup::findActiveForClient"
```

---

## Task 10: `RetainerCache` facade

**Files:**
- Create: `app/Service/Retainer/RetainerCache.php`
- Test: `tests/Unit/Service/Retainer/RetainerCacheTest.php`

Wraps `ConsumptionQuery` with Redis tag cache. TTL 5 min, key per `(retainer, as_of:Y-m-d)`, invalidation by retainer tag.

**Note:** Laravel's tag-based cache requires the Redis driver. Tests will use the cache repository directly via DI so they can run on `array` driver too.

- [ ] **Step 1: Write tests**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Service\Retainer;

use App\Models\Retainer;
use App\Service\Retainer\ConsumptionQuery;
use App\Service\Retainer\RetainerCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class RetainerCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_call_misses_and_caches_result(): void
    {
        Cache::flush();
        $retainer = Retainer::factory()->create();
        $asOf = Carbon::parse('2026-06-15');

        $query = Mockery::mock(ConsumptionQuery::class);
        $query->shouldReceive('computeTracked')->once()->andReturn(12345);

        $cache = new RetainerCache($query);

        $this->assertSame(12345, $cache->tracked($retainer, $asOf));
    }

    public function test_second_call_within_ttl_returns_cached_value_without_query(): void
    {
        Cache::flush();
        $retainer = Retainer::factory()->create();
        $asOf = Carbon::parse('2026-06-15');

        $query = Mockery::mock(ConsumptionQuery::class);
        $query->shouldReceive('computeTracked')->once()->andReturn(12345);

        $cache = new RetainerCache($query);
        $cache->tracked($retainer, $asOf);
        $second = $cache->tracked($retainer, $asOf);

        $this->assertSame(12345, $second);
    }

    public function test_invalidate_clears_cached_value(): void
    {
        Cache::flush();
        $retainer = Retainer::factory()->create();
        $asOf = Carbon::parse('2026-06-15');

        $query = Mockery::mock(ConsumptionQuery::class);
        $query->shouldReceive('computeTracked')->twice()->andReturn(100, 200);

        $cache = new RetainerCache($query);
        $this->assertSame(100, $cache->tracked($retainer, $asOf));
        $cache->invalidate($retainer);
        $this->assertSame(200, $cache->tracked($retainer, $asOf));
    }
}
```

- [ ] **Step 2: Verify failure**

Run: `./vendor/bin/phpunit tests/Unit/Service/Retainer/RetainerCacheTest.php`

- [ ] **Step 3: Implement**

```php
<?php
declare(strict_types=1);

namespace App\Service\Retainer;

use App\Models\Retainer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class RetainerCache
{
    private const TTL_SECONDS = 300;

    public function __construct(private readonly ConsumptionQuery $query) {}

    public function tracked(Retainer $retainer, Carbon $asOf, ?string $projectId = null): int
    {
        $key = $this->key($retainer, $asOf, $projectId);
        return Cache::tags($this->tag($retainer))->remember(
            $key,
            self::TTL_SECONDS,
            fn () => $this->query->computeTracked($retainer, $asOf, $projectId),
        );
    }

    public function invalidate(Retainer $retainer): void
    {
        Cache::tags($this->tag($retainer))->flush();
    }

    private function key(Retainer $retainer, Carbon $asOf, ?string $projectId): string
    {
        return sprintf('retainer:%s:tracked:%s:%s', $retainer->id, $asOf->format('Y-m-d'), $projectId ?? 'all');
    }

    private function tag(Retainer $retainer): string
    {
        return 'retainer:'.$retainer->id;
    }
}
```

- [ ] **Step 4: Ensure tests run on tag-capable driver**

The default test cache driver is `array`, which **does not support tags**. Update `phpunit.xml` if needed to set `CACHE_DRIVER=redis` for these tests, OR change the implementation to fall back to a key-prefix scheme when tags aren't supported.

Simpler: configure test env to use Redis. In `phpunit.xml` ensure:
```xml
<env name="CACHE_STORE" value="redis"/>
```
Check existing CI env first — if Redis is already provisioned for tests (likely, since solidtime has Redis as a dependency), this is one-line.

- [ ] **Step 5: Run tests, all pass**

- [ ] **Step 6: Commit**

```bash
git add app/Service/Retainer/RetainerCache.php tests/Unit/Service/Retainer/RetainerCacheTest.php phpunit.xml
git commit -m "local: add RetainerCache (Redis tag-based; 5min TTL; invalidate by retainer)"
```

---

## Task 11: `RetainerCapExceededException`

**Files:**
- Create: `app/Exceptions/Api/RetainerCapExceededException.php`

- [ ] **Step 1: Inspect an existing API exception to match pattern**

Run: `cat app/Exceptions/Api/EntityStillInUseApiException.php` (or any sibling)

Note the base class, HTTP status code, JSON response shape.

- [ ] **Step 2: Implement**

```php
<?php
declare(strict_types=1);

namespace App\Exceptions\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class RetainerCapExceededException extends ApiException
{
    public function __construct(
        public readonly string $retainerId,
        public readonly int $capSeconds,
        public readonly int $currentTrackedSeconds,
        public readonly int $attemptedDeltaSeconds,
    ) {
        parent::__construct('Saving this time entry would exceed the retainer cap.');
    }

    public function render(): JsonResponse
    {
        return new JsonResponse([
            'error' => true,
            'key' => 'retainer_cap_exceeded',
            'message' => $this->getMessage(),
            'retainer_id' => $this->retainerId,
            'cap_seconds' => $this->capSeconds,
            'tracked_seconds' => $this->currentTrackedSeconds,
            'attempted_delta_seconds' => $this->attemptedDeltaSeconds,
        ], SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY);
    }
}
```

**Adjust** if `ApiException` does not exist or has a different signature — match whatever the existing exception you read in step 1 uses.

- [ ] **Step 3: Commit**

```bash
git add app/Exceptions/Api/RetainerCapExceededException.php
git commit -m "local: add RetainerCapExceededException (422)"
```

---

## Task 12: `CapEnforcer` service

**Files:**
- Create: `app/Service/Retainer/CapEnforcer.php`
- Test: `tests/Unit/Service/Retainer/CapEnforcerTest.php`

**Method:** `enforceForEntry(TimeEntry $entry): void` — called from the observer.

Returns void on success; throws `RetainerCapExceededException` if cap would be breached in `block` mode. In v1, `flag` and `approval` modes are no-ops (logged for visibility).

- [ ] **Step 1: Write tests**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Service\Retainer;

use App\Enums\RetainerHardCapEnforcement;
use App\Enums\RetainerHardCapScope;
use App\Exceptions\Api\RetainerCapExceededException;
use App\Models\Client;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Retainer;
use App\Models\TimeEntry;
use App\Models\User;
use App\Service\Retainer\CapEnforcer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CapEnforcerTest extends TestCase
{
    use RefreshDatabase;

    public function test_skips_when_no_active_retainer(): void
    {
        $entry = $this->makeEntry(billable: true);
        // No retainer created
        app(CapEnforcer::class)->enforceForEntry($entry);
        $this->expectNotToPerformAssertions();
    }

    public function test_skips_when_retainer_hard_cap_disabled(): void
    {
        $entry = $this->makeEntry(billable: true);
        Retainer::factory()->create([
            'organization_id' => $entry->organization_id,
            'client_id' => $entry->project->client_id,
            'starts_at' => '2026-06-01',
            'hard_cap_enabled' => false,
        ]);
        app(CapEnforcer::class)->enforceForEntry($entry);
        $this->expectNotToPerformAssertions();
    }

    public function test_blocks_when_per_period_cap_exceeded(): void
    {
        $entry = $this->makeEntry(billable: true, start: '2026-06-15 09:00', end: '2026-06-15 19:00');  // 10h
        // Retainer: 40h/month calendar. Already used 35h before this entry. New entry pushes to 45h → exceed.
        $retainer = Retainer::factory()->withHardCap('per_period', 'block')->create([
            'organization_id' => $entry->organization_id,
            'client_id' => $entry->project->client_id,
            'starts_at' => '2026-06-01',
            'seconds_per_period' => 40 * 3600,
        ]);

        // Pre-existing 35h of entries this month
        TimeEntry::factory()->create([
            'organization_id' => $entry->organization_id,
            'user_id' => $entry->user_id,
            'member_id' => $entry->member_id,
            'project_id' => $entry->project_id,
            'client_id' => $entry->client_id,
            'start' => '2026-06-01 09:00',
            'end' => '2026-06-08 20:00',  // 35h spread; tweak to land on exactly 35h
            'billable' => true,
        ]);

        $this->expectException(RetainerCapExceededException::class);
        app(CapEnforcer::class)->enforceForEntry($entry);
    }

    public function test_allows_when_under_per_period_cap(): void
    {
        $entry = $this->makeEntry(billable: true, start: '2026-06-15 09:00', end: '2026-06-15 11:00'); // 2h
        Retainer::factory()->withHardCap('per_period', 'block')->create([
            'organization_id' => $entry->organization_id,
            'client_id' => $entry->project->client_id,
            'starts_at' => '2026-06-01',
            'seconds_per_period' => 40 * 3600,
        ]);

        app(CapEnforcer::class)->enforceForEntry($entry);
        $this->expectNotToPerformAssertions();
    }

    public function test_blocks_when_cumulative_cap_exceeded(): void
    {
        $entry = $this->makeEntry(billable: true, start: '2026-06-15 09:00', end: '2026-06-15 19:00');  // 10h
        Retainer::factory()->withHardCap('cumulative', 'block')->create([
            'organization_id' => $entry->organization_id,
            'client_id' => $entry->project->client_id,
            'starts_at' => '2026-06-01',
            'hard_cap_cumulative_seconds' => 5 * 3600,  // small cap so the 10h entry exceeds
        ]);

        $this->expectException(RetainerCapExceededException::class);
        app(CapEnforcer::class)->enforceForEntry($entry);
    }

    public function test_flag_enforcement_is_no_op_in_v1(): void
    {
        $entry = $this->makeEntry(billable: true, start: '2026-06-15 09:00', end: '2026-06-15 19:00');
        Retainer::factory()->withHardCap('per_period', 'flag')->create([
            'organization_id' => $entry->organization_id,
            'client_id' => $entry->project->client_id,
            'starts_at' => '2026-06-01',
            'seconds_per_period' => 5 * 3600,  // intentionally tiny so it'd "exceed"
        ]);

        // Should NOT throw, even though the entry exceeds the cap, because flag mode is unwired in v1
        app(CapEnforcer::class)->enforceForEntry($entry);
        $this->expectNotToPerformAssertions();
    }

    private function makeEntry(bool $billable, string $start = '2026-06-15 09:00', string $end = '2026-06-15 11:00'): TimeEntry
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withPersonalOrganization()->create();
        $org->users()->attach($user->id, ['role' => 'admin']);
        $member = Member::factory()->create(['user_id' => $user->id, 'organization_id' => $org->id]);
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $project = Project::factory()->create(['organization_id' => $org->id, 'client_id' => $client->id]);

        return TimeEntry::factory()->make([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'member_id' => $member->id,
            'project_id' => $project->id,
            'client_id' => $client->id,
            'start' => $start,
            'end' => $end,
            'billable' => $billable,
        ])->setRelation('project', $project);
    }
}
```

- [ ] **Step 2: Verify failure**

- [ ] **Step 3: Implement**

```php
<?php
declare(strict_types=1);

namespace App\Service\Retainer;

use App\Enums\RetainerHardCapEnforcement;
use App\Enums\RetainerHardCapScope;
use App\Enums\RetainerSubCapMode;
use App\Exceptions\Api\RetainerCapExceededException;
use App\Models\Retainer;
use App\Models\TimeEntry;
use Illuminate\Support\Carbon;

class CapEnforcer
{
    public function __construct(
        private readonly RetainerLookup $lookup,
        private readonly AllocationCalculator $allocation,
        private readonly ConsumptionQuery $consumption,  // intentionally uncached on write path
    ) {}

    public function enforceForEntry(TimeEntry $entry): void
    {
        if ($entry->end === null || $entry->project === null || $entry->project->client_id === null) {
            return; // running entries or unassigned project — skip
        }

        $retainer = $this->lookup->findActiveForClient(
            $entry->project->client_id,
            Carbon::parse($entry->end),
        );

        if ($retainer === null || ! $retainer->hard_cap_enabled) {
            return;
        }

        if ($retainer->hard_cap_enforcement !== RetainerHardCapEnforcement::Block) {
            return; // flag/approval reserved for v1.1
        }

        $delta = $this->entryDurationDelta($entry);
        if ($delta <= 0) {
            return;
        }

        $this->checkParentCap($retainer, $entry, $delta);

        if ($retainer->sub_cap_mode === RetainerSubCapMode::Strict) {
            $this->checkSubCap($retainer, $entry, $delta);
        }
    }

    private function checkParentCap(Retainer $retainer, TimeEntry $entry, int $delta): void
    {
        $asOf = Carbon::parse($entry->end);
        $currentTracked = $this->consumption->computeTracked($retainer, $asOf);
        $cap = $retainer->hard_cap_scope === RetainerHardCapScope::Cumulative
            ? (int) $retainer->hard_cap_cumulative_seconds
            : $this->allocation->computeAllocated($retainer, $asOf);

        if ($currentTracked + $delta > $cap) {
            throw new RetainerCapExceededException($retainer->id, $cap, $currentTracked, $delta);
        }
    }

    private function checkSubCap(Retainer $retainer, TimeEntry $entry, int $delta): void
    {
        $cap = $retainer->projectCaps()->where('project_id', $entry->project_id)->first();
        if ($cap === null) {
            return;
        }
        $asOf = Carbon::parse($entry->end);
        $currentTracked = $this->consumption->computeTracked($retainer, $asOf, $entry->project_id);
        $capSeconds = $retainer->hard_cap_scope === RetainerHardCapScope::Cumulative
            ? (int) $cap->seconds_cumulative
            : (int) $cap->seconds_per_period;

        if ($currentTracked + $delta > $capSeconds) {
            throw new RetainerCapExceededException($retainer->id, $capSeconds, $currentTracked, $delta);
        }
    }

    private function entryDurationDelta(TimeEntry $entry): int
    {
        $newDuration = Carbon::parse($entry->end)->diffInSeconds(Carbon::parse($entry->start));

        if (! $entry->exists) {
            return $newDuration;
        }

        $origStart = $entry->getOriginal('start');
        $origEnd = $entry->getOriginal('end');
        if ($origStart === null || $origEnd === null) {
            return $newDuration; // was running, now closed → full duration is new
        }
        $oldDuration = Carbon::parse($origEnd)->diffInSeconds(Carbon::parse($origStart));
        return $newDuration - $oldDuration;
    }
}
```

- [ ] **Step 4: Run tests until all pass**

Tweak the 35h-pre-existing-entry test's start/end times if needed to land on exactly 35h.

- [ ] **Step 5: Commit**

```bash
git add app/Service/Retainer/CapEnforcer.php tests/Unit/Service/Retainer/CapEnforcerTest.php
git commit -m "local: add CapEnforcer (block mode; uncached read; per-period/cumulative + strict sub-cap)"
```

---

## Task 13: `TimeEntryRetainerObserver` + registration

**Files:**
- Create: `app/Observers/TimeEntryRetainerObserver.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Unit/Service/Retainer/TimeEntryObserverTest.php`

- [ ] **Step 1: Write test**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Service\Retainer;

use App\Exceptions\Api\RetainerCapExceededException;
use App\Models\Client;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Retainer;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TimeEntryObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_block_mode_prevents_save_of_over_cap_entry(): void
    {
        [$org, $user, $member, $client, $project] = $this->scaffold();

        Retainer::factory()->withHardCap('per_period', 'block')->create([
            'organization_id' => $org->id,
            'client_id' => $client->id,
            'starts_at' => '2026-06-01',
            'seconds_per_period' => 1 * 3600, // tiny: 1h/month
        ]);

        $this->expectException(RetainerCapExceededException::class);
        TimeEntry::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'member_id' => $member->id,
            'project_id' => $project->id,
            'client_id' => $client->id,
            'start' => '2026-06-15 09:00',
            'end' => '2026-06-15 19:00',  // 10h, exceeds 1h cap
            'billable' => true,
        ]);
    }

    public function test_cache_invalidated_after_time_entry_save(): void
    {
        Cache::flush();
        [$org, $user, $member, $client, $project] = $this->scaffold();

        $retainer = Retainer::factory()->create([
            'organization_id' => $org->id,
            'client_id' => $client->id,
            'starts_at' => '2026-06-01',
        ]);

        // Warm the cache
        $cache = app(\App\Service\Retainer\RetainerCache::class);
        $cache->tracked($retainer, \Illuminate\Support\Carbon::parse('2026-06-30 23:59:59'));

        // Save a new entry → should invalidate
        TimeEntry::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'member_id' => $member->id,
            'project_id' => $project->id,
            'client_id' => $client->id,
            'start' => '2026-06-15 09:00',
            'end' => '2026-06-15 11:00',
            'billable' => true,
        ]);

        // Re-read tracked: should now reflect the new entry
        $tracked = $cache->tracked($retainer, \Illuminate\Support\Carbon::parse('2026-06-30 23:59:59'));
        $this->assertSame(2 * 3600, $tracked);
    }

    private function scaffold(): array
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withPersonalOrganization()->create();
        $org->users()->attach($user->id, ['role' => 'admin']);
        $member = Member::factory()->create(['user_id' => $user->id, 'organization_id' => $org->id]);
        $client = Client::factory()->create(['organization_id' => $org->id]);
        $project = Project::factory()->create(['organization_id' => $org->id, 'client_id' => $client->id]);
        return [$org, $user, $member, $client, $project];
    }
}
```

- [ ] **Step 2: Verify failure**

- [ ] **Step 3: Create the observer**

```php
<?php
declare(strict_types=1);

namespace App\Observers;

use App\Models\TimeEntry;
use App\Service\Retainer\CapEnforcer;
use App\Service\Retainer\RetainerCache;
use App\Service\Retainer\RetainerLookup;
use Illuminate\Support\Carbon;

class TimeEntryRetainerObserver
{
    public function __construct(
        private readonly CapEnforcer $enforcer,
        private readonly RetainerLookup $lookup,
        private readonly RetainerCache $cache,
    ) {}

    public function saving(TimeEntry $entry): void
    {
        $this->enforcer->enforceForEntry($entry);
    }

    public function saved(TimeEntry $entry): void
    {
        $this->invalidateFor($entry);
    }

    public function deleted(TimeEntry $entry): void
    {
        $this->invalidateFor($entry);
    }

    private function invalidateFor(TimeEntry $entry): void
    {
        if ($entry->end === null || $entry->project === null || $entry->project->client_id === null) {
            return;
        }
        $retainer = $this->lookup->findActiveForClient(
            $entry->project->client_id,
            Carbon::parse($entry->end),
        );
        if ($retainer !== null) {
            $this->cache->invalidate($retainer);
        }
    }
}
```

- [ ] **Step 4: Register the observer in `AppServiceProvider`**

Modify `app/Providers/AppServiceProvider.php`. In `boot()` add:

```php
use App\Models\TimeEntry;
use App\Observers\TimeEntryRetainerObserver;

// ...

public function boot(): void
{
    // ... existing boot code
    TimeEntry::observe(TimeEntryRetainerObserver::class);
}
```

- [ ] **Step 5: Run tests, all pass**

- [ ] **Step 6: Commit**

```bash
git add app/Observers/TimeEntryRetainerObserver.php app/Providers/AppServiceProvider.php tests/Unit/Service/Retainer/TimeEntryObserverTest.php
git commit -m "local: wire TimeEntry observer (enforce cap on saving; invalidate cache on saved/deleted)"
```

---

## Task 14: Form Requests + validation rules

**Files:**
- Create: `app/Http/Requests/V1/Retainer/RetainerStoreRequest.php`
- Create: `app/Http/Requests/V1/Retainer/RetainerUpdateRequest.php`
- Create: `app/Http/Requests/V1/Retainer/RetainerProjectCapStoreRequest.php`
- Create: `app/Http/Requests/V1/Retainer/RetainerProjectCapUpdateRequest.php`
- Create: `app/Http/Requests/V1/Retainer/RetainerPeriodsReplaceRequest.php`

- [ ] **Step 1: Inspect an existing form request to match conventions**

Run: `cat app/Http/Requests/V1/Client/ClientStoreRequest.php`

Note: namespace, base class, `rules()` shape, how related model IDs are validated, how enums are validated.

- [ ] **Step 2: Create `RetainerStoreRequest`**

```php
<?php
declare(strict_types=1);

namespace App\Http\Requests\V1\Retainer;

use App\Enums\RetainerHardCapEnforcement;
use App\Enums\RetainerHardCapScope;
use App\Enums\RetainerPeriodMode;
use App\Enums\RetainerPeriodUnit;
use App\Enums\RetainerSubCapMode;
use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RetainerStoreRequest extends FormRequest
{
    public function rules(): array
    {
        /** @var Organization $organization */
        $organization = $this->route('organization');

        return [
            'client_id' => [
                'required',
                'string',
                Rule::exists('clients', 'id')->where('organization_id', $organization->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],

            'period_mode' => ['required', Rule::enum(RetainerPeriodMode::class)],
            'period_unit' => ['nullable', Rule::enum(RetainerPeriodUnit::class), 'required_unless:period_mode,explicit'],
            'seconds_per_period' => ['nullable', 'integer', 'min:0', 'required_unless:period_mode,explicit'],
            'anchor_date' => ['nullable', 'date', 'required_if:period_mode,anchor'],

            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],

            'billable_only' => ['boolean'],

            'hard_cap_enabled' => ['boolean'],
            'hard_cap_scope' => ['nullable', Rule::enum(RetainerHardCapScope::class), 'required_if:hard_cap_enabled,true'],
            'hard_cap_enforcement' => ['nullable', Rule::enum(RetainerHardCapEnforcement::class), 'required_if:hard_cap_enabled,true'],
            'hard_cap_cumulative_seconds' => ['nullable', 'integer', 'min:0', 'required_if:hard_cap_scope,cumulative'],

            'sub_cap_mode' => ['nullable', Rule::enum(RetainerSubCapMode::class)],
        ];
    }
}
```

- [ ] **Step 3: Create `RetainerUpdateRequest`**

Same rule set but `client_id` is forbidden (immutable post-create) and all top-level required fields become `sometimes`:

```php
<?php
declare(strict_types=1);

namespace App\Http\Requests\V1\Retainer;

use App\Enums\RetainerHardCapEnforcement;
use App\Enums\RetainerHardCapScope;
use App\Enums\RetainerPeriodMode;
use App\Enums\RetainerPeriodUnit;
use App\Enums\RetainerSubCapMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RetainerUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],

            'period_mode' => ['sometimes', Rule::enum(RetainerPeriodMode::class)],
            'period_unit' => ['sometimes', 'nullable', Rule::enum(RetainerPeriodUnit::class)],
            'seconds_per_period' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'anchor_date' => ['sometimes', 'nullable', 'date'],

            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],

            'billable_only' => ['sometimes', 'boolean'],
            'hard_cap_enabled' => ['sometimes', 'boolean'],
            'hard_cap_scope' => ['sometimes', 'nullable', Rule::enum(RetainerHardCapScope::class)],
            'hard_cap_enforcement' => ['sometimes', 'nullable', Rule::enum(RetainerHardCapEnforcement::class)],
            'hard_cap_cumulative_seconds' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'sub_cap_mode' => ['sometimes', Rule::enum(RetainerSubCapMode::class)],
        ];
    }
}
```

- [ ] **Step 4: Create `RetainerProjectCapStoreRequest`**

```php
<?php
declare(strict_types=1);

namespace App\Http\Requests\V1\Retainer;

use App\Models\Retainer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RetainerProjectCapStoreRequest extends FormRequest
{
    public function rules(): array
    {
        /** @var Retainer $retainer */
        $retainer = $this->route('retainer');

        return [
            'project_id' => [
                'required',
                'string',
                Rule::exists('projects', 'id')->where('client_id', $retainer->client_id),
                Rule::unique('retainer_project_caps', 'project_id')->where('retainer_id', $retainer->id),
            ],
            'seconds_per_period' => ['nullable', 'integer', 'min:0'],
            'seconds_cumulative' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
```

- [ ] **Step 5: Create `RetainerProjectCapUpdateRequest`**

```php
<?php
declare(strict_types=1);

namespace App\Http\Requests\V1\Retainer;

use Illuminate\Foundation\Http\FormRequest;

class RetainerProjectCapUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'seconds_per_period' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'seconds_cumulative' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }
}
```

- [ ] **Step 6: Create `RetainerPeriodsReplaceRequest`**

```php
<?php
declare(strict_types=1);

namespace App\Http\Requests\V1\Retainer;

use Illuminate\Foundation\Http\FormRequest;

class RetainerPeriodsReplaceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'periods' => ['required', 'array', 'min:1'],
            'periods.*.starts_at' => ['required', 'date'],
            'periods.*.ends_at' => ['required', 'date', 'after_or_equal:periods.*.starts_at'],
            'periods.*.seconds_allocated' => ['required', 'integer', 'min:1'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $periods = collect($this->input('periods', []))
                ->sortBy('starts_at')->values()->all();
            for ($i = 1; $i < count($periods); $i++) {
                if ($periods[$i]['starts_at'] <= $periods[$i - 1]['ends_at']) {
                    $v->errors()->add('periods', 'Periods must not overlap.');
                    return;
                }
            }
        });
    }
}
```

- [ ] **Step 7: Commit**

```bash
git add app/Http/Requests/V1/Retainer/
git commit -m "local: add retainer form requests (store, update, sub-cap, periods-replace)"
```

---

## Task 15: API Resources (response transformers)

**Files:**
- Create: `app/Http/Resources/V1/Retainer/RetainerResource.php`
- Create: `app/Http/Resources/V1/Retainer/RetainerCollection.php`
- Create: `app/Http/Resources/V1/Retainer/RetainerStatusResource.php`
- Create: `app/Http/Resources/V1/Retainer/RetainerProjectCapResource.php`
- Create: `app/Http/Resources/V1/Retainer/RetainerPeriodResource.php`

- [ ] **Step 1: Inspect existing resource for shape**

Run: `cat app/Http/Resources/V1/Client/ClientResource.php`

- [ ] **Step 2: `RetainerResource`**

```php
<?php
declare(strict_types=1);

namespace App\Http\Resources\V1\Retainer;

use App\Models\Retainer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Retainer $resource
 */
class RetainerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'organization_id' => $this->resource->organization_id,
            'client_id' => $this->resource->client_id,
            'name' => $this->resource->name,
            'description' => $this->resource->description,
            'period_mode' => $this->resource->period_mode->value,
            'period_unit' => $this->resource->period_unit?->value,
            'seconds_per_period' => $this->resource->seconds_per_period,
            'anchor_date' => $this->resource->anchor_date?->toDateString(),
            'starts_at' => $this->resource->starts_at->toDateString(),
            'ends_at' => $this->resource->ends_at?->toDateString(),
            'billable_only' => $this->resource->billable_only,
            'hard_cap_enabled' => $this->resource->hard_cap_enabled,
            'hard_cap_scope' => $this->resource->hard_cap_scope?->value,
            'hard_cap_enforcement' => $this->resource->hard_cap_enforcement?->value,
            'hard_cap_cumulative_seconds' => $this->resource->hard_cap_cumulative_seconds,
            'sub_cap_mode' => $this->resource->sub_cap_mode->value,
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 3: `RetainerCollection`**

```php
<?php
declare(strict_types=1);

namespace App\Http\Resources\V1\Retainer;

use Illuminate\Http\Resources\Json\ResourceCollection;

class RetainerCollection extends ResourceCollection
{
    public $collects = RetainerResource::class;
}
```

- [ ] **Step 4: `RetainerStatusResource`**

```php
<?php
declare(strict_types=1);

namespace App\Http\Resources\V1\Retainer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RetainerStatusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // $this->resource is an associative array built by the controller
        return $this->resource;
    }
}
```

- [ ] **Step 5: `RetainerProjectCapResource`**

```php
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
```

- [ ] **Step 6: `RetainerPeriodResource`**

```php
<?php
declare(strict_types=1);

namespace App\Http\Resources\V1\Retainer;

use App\Models\RetainerPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property RetainerPeriod $resource
 */
class RetainerPeriodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'retainer_id' => $this->resource->retainer_id,
            'starts_at' => $this->resource->starts_at->toDateString(),
            'ends_at' => $this->resource->ends_at->toDateString(),
            'seconds_allocated' => $this->resource->seconds_allocated,
        ];
    }
}
```

- [ ] **Step 7: Commit**

```bash
git add app/Http/Resources/V1/Retainer/
git commit -m "local: add retainer API resources"
```

---

## Task 16: `RetainerController` + routes

**Files:**
- Create: `app/Http/Controllers/Api/V1/RetainerController.php`
- Modify: `routes/api.php`
- Test: `tests/Unit/Endpoint/Api/V1/RetainerEndpointTest.php`

- [ ] **Step 1: Inspect an existing controller + endpoint test for the shape**

Run: `cat app/Http/Controllers/Api/V1/ClientController.php tests/Unit/Endpoint/Api/V1/ClientEndpointTest.php`

Note: `index/store/show/update/destroy` signatures, `checkPermission` calls, how the org-belongs-to-resource check is performed, `actingAs` setup in tests.

- [ ] **Step 2: Add the routes**

In `routes/api.php`, inside the V1 group with the same middleware as other org-scoped routes, add:

```php
Route::name('retainers.')->prefix('/organizations/{organization}')->group(static function (): void {
    Route::get('/retainers', [RetainerController::class, 'index'])->name('index');
    Route::post('/retainers', [RetainerController::class, 'store'])->name('store')->middleware('check-organization-blocked');
    Route::get('/retainers/{retainer}', [RetainerController::class, 'show'])->name('show');
    Route::put('/retainers/{retainer}', [RetainerController::class, 'update'])->name('update')->middleware('check-organization-blocked');
    Route::delete('/retainers/{retainer}', [RetainerController::class, 'destroy'])->name('destroy')->middleware('check-organization-blocked');

    Route::get('/retainers/{retainer}/status', [RetainerController::class, 'status'])->name('status');
    Route::get('/retainers/{retainer}/periods', [RetainerController::class, 'periods'])->name('periods');

    Route::get('/clients/{client}/retainers', [RetainerController::class, 'forClient'])->name('for-client');
});
```

Add the `use App\Http\Controllers\Api\V1\RetainerController;` import at the top.

- [ ] **Step 3: Write tests for index/store/show/update/destroy/status**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Endpoint\Api\V1;

use App\Models\Client;
use App\Models\Retainer;

class RetainerEndpointTest extends ApiEndpointTestAbstract
{
    public function test_index_returns_retainers_for_organization(): void
    {
        $data = $this->createUserWithPermission(['retainers:view']);
        $client = Client::factory()->create(['organization_id' => $data->organization->id]);
        Retainer::factory()->count(2)->create([
            'organization_id' => $data->organization->id,
            'client_id' => $client->id,
        ]);

        $response = $this->actingAs($data->user)->getJson(route('api.v1.retainers.index', ['organization' => $data->organization->id]));

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_index_forbidden_without_permission(): void
    {
        $data = $this->createUserWithPermission([]); // no retainers:view
        $response = $this->actingAs($data->user)->getJson(route('api.v1.retainers.index', ['organization' => $data->organization->id]));
        $response->assertForbidden();
    }

    public function test_store_creates_retainer_with_minimum_fields(): void
    {
        $data = $this->createUserWithPermission(['retainers:create']);
        $client = Client::factory()->create(['organization_id' => $data->organization->id]);

        $payload = [
            'client_id' => $client->id,
            'name' => 'Acme Monthly',
            'period_mode' => 'calendar',
            'period_unit' => 'monthly',
            'seconds_per_period' => 40 * 3600,
            'starts_at' => '2026-06-01',
        ];

        $response = $this->actingAs($data->user)->postJson(route('api.v1.retainers.store', ['organization' => $data->organization->id]), $payload);

        $response->assertCreated()
            ->assertJsonPath('data.client_id', $client->id)
            ->assertJsonPath('data.name', 'Acme Monthly');
        $this->assertDatabaseCount('retainers', 1);
    }

    public function test_store_rejects_when_client_belongs_to_other_org(): void
    {
        $data = $this->createUserWithPermission(['retainers:create']);
        $otherOrgClient = Client::factory()->create();
        $response = $this->actingAs($data->user)->postJson(
            route('api.v1.retainers.store', ['organization' => $data->organization->id]),
            ['client_id' => $otherOrgClient->id, 'name' => 'X', 'period_mode' => 'calendar', 'period_unit' => 'monthly', 'seconds_per_period' => 100, 'starts_at' => '2026-06-01'],
        );
        $response->assertUnprocessable();
    }

    public function test_show_returns_retainer(): void
    {
        $data = $this->createUserWithPermission(['retainers:view']);
        $client = Client::factory()->create(['organization_id' => $data->organization->id]);
        $retainer = Retainer::factory()->create(['organization_id' => $data->organization->id, 'client_id' => $client->id]);

        $response = $this->actingAs($data->user)->getJson(route('api.v1.retainers.show', ['organization' => $data->organization->id, 'retainer' => $retainer->id]));

        $response->assertOk()->assertJsonPath('data.id', $retainer->id);
    }

    public function test_update_changes_name(): void
    {
        $data = $this->createUserWithPermission(['retainers:update']);
        $client = Client::factory()->create(['organization_id' => $data->organization->id]);
        $retainer = Retainer::factory()->create(['organization_id' => $data->organization->id, 'client_id' => $client->id]);

        $response = $this->actingAs($data->user)->putJson(
            route('api.v1.retainers.update', ['organization' => $data->organization->id, 'retainer' => $retainer->id]),
            ['name' => 'New name'],
        );

        $response->assertOk()->assertJsonPath('data.name', 'New name');
    }

    public function test_destroy_soft_deletes(): void
    {
        $data = $this->createUserWithPermission(['retainers:delete']);
        $client = Client::factory()->create(['organization_id' => $data->organization->id]);
        $retainer = Retainer::factory()->create(['organization_id' => $data->organization->id, 'client_id' => $client->id]);

        $response = $this->actingAs($data->user)->deleteJson(route('api.v1.retainers.destroy', ['organization' => $data->organization->id, 'retainer' => $retainer->id]));

        $response->assertNoContent();
        $this->assertSoftDeleted('retainers', ['id' => $retainer->id]);
    }

    public function test_status_returns_allocated_tracked_delta(): void
    {
        $data = $this->createUserWithPermission(['retainers:view']);
        $client = Client::factory()->create(['organization_id' => $data->organization->id]);
        $retainer = Retainer::factory()->create([
            'organization_id' => $data->organization->id,
            'client_id' => $client->id,
            'starts_at' => '2026-06-01',
            'seconds_per_period' => 40 * 3600,
        ]);

        $response = $this->actingAs($data->user)->getJson(route('api.v1.retainers.status', [
            'organization' => $data->organization->id, 'retainer' => $retainer->id,
        ]).'?as_of=2026-06-30');

        $response->assertOk()
            ->assertJsonStructure(['data' => ['as_of', 'allocated_seconds', 'tracked_seconds', 'delta_seconds', 'percent']]);
    }

    public function test_overlapping_retainer_for_same_client_rejected(): void
    {
        $data = $this->createUserWithPermission(['retainers:create']);
        $client = Client::factory()->create(['organization_id' => $data->organization->id]);
        Retainer::factory()->create([
            'organization_id' => $data->organization->id,
            'client_id' => $client->id,
            'starts_at' => '2026-06-01',
            'ends_at' => '2026-12-31',
        ]);

        $response = $this->actingAs($data->user)->postJson(
            route('api.v1.retainers.store', ['organization' => $data->organization->id]),
            [
                'client_id' => $client->id, 'name' => 'Overlap', 'period_mode' => 'calendar',
                'period_unit' => 'monthly', 'seconds_per_period' => 100, 'starts_at' => '2026-08-01',
            ],
        );

        $response->assertUnprocessable()->assertJsonValidationErrors(['starts_at']);
    }
}
```

The `createUserWithPermission` helper may need to be added to `ApiEndpointTestAbstract` if not present — check first; if absent, use the existing fixtures that the sibling tests use.

- [ ] **Step 4: Implement controller**

```php
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
    protected function checkPermission(Organization $organization, string $permission, ?Retainer $retainer = null): void
    {
        parent::checkPermission($organization, $permission);
        if ($retainer !== null && $retainer->organization_id !== $organization->getKey()) {
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
        $this->assertNoOverlap($organization, $request->input('client_id'), $request->input('starts_at'), $request->input('ends_at'));

        $retainer = new Retainer($request->validated());
        $retainer->organization_id = $organization->id;
        $retainer->save();

        return new RetainerResource($retainer);
    }

    public function show(Organization $organization, Retainer $retainer): RetainerResource
    {
        $this->checkPermission($organization, 'retainers:view', $retainer);
        return new RetainerResource($retainer);
    }

    public function update(Organization $organization, Retainer $retainer, RetainerUpdateRequest $request): RetainerResource
    {
        $this->checkPermission($organization, 'retainers:update', $retainer);
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
        $this->checkPermission($organization, 'retainers:delete', $retainer);
        $retainer->delete();
        return new JsonResponse(null, 204);
    }

    public function status(Organization $organization, Retainer $retainer, Request $request, AllocationCalculator $calculator, RetainerCache $cache): RetainerStatusResource
    {
        $this->checkPermission($organization, 'retainers:view', $retainer);
        $asOf = $request->query('as_of') ? Carbon::parse($request->query('as_of'))->endOfDay() : Carbon::now();

        $allocated = $calculator->computeAllocated($retainer, $asOf);
        $tracked = $cache->tracked($retainer, $asOf);
        $delta = $tracked - $allocated;
        $percent = $allocated > 0 ? round($tracked / $allocated, 4) : 0.0;

        return new RetainerStatusResource([
            'data' => [
                'as_of' => $asOf->toDateString(),
                'allocated_seconds' => $allocated,
                'tracked_seconds' => $tracked,
                'delta_seconds' => $delta,
                'percent' => $percent,
                'hard_cap_enabled' => $retainer->hard_cap_enabled,
                'hard_cap_scope' => $retainer->hard_cap_scope?->value,
            ],
        ]);
    }

    public function periods(Organization $organization, Retainer $retainer): JsonResponse
    {
        $this->checkPermission($organization, 'retainers:view', $retainer);
        // Returns per-period breakdown for charting; for recurring modes this is computed.
        // v1: simple "list of {starts_at, ends_at, seconds_allocated, seconds_tracked}" for the last 12 months.
        // Implementation deferred until subsystem #2 needs it; stub returns empty array.
        return new JsonResponse(['data' => []]);
    }

    public function forClient(Organization $organization, Client $client): RetainerCollection
    {
        $this->checkPermission($organization, 'retainers:view');
        return new RetainerCollection(
            Retainer::query()->where('client_id', $client->id)->get()
        );
    }

    private function assertNoOverlap(Organization $organization, string $clientId, string $startsAt, ?string $endsAt, ?string $exceptId = null): void
    {
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
            if (($end === null || $end->gte($oStart)) && ($oEnd === null || $start->lte($oEnd))) {
                throw ValidationException::withMessages(['starts_at' => 'Overlaps with another retainer for this client.']);
            }
        }
    }
}
```

- [ ] **Step 5: Run tests**

Run: `./vendor/bin/phpunit tests/Unit/Endpoint/Api/V1/RetainerEndpointTest.php`
Expected: all tests pass.

If `createUserWithPermission` doesn't exist, replicate the equivalent setup that `ClientEndpointTest.php` uses.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/V1/RetainerController.php routes/api.php tests/Unit/Endpoint/Api/V1/RetainerEndpointTest.php
git commit -m "local: add RetainerController (CRUD + status endpoint) with overlap validation"
```

---

## Task 17: `RetainerProjectCapController` + routes

**Files:**
- Create: `app/Http/Controllers/Api/V1/RetainerProjectCapController.php`
- Modify: `routes/api.php`
- Test: `tests/Unit/Endpoint/Api/V1/RetainerProjectCapEndpointTest.php`

- [ ] **Step 1: Add routes**

In `routes/api.php` inside the retainers group:

```php
Route::get('/retainers/{retainer}/project-caps', [RetainerProjectCapController::class, 'index'])->name('project-caps.index');
Route::post('/retainers/{retainer}/project-caps', [RetainerProjectCapController::class, 'store'])->name('project-caps.store')->middleware('check-organization-blocked');
Route::put('/retainers/{retainer}/project-caps/{cap}', [RetainerProjectCapController::class, 'update'])->name('project-caps.update')->middleware('check-organization-blocked');
Route::delete('/retainers/{retainer}/project-caps/{cap}', [RetainerProjectCapController::class, 'destroy'])->name('project-caps.destroy')->middleware('check-organization-blocked');
```

- [ ] **Step 2: Write tests**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Endpoint\Api\V1;

use App\Models\Client;
use App\Models\Project;
use App\Models\Retainer;
use App\Models\RetainerProjectCap;

class RetainerProjectCapEndpointTest extends ApiEndpointTestAbstract
{
    public function test_index_returns_caps(): void
    {
        $data = $this->createUserWithPermission(['retainers:view']);
        $client = Client::factory()->create(['organization_id' => $data->organization->id]);
        $retainer = Retainer::factory()->create(['organization_id' => $data->organization->id, 'client_id' => $client->id]);
        $project = Project::factory()->create(['organization_id' => $data->organization->id, 'client_id' => $client->id]);
        RetainerProjectCap::factory()->create(['retainer_id' => $retainer->id, 'project_id' => $project->id]);

        $response = $this->actingAs($data->user)->getJson(
            route('api.v1.retainers.project-caps.index', ['organization' => $data->organization->id, 'retainer' => $retainer->id])
        );
        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_store_creates_cap(): void
    {
        $data = $this->createUserWithPermission(['retainers:update']);
        $client = Client::factory()->create(['organization_id' => $data->organization->id]);
        $retainer = Retainer::factory()->create(['organization_id' => $data->organization->id, 'client_id' => $client->id]);
        $project = Project::factory()->create(['organization_id' => $data->organization->id, 'client_id' => $client->id]);

        $response = $this->actingAs($data->user)->postJson(
            route('api.v1.retainers.project-caps.store', ['organization' => $data->organization->id, 'retainer' => $retainer->id]),
            ['project_id' => $project->id, 'seconds_per_period' => 20 * 3600],
        );
        $response->assertCreated();
    }

    public function test_store_rejects_project_from_other_client(): void
    {
        $data = $this->createUserWithPermission(['retainers:update']);
        $clientA = Client::factory()->create(['organization_id' => $data->organization->id]);
        $clientB = Client::factory()->create(['organization_id' => $data->organization->id]);
        $retainer = Retainer::factory()->create(['organization_id' => $data->organization->id, 'client_id' => $clientA->id]);
        $foreignProject = Project::factory()->create(['organization_id' => $data->organization->id, 'client_id' => $clientB->id]);

        $response = $this->actingAs($data->user)->postJson(
            route('api.v1.retainers.project-caps.store', ['organization' => $data->organization->id, 'retainer' => $retainer->id]),
            ['project_id' => $foreignProject->id, 'seconds_per_period' => 100],
        );
        $response->assertUnprocessable();
    }
}
```

- [ ] **Step 3: Verify failure**

- [ ] **Step 4: Implement controller**

```php
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
```

- [ ] **Step 5: Run tests, all pass**

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/V1/RetainerProjectCapController.php routes/api.php tests/Unit/Endpoint/Api/V1/RetainerProjectCapEndpointTest.php
git commit -m "local: add RetainerProjectCapController (nested CRUD)"
```

---

## Task 18: `RetainerPeriodController` (explicit-mode period replacement)

**Files:**
- Create: `app/Http/Controllers/Api/V1/RetainerPeriodController.php`
- Modify: `routes/api.php`
- Test: `tests/Unit/Endpoint/Api/V1/RetainerPeriodEndpointTest.php`

Single PUT endpoint that replaces the entire `retainer_periods` set for an explicit-mode retainer.

- [ ] **Step 1: Add route**

```php
Route::put('/retainers/{retainer}/periods', [RetainerPeriodController::class, 'replace'])->name('periods.replace')->middleware('check-organization-blocked');
```

- [ ] **Step 2: Write tests**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Endpoint\Api\V1;

use App\Enums\RetainerPeriodMode;
use App\Models\Client;
use App\Models\Retainer;

class RetainerPeriodEndpointTest extends ApiEndpointTestAbstract
{
    public function test_replace_sets_periods(): void
    {
        $data = $this->createUserWithPermission(['retainers:update']);
        $client = Client::factory()->create(['organization_id' => $data->organization->id]);
        $retainer = Retainer::factory()->create([
            'organization_id' => $data->organization->id,
            'client_id' => $client->id,
            'period_mode' => RetainerPeriodMode::Explicit,
            'period_unit' => null,
            'seconds_per_period' => null,
        ]);

        $response = $this->actingAs($data->user)->putJson(
            route('api.v1.retainers.periods.replace', ['organization' => $data->organization->id, 'retainer' => $retainer->id]),
            ['periods' => [
                ['starts_at' => '2026-01-01', 'ends_at' => '2026-01-31', 'seconds_allocated' => 144000],
                ['starts_at' => '2026-02-01', 'ends_at' => '2026-02-28', 'seconds_allocated' => 216000],
            ]],
        );
        $response->assertOk();
        $this->assertSame(2, $retainer->periods()->count());
    }

    public function test_replace_rejects_overlapping_periods(): void
    {
        $data = $this->createUserWithPermission(['retainers:update']);
        $client = Client::factory()->create(['organization_id' => $data->organization->id]);
        $retainer = Retainer::factory()->create([
            'organization_id' => $data->organization->id,
            'client_id' => $client->id,
            'period_mode' => RetainerPeriodMode::Explicit,
        ]);

        $response = $this->actingAs($data->user)->putJson(
            route('api.v1.retainers.periods.replace', ['organization' => $data->organization->id, 'retainer' => $retainer->id]),
            ['periods' => [
                ['starts_at' => '2026-01-01', 'ends_at' => '2026-02-15', 'seconds_allocated' => 100],
                ['starts_at' => '2026-02-01', 'ends_at' => '2026-02-28', 'seconds_allocated' => 100],
            ]],
        );
        $response->assertUnprocessable();
    }
}
```

- [ ] **Step 3: Verify failure**

- [ ] **Step 4: Implement controller**

```php
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
```

- [ ] **Step 5: Run tests, all pass**

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/V1/RetainerPeriodController.php routes/api.php tests/Unit/Endpoint/Api/V1/RetainerPeriodEndpointTest.php
git commit -m "local: add RetainerPeriodController (PUT replaces periods, transactional)"
```

---

## Task 19: Frontend — inspect existing Vue/Inertia patterns

**Files:**
- Read-only inspection step (no commit)

Before building the UI, the engineer needs to know the existing patterns. This task documents what to look at; no code changes.

- [ ] **Step 1: Find the client detail page**

Run: `find resources/js -type f -name "*.vue" | xargs grep -l -i "client" 2>/dev/null | head -10`

Identify the page that renders a single client. Note its layout, how tabs (if any) are structured, and how it loads data via Inertia.

- [ ] **Step 2: Find an existing form component to mirror**

Run: `find resources/js -type f -name "*Form.vue" -o -name "*Modal.vue" | head -10`

Pick the closest match (probably a project or client form) and read it. Note: field components used (TextInput, NumberInput, Toggle), validation error display, submit-button pattern, how Inertia's `useForm` is used.

- [ ] **Step 3: Find the time entry form for the badge placement**

Run: `find resources/js -type f -name "*TimeEntry*.vue" | head -10`

Identify the file where the project picker resolves to a `project_id`. That's where the retainer badge gets mounted.

- [ ] **Step 4: Document findings in a scratch note**

Create `docs/superpowers/notes/retainer-ui-patterns.md` (or leave inline TODOs in the Vue files in Task 20+). This is read-only context for the implementer; the actual implementation tasks reference this note.

Example (replace with actual findings):

```
- Client detail page: resources/js/Pages/Clients/Show.vue (uses <TabGroup> from @headlessui/vue for sectioning)
- Form pattern: resources/js/Components/Project/ProjectForm.vue uses Inertia useForm + custom <TextInput>, <Toggle>, <NumberInput> components from resources/js/Components/Common
- TimeEntry project picker: resources/js/Components/TimeTracker/ProjectSelector.vue emits selected project_id; badge mount point will be in resources/js/Components/TimeTracker/TimeEntryForm.vue right below the project row
- API client: resources/js/packages/api/src/index.ts uses openapi-typescript-fetch — schema regenerated from openapi.json via `npm run openapi:generate`
```

- [ ] **Step 5: Add retainers to the openapi schema (so the frontend types are generated)**

Solidtime auto-generates `openapi.json` via Scribe or similar. Run the same generation command the repo uses (check `package.json` or `composer.json` scripts).

Likely: `php artisan scribe:generate && npm run openapi:generate` — confirm by looking at `package.json` scripts.

- [ ] **Step 6: Commit the docs note + regenerated openapi (no Vue changes yet)**

```bash
git add docs/superpowers/notes/retainer-ui-patterns.md openapi.json resources/js/packages/api/src/schema.d.ts
git commit -m "local: regenerate openapi + ui-pattern notes for retainer UI"
```

---

## Task 20: Frontend — `RetainerStatusCard.vue`

**Files:**
- Create: `resources/js/Components/Retainer/RetainerStatusCard.vue`

Displays a single retainer's current status: name, period unit, progress bar (tracked / allocated), delta, percent.

- [ ] **Step 1: Implement component**

```vue
<script setup lang="ts">
import { computed } from 'vue';

interface Props {
  retainer: {
    id: string;
    name: string;
    period_unit: string | null;
    seconds_per_period: number | null;
  };
  status: {
    allocated_seconds: number;
    tracked_seconds: number;
    delta_seconds: number;
    percent: number;
    hard_cap_enabled: boolean;
  };
}

const props = defineProps<Props>();

const toHours = (sec: number) => (sec / 3600).toFixed(1);

const barWidth = computed(() => {
  const pct = props.status.percent * 100;
  return Math.min(100, pct).toFixed(1) + '%';
});

const barColor = computed(() => {
  const pct = props.status.percent;
  if (pct >= 1.0) return 'bg-red-500';
  if (pct >= 0.9) return 'bg-amber-500';
  return 'bg-emerald-500';
});
</script>

<template>
  <div class="rounded-lg border border-default p-4 bg-card">
    <div class="flex items-baseline justify-between">
      <h3 class="font-medium text-card-foreground">{{ retainer.name }}</h3>
      <span class="text-sm text-muted-foreground">{{ retainer.period_unit }}</span>
    </div>

    <div class="mt-3 flex items-baseline justify-between text-sm">
      <span class="text-card-foreground">
        {{ toHours(status.tracked_seconds) }}h / {{ toHours(status.allocated_seconds) }}h
      </span>
      <span class="text-muted-foreground">
        {{ (status.percent * 100).toFixed(0) }}%
      </span>
    </div>

    <div class="mt-2 h-2 w-full rounded-full bg-muted overflow-hidden">
      <div :class="['h-full transition-all', barColor]" :style="{ width: barWidth }" />
    </div>

    <div class="mt-2 text-xs text-muted-foreground">
      <span v-if="status.delta_seconds < 0">{{ toHours(-status.delta_seconds) }}h under plan</span>
      <span v-else-if="status.delta_seconds > 0">{{ toHours(status.delta_seconds) }}h over plan</span>
      <span v-else>On plan</span>
      <span v-if="status.hard_cap_enabled" class="ml-2 inline-flex items-center px-1.5 py-0.5 rounded text-[10px] bg-amber-100 text-amber-800">
        Hard cap
      </span>
    </div>
  </div>
</template>
```

**Note:** The actual Tailwind utility class names (`text-card-foreground`, `bg-muted`, etc.) must match what solidtime uses. After reading the patterns note from Task 19, replace these with the project's actual tokens (likely `text-text-primary`, `bg-secondary-bg`, etc. — verify against an existing card component).

- [ ] **Step 2: Commit**

```bash
git add resources/js/Components/Retainer/RetainerStatusCard.vue
git commit -m "local: add RetainerStatusCard Vue component"
```

---

## Task 21: Frontend — `RetainerForm.vue`

**Files:**
- Create: `resources/js/Components/Retainer/RetainerForm.vue`

Opinionated form with **default fields** visible (name, period_unit, seconds_per_period, starts_at) and an **Advanced** collapsible section for everything else.

- [ ] **Step 1: Implement component**

```vue
<script setup lang="ts">
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';

interface RetainerInput {
  id?: string;
  client_id: string;
  name: string;
  description: string | null;
  period_mode: 'calendar' | 'anchor' | 'explicit';
  period_unit: 'weekly' | 'monthly' | 'quarterly' | null;
  seconds_per_period: number | null;
  anchor_date: string | null;
  starts_at: string;
  ends_at: string | null;
  billable_only: boolean;
  hard_cap_enabled: boolean;
  hard_cap_scope: 'per_period' | 'cumulative' | null;
  hard_cap_enforcement: 'block' | 'flag' | 'approval' | null;
  hard_cap_cumulative_seconds: number | null;
  sub_cap_mode: 'soft' | 'strict';
}

const props = defineProps<{
  organizationId: string;
  clientId: string;
  initial?: Partial<RetainerInput>;
}>();

const emit = defineEmits<{ saved: [retainer: any]; cancel: [] }>();

const hoursPerPeriod = ref<number>(
  props.initial?.seconds_per_period ? Math.round(props.initial.seconds_per_period / 3600) : 40
);

const form = ref<RetainerInput>({
  client_id: props.clientId,
  name: props.initial?.name ?? '',
  description: props.initial?.description ?? null,
  period_mode: props.initial?.period_mode ?? 'calendar',
  period_unit: props.initial?.period_unit ?? 'monthly',
  seconds_per_period: hoursPerPeriod.value * 3600,
  anchor_date: props.initial?.anchor_date ?? null,
  starts_at: props.initial?.starts_at ?? new Date().toISOString().slice(0, 10),
  ends_at: props.initial?.ends_at ?? null,
  billable_only: props.initial?.billable_only ?? true,
  hard_cap_enabled: props.initial?.hard_cap_enabled ?? false,
  hard_cap_scope: props.initial?.hard_cap_scope ?? null,
  hard_cap_enforcement: props.initial?.hard_cap_enforcement ?? null,
  hard_cap_cumulative_seconds: props.initial?.hard_cap_cumulative_seconds ?? null,
  sub_cap_mode: props.initial?.sub_cap_mode ?? 'soft',
});

const showAdvanced = ref(false);
const submitting = ref(false);
const errors = ref<Record<string, string>>({});

const isUpdate = computed(() => !!props.initial?.id);

const submit = async () => {
  submitting.value = true;
  errors.value = {};
  form.value.seconds_per_period = hoursPerPeriod.value * 3600;

  const url = isUpdate.value
    ? `/api/v1/organizations/${props.organizationId}/retainers/${props.initial!.id}`
    : `/api/v1/organizations/${props.organizationId}/retainers`;
  const method = isUpdate.value ? 'PUT' : 'POST';

  try {
    const res = await fetch(url, {
      method,
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(form.value),
    });
    if (!res.ok) {
      const body = await res.json();
      errors.value = body.errors ?? { _: body.message ?? 'Failed' };
      return;
    }
    emit('saved', (await res.json()).data);
  } finally {
    submitting.value = false;
  }
};
</script>

<template>
  <form @submit.prevent="submit" class="space-y-4">
    <!-- Default visible fields -->
    <div>
      <label class="block text-sm font-medium">Name</label>
      <input v-model="form.name" type="text" class="mt-1 w-full rounded border px-3 py-2" required />
      <p v-if="errors.name" class="text-red-600 text-xs mt-1">{{ errors.name }}</p>
    </div>

    <div>
      <label class="block text-sm font-medium">Period</label>
      <select v-model="form.period_unit" class="mt-1 w-full rounded border px-3 py-2">
        <option value="weekly">Weekly</option>
        <option value="monthly">Monthly</option>
        <option value="quarterly">Quarterly</option>
      </select>
    </div>

    <div>
      <label class="block text-sm font-medium">Hours per period</label>
      <input v-model.number="hoursPerPeriod" type="number" min="0" step="0.5" class="mt-1 w-full rounded border px-3 py-2" />
    </div>

    <div>
      <label class="block text-sm font-medium">Starts at</label>
      <input v-model="form.starts_at" type="date" class="mt-1 w-full rounded border px-3 py-2" required />
    </div>

    <!-- Advanced -->
    <button type="button" @click="showAdvanced = !showAdvanced" class="text-sm text-indigo-600">
      {{ showAdvanced ? 'Hide' : 'Show' }} advanced options
    </button>

    <div v-if="showAdvanced" class="space-y-3 border-t pt-3">
      <div>
        <label class="block text-sm">Period mode</label>
        <select v-model="form.period_mode" class="mt-1 w-full rounded border px-3 py-2">
          <option value="calendar">Calendar-aligned</option>
          <option value="anchor">Anchor date</option>
          <option value="explicit">Explicit periods</option>
        </select>
      </div>

      <div v-if="form.period_mode === 'anchor'">
        <label class="block text-sm">Anchor date</label>
        <input v-model="form.anchor_date" type="date" class="mt-1 w-full rounded border px-3 py-2" />
      </div>

      <div>
        <label class="inline-flex items-center gap-2 text-sm">
          <input v-model="form.billable_only" type="checkbox" /> Billable entries only
        </label>
      </div>

      <div>
        <label class="inline-flex items-center gap-2 text-sm">
          <input v-model="form.hard_cap_enabled" type="checkbox" /> Enforce hard cap
        </label>
      </div>

      <div v-if="form.hard_cap_enabled" class="ml-6 space-y-2">
        <div>
          <label class="block text-xs">Cap scope</label>
          <select v-model="form.hard_cap_scope" class="mt-1 w-full rounded border px-2 py-1">
            <option value="per_period">Per period</option>
            <option value="cumulative">Cumulative</option>
          </select>
        </div>
        <div>
          <label class="block text-xs">Enforcement</label>
          <select v-model="form.hard_cap_enforcement" class="mt-1 w-full rounded border px-2 py-1">
            <option value="block">Block (v1)</option>
            <option value="flag" disabled>Flag (v1.1)</option>
            <option value="approval" disabled>Approval (v1.1)</option>
          </select>
        </div>
        <div v-if="form.hard_cap_scope === 'cumulative'">
          <label class="block text-xs">Cumulative cap (hours)</label>
          <input
            :value="(form.hard_cap_cumulative_seconds ?? 0) / 3600"
            @input="form.hard_cap_cumulative_seconds = Number(($event.target as HTMLInputElement).value) * 3600"
            type="number" min="0" step="0.5"
            class="mt-1 w-full rounded border px-2 py-1"
          />
        </div>
      </div>

      <div>
        <label class="block text-sm">End date (optional)</label>
        <input v-model="form.ends_at" type="date" class="mt-1 w-full rounded border px-3 py-2" />
      </div>
    </div>

    <p v-if="errors._" class="text-red-600 text-sm">{{ errors._ }}</p>

    <div class="flex justify-end gap-2 pt-2">
      <button type="button" @click="emit('cancel')" class="rounded border px-3 py-1">Cancel</button>
      <button type="submit" :disabled="submitting" class="rounded bg-indigo-600 text-white px-3 py-1">
        {{ submitting ? 'Saving…' : (isUpdate ? 'Update' : 'Create') }}
      </button>
    </div>
  </form>
</template>
```

**Note on styling:** the inline Tailwind classes are placeholders to match generic shape. After reading Task 19's pattern notes, swap them for the actual design-system tokens solidtime uses (the project has its own `Components/Common` set — use those wrappers, not raw `<input>`).

- [ ] **Step 2: Commit**

```bash
git add resources/js/Components/Retainer/RetainerForm.vue
git commit -m "local: add RetainerForm Vue component (opinionated defaults + Advanced section)"
```

---

## Task 22: Frontend — Client detail page tab integration

**Files:**
- Modify: existing client detail Vue page (identified in Task 19)
- Create: `resources/js/Pages/Client/RetainerTab.vue`

The exact integration path depends on Task 19's findings. Below is the **shape** of `RetainerTab.vue`; the integration step modifies the existing client page to mount this tab.

- [ ] **Step 1: Create `RetainerTab.vue`**

```vue
<script setup lang="ts">
import { ref, onMounted } from 'vue';
import RetainerStatusCard from '@/Components/Retainer/RetainerStatusCard.vue';
import RetainerForm from '@/Components/Retainer/RetainerForm.vue';

const props = defineProps<{ organizationId: string; clientId: string }>();

const retainers = ref<any[]>([]);
const statuses = ref<Record<string, any>>({});
const editing = ref<any | null>(null);
const showForm = ref(false);

const loadRetainers = async () => {
  const r = await fetch(`/api/v1/organizations/${props.organizationId}/clients/${props.clientId}/retainers`);
  retainers.value = (await r.json()).data;
  for (const ret of retainers.value) {
    const s = await fetch(`/api/v1/organizations/${props.organizationId}/retainers/${ret.id}/status`);
    statuses.value[ret.id] = (await s.json()).data;
  }
};

const handleSaved = async () => {
  showForm.value = false;
  editing.value = null;
  await loadRetainers();
};

onMounted(loadRetainers);
</script>

<template>
  <div class="space-y-4">
    <div class="flex justify-between items-center">
      <h2 class="text-lg font-medium">Retainers</h2>
      <button @click="showForm = true; editing = null" class="rounded bg-indigo-600 text-white px-3 py-1 text-sm">
        New retainer
      </button>
    </div>

    <div v-if="showForm" class="rounded border p-4">
      <RetainerForm
        :organization-id="organizationId"
        :client-id="clientId"
        :initial="editing"
        @saved="handleSaved"
        @cancel="showForm = false; editing = null"
      />
    </div>

    <div v-if="!retainers.length && !showForm" class="text-sm text-muted-foreground">
      No retainers for this client yet.
    </div>

    <div v-for="ret in retainers" :key="ret.id" class="space-y-1">
      <RetainerStatusCard :retainer="ret" :status="statuses[ret.id]" v-if="statuses[ret.id]" />
      <div class="flex gap-2 text-xs">
        <button @click="editing = ret; showForm = true" class="text-indigo-600">Edit</button>
      </div>
    </div>
  </div>
</template>
```

- [ ] **Step 2: Mount the tab in the existing client detail page**

Open the page identified in Task 19 (e.g., `resources/js/Pages/Clients/Show.vue`). Add the Retainers tab to the page's tab structure. Pass `organizationId` and `clientId` props.

If the existing page does **not** use a tab pattern, this is the moment to introduce one — keep it minimal (a simple `<button>` row that toggles which child component renders).

- [ ] **Step 3: Manually verify in browser**

Run the dev server: `npm run dev` (in one terminal) and `php artisan serve` (in another, or use the existing dev command from `package.json`).

Navigate to a client detail page → Retainers tab → create a retainer → see the status card render. Save a time entry against a project belonging to that client → verify status updates after cache TTL or after refresh.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Client/RetainerTab.vue resources/js/Pages/<existing-client-show-file>
git commit -m "local: mount Retainers tab on client detail page"
```

---

## Task 23: Frontend — `TimeEntryRetainerBadge.vue`

**Files:**
- Create: `resources/js/Components/Retainer/TimeEntryRetainerBadge.vue`
- Modify: existing time entry form (identified in Task 19)

- [ ] **Step 1: Implement badge component**

```vue
<script setup lang="ts">
import { ref, watch } from 'vue';

const props = defineProps<{
  organizationId: string;
  clientId: string | null;
}>();

const status = ref<{ allocated_seconds: number; tracked_seconds: number; percent: number; hard_cap_enabled: boolean } | null>(null);
const retainerName = ref<string | null>(null);

const load = async () => {
  status.value = null;
  retainerName.value = null;
  if (!props.clientId) return;

  // Fetch retainers for client, take the active one (first non-deleted in window)
  const r = await fetch(`/api/v1/organizations/${props.organizationId}/clients/${props.clientId}/retainers`);
  const retainers = (await r.json()).data;
  const today = new Date().toISOString().slice(0, 10);
  const active = retainers.find((x: any) =>
    x.starts_at <= today && (x.ends_at === null || x.ends_at >= today)
  );
  if (!active) return;

  retainerName.value = active.name;
  const s = await fetch(`/api/v1/organizations/${props.organizationId}/retainers/${active.id}/status`);
  status.value = (await s.json()).data;
};

watch(() => props.clientId, load, { immediate: true });

const toH = (n: number) => (n / 3600).toFixed(1);
</script>

<template>
  <div v-if="status && retainerName" class="inline-flex items-center gap-1 text-xs px-2 py-1 rounded bg-muted text-muted-foreground">
    <span class="font-medium">{{ retainerName }}:</span>
    <span>{{ toH(status.tracked_seconds) }}h / {{ toH(status.allocated_seconds) }}h</span>
    <span>({{ (status.percent * 100).toFixed(0) }}%)</span>
  </div>
</template>
```

- [ ] **Step 2: Mount in time entry form**

Open the time entry form file identified in Task 19. Below the project picker, mount:

```vue
<TimeEntryRetainerBadge :organization-id="currentOrgId" :client-id="selectedProjectClientId" />
```

Where `selectedProjectClientId` is derived from the project picker's emitted project. (The time entry form already knows the project's `client_id` once a project is selected — surface that into the badge.)

- [ ] **Step 3: Verify manually in browser**

Pick a project belonging to a client with an active retainer → badge appears. Pick a project without one → badge hides.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Components/Retainer/TimeEntryRetainerBadge.vue resources/js/Pages/<modified-time-entry-form>
git commit -m "local: add TimeEntryRetainerBadge + mount on time entry form"
```

---

## Task 24: Full test suite + linting

**Files:**
- No source changes — just verification

- [ ] **Step 1: Run the full PHP test suite**

Run: `./vendor/bin/phpunit`
Expected: all green (your new tests + everything that existed before).

- [ ] **Step 2: Run PHPStan**

Run: `./vendor/bin/phpstan analyse`
Expected: no errors. Fix any type complaints in the new files.

- [ ] **Step 3: Run Laravel Pint**

Run: `./vendor/bin/pint --test`
Expected: no formatting violations. If there are, run `./vendor/bin/pint` to fix, review the diff, commit.

- [ ] **Step 4: Run frontend lint**

Run: `npm run lint`
Expected: no errors in `resources/js/Components/Retainer/**` or `resources/js/Pages/Client/RetainerTab.vue`.

- [ ] **Step 5: Run frontend type check**

Run: `npm run typecheck` (or the equivalent from `package.json` scripts)
Expected: no type errors. The openapi-generated types in `schema.d.ts` should cover the new endpoints (verify the API resources match — adjust shape if mismatched).

- [ ] **Step 6: Commit any cleanup**

```bash
git add -A
git commit -m "local: lint + format pass"  # only if there's actual cleanup
```

---

## Task 25: Push branch + verify reyem workflow

- [ ] **Step 1: Push reyem**

Run: `git push origin reyem`
Expected: branch updated on github.com/ReyemTech/solidtime.

- [ ] **Step 2: Verify CLAUDE.md still doesn't exist on main**

Run: `git checkout main && ls CLAUDE.md docs/superpowers/ deploy/ 2>&1; git checkout reyem`
Expected: all three are "No such file or directory" on `main`, then back on `reyem`.

- [ ] **Step 3: Manual end-to-end smoke test**

In a browser, log into solidtime locally:

1. Create a client "Test Co"
2. Create a project "Site work" for Test Co
3. Go to client detail → Retainers → create "Test Co Monthly", 40h/month, calendar/monthly, starts today
4. Verify the status card shows 0h/0h with smooth progress as time passes (or use the time-travel feature if solidtime has one)
5. Log a 2h time entry on Site work → refresh client detail → status card shows 2h/Xh
6. Edit the retainer → enable hard cap, block, per-period, set rate to 1h
7. Try to log another 2h entry → server returns 422 with `retainer_cap_exceeded` error

- [ ] **Step 4: Document any deviations**

If anything didn't match the spec or this plan, capture it in `docs/superpowers/notes/retainer-v1-implementation-notes.md` and commit. This becomes input for subsystem #2 (statistics view) planning.

---

## Spec coverage check (self-review)

| Spec section | Implemented in task(s) |
|---|---|
| §2 Goals | Tasks 6–18 (model + service + API) |
| §3 Non-goals | Not implemented (correct) |
| §4 Architecture (4 units) | Tasks 7, 8, 10, 12, 13 |
| §5 Data model — `retainers` | Task 3 + Task 6 |
| §5 Data model — `retainer_periods` | Task 4 + Task 6 |
| §5 Data model — `retainer_project_caps` | Task 5 + Task 6 |
| §5 Validation: required-when rules | Task 14 |
| §5 Validation: "one active retainer per client per date" | Task 16 (overlap check in controller) |
| §5 Validation: sub-cap project belongs to client | Task 14 (`RetainerProjectCapStoreRequest`) |
| §5 Validation: explicit periods don't overlap | Task 14 (`RetainerPeriodsReplaceRequest`) |
| §6 Read path: `AllocationCalculator` | Task 7 |
| §6 Read path: `ConsumptionQuery` | Task 8 |
| §6 Read path: `RetainerCache` (5min TTL, tagged) | Task 10 |
| §7 Write path: `CapEnforcer` | Task 12 |
| §7 Write path: observer | Task 13 |
| §7 Write path: uncached read on write | Task 12 (`CapEnforcer` ctor uses `ConsumptionQuery`, not `RetainerCache`) |
| §7 v1: only `block` wired | Task 12 (`flag`/`approval` skipped) |
| §8 API: all endpoints | Tasks 16, 17, 18 |
| §8 Status response shape | Task 16 (`status` action) |
| §9.1 Client tab UI | Tasks 19, 22 |
| §9.1 Opinionated default form + Advanced section | Task 21 |
| §9.2 TimeEntry retainer badge | Task 23 |
| §9.3 Dashboard tile (v1.5) | Not in v1 (correct) |
| §10 Testing — `AllocationCalculator` table-driven | Task 7 |
| §10 Testing — `ConsumptionQuery` | Task 8 |
| §10 Testing — `CapEnforcer` + observer | Tasks 12, 13 |
| §10 Testing — API/policy | Tasks 16, 17, 18 |
| §11 Audit | Deferred — not in v1 plan (see Open question below) |
| §12 Migration & rollout | Tasks 3–5 (additive only) |
| §15 Acceptance criteria | Task 25 (smoke test) |

**Gaps found during self-review:**

1. **Spec §11 (Audit wiring) is not in a numbered task.** Solidtime's existing `Audit` model trait — if it's a one-line trait add (`use LogsActivity` or similar), adding it to the three new models is trivial and should be in Task 6. **Fix:** add to Task 6 step 4 — after defining the model class body, add the audit trait the same way `Client.php` does. Inspect `app/Models/Client.php` for the exact trait, add it to `Retainer`, `RetainerPeriod`, `RetainerProjectCap`.

2. **The `periods` endpoint (Task 16) is stubbed.** It returns `[]`. The spec says it returns per-period breakdown for charts. This is fine for v1 (no consumer yet — subsystem #2 will need it), but flag this explicitly: when subsystem #2 starts, implement the body. **Fix:** added explicit note in the controller and in Task 25 deviations log.

Both gaps are addressed inline — the plan structure is otherwise consistent with the spec.

---

## Execution recommendations

- **Tasks 1–18 can run in two parallel streams** after Task 6 (models exist):
  - Stream A: services + observer (Tasks 7 → 8 → 9 → 10 → 11 → 12 → 13)
  - Stream B: form requests → resources → controllers → routes (Tasks 14 → 15 → 16 → 17 → 18)
- **Tasks 19–23 (frontend) must run sequentially** after the API exists.
- **Task 24 (full test suite) is a final gate** before push.
