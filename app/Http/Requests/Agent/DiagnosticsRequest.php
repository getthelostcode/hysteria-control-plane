<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent;

use Illuminate\Foundation\Http\FormRequest;

class DiagnosticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'payload' => ['required', 'array'],
            'payload.summary' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * The full diagnostic blob.
     *
     * validated() only returns keys that have a rule, so the raw input is read
     * here instead: agents attach arbitrary host data.
     *
     * @return array<string, mixed>
     */
    public function diagnostics(): array
    {
        $payload = $this->input('payload');

        return is_array($payload) ? $payload : [];
    }
}
