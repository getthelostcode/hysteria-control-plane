<div class="space-y-3">
    <div class="flex gap-3 text-xs">
        <span class="rounded bg-success-50 px-2 py-1 text-success-700">新增 {{ $diff['added'] }}</span>
        <span class="rounded bg-danger-50 px-2 py-1 text-danger-700">删除 {{ $diff['removed'] }}</span>
        <span class="rounded bg-gray-100 px-2 py-1 text-gray-600">未变 {{ $diff['same'] }}</span>
        <span class="text-gray-500">
            v{{ $previous?->version ?? 0 }} → v{{ $config->version }}
            · sha256 <span class="cp-mono">{{ $config->shortHash() }}</span>
        </span>
    </div>
    <div class="cp-diff cp-mono max-h-96 overflow-auto rounded-lg border border-gray-200 p-3 text-xs dark:border-white/10">
        {!! $diff['html'] !!}
    </div>
</div>
