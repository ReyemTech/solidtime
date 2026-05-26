<?php
declare(strict_types=1);

namespace App\Http\Requests\V1\Retainer;

use App\Enums\RetainerHardCapEnforcement;
use App\Enums\RetainerHardCapScope;
use App\Enums\RetainerPeriodMode;
use App\Enums\RetainerPeriodUnit;
use App\Enums\RetainerSubCapMode;
use App\Http\Requests\V1\BaseFormRequest;
use Illuminate\Validation\Rule;

class RetainerUpdateRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
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
