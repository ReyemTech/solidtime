<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Retainer;

use App\Http\Requests\V1\BaseFormRequest;
use Illuminate\Contracts\Validation\Validator;

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
        $validator->after(function ($v): void {
            /** @var array<int, array{starts_at: string, ends_at: string, seconds_allocated: int}> $input */
            $input = $this->input('periods', []);
            usort($input, fn (array $a, array $b): int => strcmp($a['starts_at'], $b['starts_at']));
            for ($i = 1; $i < count($input); $i++) {
                if ($input[$i]['starts_at'] <= $input[$i - 1]['ends_at']) {
                    $v->errors()->add('periods', 'Periods must not overlap.');

                    return;
                }
            }
        });
    }
}
