@extends('panel.layout')

@section('title', 'Monitoring')
@section('section-label', 'Modul · N° 006')
@section('breadcrumb', 'Monitoring')

@section('content')
<div x-data="hermesMonitoring()" x-init="init()" x-destroy="destroy()" class="space-y-10">

    <!-- Header strip: 4 sparklines bigger than dashboard -->
    @include('panel._dashboard_health_strip')

    <!-- Window selector -->
    <div class="flex items-center gap-3 animate-fade-up-1">
        <span class="font-mono text-[10px] tracking-[0.22em] uppercase text-paper-dim">Window:</span>
        @foreach (['5m','15m','1h','6h','24h'] as $w)
        <button @click="setWindow('{{ $w }}')"
                class="font-mono text-[10px] tracking-[0.18em] uppercase px-3 py-1.5 border transition-colors"
                :class="window === '{{ $w }}'
                    ? 'border-[color:var(--copper)] text-copper'
                    : 'border-[color:var(--rule)] text-paper-dim hover:text-paper'">
            {{ $w }}
        </button>
        @endforeach
    </div>

    <!-- Charts grid -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 animate-fade-up-2">
        @foreach ([
            ['cpu_usage_pct',           'CPU Usage (%)',           'cpu'],
            ['mem_used_kb',             'Memori (KB)',             'mem'],
            ['disk_read_bytes_per_sec', 'Disk Read (B/s)',         'disk'],
            ['disk_write_bytes_per_sec','Disk Write (B/s)',        'disk'],
            ['net_tcp_established',     'TCP Established',         'net'],
        ] as [$slug, $label, $section])
        <article id="{{ $section }}"
                 class="card-editorial p-5">
            <div class="font-mono text-[10px] tracking-[0.22em] uppercase text-paper-dim mb-3">{{ $label }}</div>
            <div x-ref="chart_{{ $slug }}" class="w-full h-[200px]"></div>
        </article>
        @endforeach
    </div>

    <!-- Services table -->
    <section class="card-editorial p-5 animate-fade-up-3">
        <div class="flex items-center justify-between mb-4">
            <h2 class="font-serif text-xl italic text-paper"
                style="font-variation-settings: 'opsz' 60, 'wght' 400, 'WONK' 1;">Layanan</h2>
            <button @click="refreshServices()"
                    :disabled="refreshing"
                    class="font-mono text-[9px] tracking-[0.22em] uppercase text-paper-dim hover:text-copper transition-colors disabled:opacity-50">
                <span x-show="!refreshing">Refresh ↻</span>
                <span x-show="refreshing" x-cloak>Memuat…</span>
            </button>
        </div>
        <div class="overflow-x-auto">
            <table class="table-editorial w-full">
                <thead>
                    <tr>
                        <th>Unit</th>
                        <th>Status</th>
                        <th>Detection</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="row in services" :key="row.unit">
                        <tr>
                            <td x-text="row.unit"></td>
                            <td>
                                <span :class="row.status === 'active'
                                    ? 'text-[color:var(--verdigris)]'
                                    : (row.status === 'failed' ? 'text-[color:var(--rust)]' : 'text-paper-dim')"
                                      x-text="row.status"></span>
                            </td>
                            <td class="text-paper-dim text-[10px] uppercase tracking-wider" x-text="row.detection"></td>
                        </tr>
                    </template>
                    <tr x-show="!services.length" x-cloak>
                        <td colspan="3" class="text-center text-paper-dim italic font-serif py-6">
                            Belum ada layanan terdeteksi.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Process top + Listening ports collapsible -->
    <section class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <article class="card-editorial p-5">
            <button @click="showProcesses = !showProcesses"
                    class="flex items-center justify-between w-full mb-3 group">
                <h2 class="font-serif text-xl italic text-paper"
                    style="font-variation-settings: 'opsz' 60, 'wght' 400, 'WONK' 1;">Proses Teratas</h2>
                <span class="font-mono text-[10px] tracking-[0.22em] uppercase text-paper-dim group-hover:text-copper"
                      x-text="showProcesses ? '— sembunyikan' : '+ tampilkan'"></span>
            </button>
            <div x-show="showProcesses" x-cloak class="overflow-x-auto">
                <table class="table-editorial w-full">
                    <thead><tr><th>PID</th><th>Nama</th><th>CPU%</th><th>RSS (KB)</th></tr></thead>
                    <tbody>
                        <template x-for="p in processes" :key="p.pid">
                            <tr>
                                <td class="text-paper-dim" x-text="p.pid"></td>
                                <td x-text="p.name"></td>
                                <td x-text="p.cpu_pct ?? '—'"></td>
                                <td x-text="p.rss_kb"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </article>

        <article class="card-editorial p-5">
            <button @click="showPorts = !showPorts"
                    class="flex items-center justify-between w-full mb-3 group">
                <h2 class="font-serif text-xl italic text-paper"
                    style="font-variation-settings: 'opsz' 60, 'wght' 400, 'WONK' 1;">Port Mendengar</h2>
                <span class="font-mono text-[10px] tracking-[0.22em] uppercase text-paper-dim group-hover:text-copper"
                      x-text="showPorts ? '— sembunyikan' : '+ tampilkan'"></span>
            </button>
            <div x-show="showPorts" x-cloak class="overflow-x-auto">
                <table class="table-editorial w-full">
                    <thead><tr><th>Port</th><th>Proto</th><th>Address</th><th>Process</th></tr></thead>
                    <tbody>
                        <template x-for="(row, idx) in ports" :key="idx">
                            <tr>
                                <td class="text-copper" x-text="row.port"></td>
                                <td class="text-paper-dim uppercase text-[10px] tracking-wider" x-text="row.proto"></td>
                                <td x-text="row.address"></td>
                                <td x-text="row.process_name ?? '—'"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </article>
    </section>

    <!-- Alerts log (sticky bottom) -->
    <section x-show="alerts.length > 0" x-cloak
             class="card-editorial p-5 border-l-4 border-[color:var(--copper)]">
        <div class="font-mono text-[10px] tracking-[0.22em] uppercase text-copper mb-3">Peringatan Aktif</div>
        <ul class="space-y-2 font-mono text-[12px]">
            <template x-for="alert in alerts" :key="alert.rule_id + alert.ts">
                <li class="flex items-baseline gap-3">
                    <span class="text-[10px] tracking-wider uppercase shrink-0"
                          :class="alert.level === 'critical' ? 'text-[color:var(--rust)]' : 'text-copper'"
                          x-text="`[${alert.level}]`"></span>
                    <span class="text-paper" x-text="alert.message"></span>
                </li>
            </template>
        </ul>
    </section>

</div>
@endsection
