<?php
declare(strict_types=1);

namespace App\Http\Requests\V1\Retainer;

use App\Models\Retainer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RetainerProjectCapStoreRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Retainer $retainer */
        $retainer = $this->route('retainer');

        return [
            'project_id' => [
                'required', 'string',
                Rule::exists('projects', 'id')->where('client_id', $retainer->client_id),
                Rule::unique('retainer_project_caps', 'project_id')->where('retainer_id', $retainer->id),
            ],
            'seconds_per_period' => ['nullable', 'integer', 'min:0'],
            'seconds_cumulative' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
