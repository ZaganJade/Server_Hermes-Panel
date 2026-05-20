<div x-data="hermesHealthStrip()" x-init="init()" x-destroy="destroy()"
     class="grid grid-cols-2 lg:grid-cols-4 gap-px bg-[color:var(--rule)] border border-[color:var(--rule)] mb-12 animate-fade-up">
    @foreach ([
        ['cpu',  'CPU'],
        ['mem',  'Memori'],
        ['disk', 'Disk'],
        ['net',  'Jaringan'],
    ] as [$key, $label])
    <a href="{{ route('panel.monitoring') }}#{{ $key }}"
       class="group bg-ink hover:bg-ink-soft p-5 flex items-center justify-between gap-4 transition-colors"
       :class="alerts.{{ $key }} === 'critical'
           ? 'ring-1 ring-inset ring-[color:var(--rust)]'
           : (alerts.{{ $key }} === 'warning' ? 'ring-1 ring-inset ring-[color:var(--copper)]' : '')">
        <div class="min-w-0">
            <div class="font-mono text-[9px] tracking-[0.22em] uppercase text-paper-dim mb-1">{{ $label }}</div>
            <div class="font-serif italic text-2xl text-paper leading-none truncate"
                 style="font-variation-settings: 'opsz' 60, 'wght' 400, 'WONK' 1;"
                 x-text="current.{{ $key }}"></div>
        </div>
        <div class="shrink-0 opacity-70 group-hover:opacity-100 transition-opacity">
            <canvas x-ref="spark_{{ $key }}" width="80" height="32"></canvas>
        </div>
    </a>
    @endforeach
</div>
