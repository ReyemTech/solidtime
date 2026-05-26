<?php
declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\CustomAuditable;
use App\Models\Concerns\HasUuids;
use Database\Factories\RetainerProjectCapFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * @property string $id
 * @property string $retainer_id
 * @property string $project_id
 * @property int|null $seconds_per_period
 * @property int|null $seconds_cumulative
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @method static RetainerProjectCapFactory factory()
 */
class RetainerProjectCap extends Model implements AuditableContract
{
    use CustomAuditable;

    /** @use HasFactory<RetainerProjectCapFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'seconds_per_period' => 'int',
        'seconds_cumulative' => 'int',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = ['retainer_id', 'project_id', 'seconds_per_period', 'seconds_cumulative'];

    /**
     * @return BelongsTo<Retainer, $this>
     */
    public function retainer(): BelongsTo
    {
        return $this->belongsTo(Retainer::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
