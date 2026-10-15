<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload of the hysteria-agent enroll contract (DESIGN.md §3.1):
 * {enroll_token, hostname, machine_id, boot_id, os, arch, agent_version, ...}
 * The token may also arrive in the X-Enroll-Token header.
 */
class AgentV1EnrollRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'enroll_token' => ['nullable', 'string', 'min:8', 'max:128'],
            'token' => ['nullable', 'string', 'min:8', 'max:128'],
            'node_name' => ['nullable', 'string', 'max:80'],
            'hostname' => ['nullable', 'string', 'max:80'],
            'os' => ['nullable', 'string', 'max:32'],
            'arch' => ['nullable', 'string', 'max:16'],
            'kernel' => ['nullable', 'string', 'max:64'],
            'machine_id' => ['nullable', 'string', 'max:128'],
            'boot_id' => ['nullable', 'string', 'max:128'],
            'agent_version' => ['nullable', 'string', 'max:32'],
            'hysteria_version' => ['nullable', 'string', 'max:32'],
            'protocol' => ['nullable', 'string', 'max:32'],
            'replace' => ['nullable', 'boolean'],
            'region' => ['nullable', 'string', 'max:64'],
            'tags' => ['nullable', 'array', 'max:16'],
            // agent 自报的公网出口地址（节点在 NAT/隧道后时，控制面只能看到对端代理地址）
            'ip' => ['nullable', 'ip'],
            'public_ip' => ['nullable', 'ip'],
        ];
    }

    public function token(): string
    {
        $fromHeader = (string) ($this->header('X-Enroll-Token') ?? '');

        return trim($fromHeader !== '' ? $fromHeader : (string) ($this->input('enroll_token') ?? $this->input('token') ?? ''));
    }

    public function wantsReplace(): bool
    {
        return $this->boolean('replace');
    }

    /** @return array<string, mixed> */
    public function agentAttributes(): array
    {
        $validated = $this->validated();

        return array_filter([
            'node_name' => $validated['node_name'] ?? $validated['hostname'] ?? null,
            'hostname' => $validated['hostname'] ?? null,
            'os' => $validated['os'] ?? null,
            'arch' => $validated['arch'] ?? null,
            'kernel' => $validated['kernel'] ?? null,
            'machine_id' => $validated['machine_id'] ?? null,
            'boot_id' => $validated['boot_id'] ?? null,
            'agent_version' => $validated['agent_version'] ?? null,
            'hysteria_version' => $validated['hysteria_version'] ?? null,
            'protocol' => $validated['protocol'] ?? null,
            'region' => $validated['region'] ?? null,
            'tags' => $validated['tags'] ?? null,
            // 节点自报的公网出口地址（此前在这里被丢掉，所以面板只能显示控制面观测到的
            // 隧道地址 127.0.0.1 —— 这就是「节点 IP 全是 127.0.0.1」的第二个原因）
            'public_ip' => $validated['public_ip'] ?? $validated['ip'] ?? null,
        ], static fn ($value) => $value !== null);
    }
}
