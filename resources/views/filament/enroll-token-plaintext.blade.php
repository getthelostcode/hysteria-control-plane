{{--
    注册令牌明文的一次性展示。
    放在 action 的 modalContent 里：notification body 会被 Str::sanitizeHtml() 清掉所有属性
    （onclick/x-on-click 都会被剥掉），所以「一键复制」按钮只能出现在这里。
--}}
<div x-data="{ copied: false, value: @js($plain) }" class="space-y-3">
    <div
        class="select-all break-all rounded-lg bg-gray-50 p-3 font-mono text-xs leading-5 text-gray-950 ring-1 ring-gray-950/5 dark:bg-white/5 dark:text-white dark:ring-white/10"
        x-ref="token"
    >{{ $plain }}</div>

    <div class="flex flex-wrap items-center gap-3">
        <x-filament::button
            size="sm"
            color="primary"
            x-on:click="navigator.clipboard.writeText(value); copied = true; setTimeout(() => copied = false, 2500)"
        >
            <span x-show="! copied">复制完整令牌</span>
            <span x-show="copied" x-cloak>已复制 ✓</span>
        </x-filament::button>

        <span class="text-xs text-gray-500 dark:text-gray-400">
            共 {{ strlen($plain) }} 个字符，形如 <code>enr_</code> + 40 位随机字符；列表页显示的
            <code>{{ $prefix }}…</code> 是掩码，用它注册一定失败。
        </span>
    </div>
</div>
