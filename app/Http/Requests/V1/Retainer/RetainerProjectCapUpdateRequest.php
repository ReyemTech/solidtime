<?php
declare(strict_types=1);

namespace App\Http\Requests\V1\Retainer;

use App\Http\Requests\V1\BaseFormRequest;

class RetainerProjectCapUpdateRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'seconds_per_period' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'seconds_cumulative' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }
}
