<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RetainerHardCapEnforcement;
use App\Enums\RetainerHardCapScope;
use App\Enums\RetainerPeriodMode;
use App\Enums\RetainerPeriodUnit;
use App\Enums\RetainerSubCapMode;
use App\Models\Concerns\CustomAuditable;
use App\Models\Concerns\HasUuids;
use Database\Factories\RetainerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

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
 *
 * @method static RetainerFactory factory()
 */
class Retainer extends Model implements AuditableContract
{
    use CustomAuditable;

    /** @use HasFactory<RetainerFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
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

    /**
     * @var list<string>
     */
    protected $fillable = [
        'organization_id', 'client_id', 'name', 'description',
        'period_mode', 'period_unit', 'seconds_per_period', 'anchor_date',
        'starts_at', 'ends_at', 'billable_only',
        'hard_cap_enabled', 'hard_cap_scope', 'hard_cap_enforcement', 'hard_cap_cumulative_seconds',
        'sub_cap_mode',
    ];

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return HasMany<RetainerPeriod, $this>
     */
    public function periods(): HasMany
    {
        return $this->hasMany(RetainerPeriod::class);
    }

    /**
     * @return HasMany<RetainerProjectCap, $this>
     */
    public function projectCaps(): HasMany
    {
        return $this->hasMany(RetainerProjectCap::class);
    }
}
