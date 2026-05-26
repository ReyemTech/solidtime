<?php
declare(strict_types=1);

namespace App\Http\Requests\V1\Retainer;

use Illuminate\Contracts\Validation\Validator;
use App\Http\Requests\V1\BaseFormRequest;

class RetainerPeriodsReplaceRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'periods' => ['required', 'array', 'min:1'],
            'periods.*.starts_at' => ['required', 'date'],
            'periods.*.ends_at' => ['required', 'date', 'after_or_equal:periods.*.starts_at'],
            'periods.*.seconds_allocated' => ['required', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
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
