<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent;

use Illuminate\Foundation\Http\FormRequest;

class LogReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'entries' => ['array', 'max:' . config('agent.max_log_entries')],
            'logs' => ['array', 'max:' . config('agent.max_log_entries')],
            // Levels are normalised in the controller: an unknown level must not 422 the whole batch.
            'entries.*.level' => ['nullable', 'string', 'max:16'],
            'entries.*.message' => ['required', 'string', 'max:4000'],
            'entries.*.context' => ['nullable', 'array'],
            'entries.*.recorded_at' => ['nullable', 'date'],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function entries(): array
    {
        $entries = $this->input('entries') ?? $this->input('logs') ?? [];

        return is_array($entries) ? array_values(array_filter($entries, 'is_array')) : [];
    }
}
