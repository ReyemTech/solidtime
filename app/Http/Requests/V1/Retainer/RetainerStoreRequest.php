<?php
declare(strict_types=1);

namespace App\Http\Requests\V1\Retainer;

use App\Enums\RetainerHardCapEnforcement;
use App\Enums\RetainerHardCapScope;
use App\Enums\RetainerPeriodMode;
use App\Enums\RetainerPeriodUnit;
use App\Enums\RetainerSubCapMode;
use App\Models\Organization;
use App\Http\Requests\V1\BaseFormRequest;
use Illuminate\Validation\Rule;

class RetainerStoreRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Organization $organization */
        $organization = $this->route('organization');

        return [
            'client_id' => [
                'required', 'string',
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
