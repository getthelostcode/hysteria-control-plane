{{-- 某个配置版本的正文（模态里查看，只读） --}}
<div class="space-y-3" wire:key="config-content-{{ $config->id }}">
    <div class="text-sm text-gray-500 dark:text-gray-400">
        节点 {{ $config->node?->name ?? '-' }} · v{{ $config->version }} · sha256
        <span class="font-mono">{{ $config->shortHash() }}</span>
        @if ($config->is_current)
            <span class="ml-2 rounded bg-success-100 px-2 py-0.5 text-xs text-success-800 dark:bg-success-500/20 dark:text-success-300">默认下发</span>
        @endif
    </div>
    <pre class="max-h-[28rem] overflow-auto rounded-lg bg-gray-950 p-4 text-xs leading-relaxed text-gray-100">{{ $config->content_yaml }}</pre>
</div>
