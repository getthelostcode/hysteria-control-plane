<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUpgradePackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'version' => [
                'required', 'string', 'max:32',
                Rule::unique('upgrade_packages', 'version')
                    ->where('arch', (string) $this->input('arch', 'amd64'))
                    ->where('channel', (string) $this->input('channel', 'stable')),
            ],
            'arch' => ['nullable', 'string', Rule::in(['amd64', 'arm64', 'arm', '386'])],
            'url' => ['required', 'url', 'max:512'],
            'sha256' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/i'],
            'signature' => ['nullable', 'string'],
            'channel' => ['nullable', 'string', Rule::in(['stable', 'beta', 'canary'])],
            'rollout_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'released_at' => ['nullable', 'date'],
        ];
    }
}
