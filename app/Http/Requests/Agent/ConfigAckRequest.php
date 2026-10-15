<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent;

use Illuminate\Foundation\Http\FormRequest;

class ConfigAckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'status' => ['required', 'string', 'in:applied,failed'],
            'error' => ['nullable', 'string', 'max:2000'],
            'applied_at' => ['nullable', 'date'],
        ];
    }
}
