<div class="space-y-4">
    @if ($node === null)
        <x-filament::section>节点不存在。</x-filament::section>
    @else
        <x-filament::section heading="实时指标（6 小时）" description="每 5 秒刷新，来自节点上报的指标批次">
            @php
                $last = $node->latestMetric;
                $peak = collect($metrics)->max('cpu') ?? 0;
            @endphp
            <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                <div><div class="text-xs text-gray-500">CPU</div><div class="text-2xl font-semibold">{{ $last?->cpu ?? 0 }}%</div><div class="text-xs text-gray-400">峰值 {{ round($peak, 1) }}%</div></div>
                <div><div class="text-xs text-gray-500">内存</div><div class="text-2xl font-semibold">{{ $last?->mem ?? 0 }}%</div></div>
                <div><div class="text-xs text-gray-500">连接</div><div class="text-2xl font-semibold">{{ $last?->conn_count ?? 0 }}</div></div>
                <div><div class="text-xs text-gray-500">用户</div><div class="text-2xl font-semibold">{{ $last?->users ?? 0 }}</div></div>
            </div>
            <div class="mt-3 text-xs text-gray-500">
                下行 {{ \App\Filament\Widgets\StatsOverviewWidget::humanBytes($last?->net_rx ?? 0) }}
                · 上行 {{ \App\Filament\Widgets\StatsOverviewWidget::humanBytes($last?->net_tx ?? 0) }}
                · 采样点 {{ count($metrics) }}
                · 最后心跳 {{ $node->last_seen_at?->diffForHumans() ?? '-' }}
            </div>
        </x-filament::section>

        <div class="grid gap-4 md:grid-cols-2">
            <x-filament::section heading="最近指令">
                @forelse ($commands as $command)
                    <div class="flex items-center justify-between border-b border-gray-100 py-1 text-sm last:border-0 dark:border-white/5">
                        <span>{{ $command->typeLabel() }}</span>
                        <span class="text-xs text-gray-500">{{ $command->status }} · {{ $command->issued_at?->diffForHumans() }}</span>
                    </div>
                @empty
                    <div class="text-sm text-gray-500">暂无指令</div>
                @endforelse
            </x-filament::section>

            <x-filament::section heading="配置版本">
                @forelse ($configs as $config)
                    <div class="flex items-center justify-between border-b border-gray-100 py-1 text-sm last:border-0 dark:border-white/5">
                        <span>v{{ $config->version }} <span class="cp-mono text-xs text-gray-500">{{ $config->shortHash() }}</span></span>
                        <span class="text-xs text-gray-500">{{ $config->status }} · {{ $config->published_at?->diffForHumans() }}</span>
                    </div>
                @empty
                    <div class="text-sm text-gray-500">尚未发布配置</div>
                @endforelse
            </x-filament::section>
        </div>
    @endif
</div>
