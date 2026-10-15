<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent;

use Illuminate\Foundation\Http\FormRequest;

class HeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'version' => ['nullable', 'string', 'max:32'],
            'agent_version' => ['nullable', 'string', 'max:32'],
            'uptime' => ['nullable', 'integer', 'min:0'],
            'ip' => ['nullable', 'ip'],
            'public_ip' => ['nullable', 'ip'],   // agent 自报的公网出口地址（NAT/桥接下唯一可靠来源）
            'region' => ['nullable', 'string', 'max:64'],
            'tags' => ['nullable', 'array', 'max:16'],
            'meta' => ['nullable', 'array'],
        ];
    }
}
