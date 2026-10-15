<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent;

use Illuminate\Foundation\Http\FormRequest;

class EnrollRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'min:8', 'max:128'],
            'name' => ['nullable', 'string', 'max:80'],
            'region' => ['nullable', 'string', 'max:64'],
            'tags' => ['nullable', 'array', 'max:16'],
            'tags.*' => ['string', 'max:32'],
            'version' => ['nullable', 'string', 'max:32'],
            'agent_version' => ['nullable', 'string', 'max:32'],
            'arch' => ['nullable', 'string', 'max:16'],
            'kernel' => ['nullable', 'string', 'max:64'],
            'ip' => ['nullable', 'ip'],
        ];
    }
}
