<?php
declare(strict_types=1);

namespace App\Http\Requests\V1\Retainer;

use Illuminate\Foundation\Http\FormRequest;

class RetainerProjectCapUpdateRequest extends FormRequest
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
