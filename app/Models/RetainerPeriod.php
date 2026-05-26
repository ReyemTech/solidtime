<?php
declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\CustomAuditable;
use App\Models\Concerns\HasUuids;
use Database\Factories\RetainerPeriodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * @property string $id
 * @property string $retainer_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property int $seconds_allocated
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @method static RetainerPeriodFactory factory()
 */
class RetainerPeriod extends Model implements AuditableContract
{
    use CustomAuditable;

    /** @use HasFactory<RetainerPeriodFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'starts_at' => 'date',
        'ends_at' => 'date',
        'seconds_allocated' => 'int',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = ['retainer_id', 'starts_at', 'ends_at', 'seconds_allocated'];

    /**
     * @return BelongsTo<Retainer, $this>
     */
    public function retainer(): BelongsTo
    {
        return $this->belongsTo(Retainer::class);
    }
}
