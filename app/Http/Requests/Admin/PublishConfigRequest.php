<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class PublishConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'content_yaml' => ['nullable', 'string', 'max:200000'],
            'reason' => ['nullable', 'string', 'max:500'],
            'overrides' => ['nullable', 'array'],
            'use_template' => ['nullable', 'boolean'],
        ];
    }
}
