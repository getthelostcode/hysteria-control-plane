<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\NodeCommand;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IssueCommandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(array_keys(NodeCommand::TYPES))],
            'payload' => ['nullable', 'array'],
            'nodes' => ['nullable', 'array'],
            'nodes.*' => ['string'],
        ];
    }
}
