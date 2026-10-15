<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent;

use Illuminate\Foundation\Http\FormRequest;

class MetricsReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $sample = [
            'cpu' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'mem' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'disk' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'net_rx' => ['nullable', 'integer', 'min:0'],
            'net_tx' => ['nullable', 'integer', 'min:0'],
            'conn_count' => ['nullable', 'integer', 'min:0'],
            'users' => ['nullable', 'integer', 'min:0'],
            'recorded_at' => ['nullable', 'date'],
            'extra' => ['nullable', 'array'],
        ];

        $rules = [
            'samples' => ['array', 'max:' . config('agent.max_metric_samples')],
            'metrics' => ['array', 'max:' . config('agent.max_metric_samples')],
        ];

        foreach (['samples', 'metrics'] as $key) {
            foreach ($sample as $field => $constraints) {
                $rules[$key . '.*.' . $field] = $constraints;
            }
        }

        return $rules;
    }

    /** @return array<int, array<string, mixed>> */
    public function samples(): array
    {
        $samples = $this->input('samples') ?? $this->input('metrics');

        // A single sample may be posted flat ({"cpu":1,"mem":2}) with no wrapper key.
        if ($samples === null) {
            $samples = $this->all();
        }

        if (! is_array($samples)) {
            return [];
        }

        if (isset($samples['cpu']) || isset($samples['net_rx']) || isset($samples['mem'])) {
            $samples = [$samples];
        }

        return array_values(array_filter($samples, 'is_array'));
    }
}
