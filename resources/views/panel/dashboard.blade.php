@extends('panel.layout')

@section('title', 'Dashboard')
@section('section-label', 'Modul · N° 001')
@section('breadcrumb', 'Dashboard')

@section('content')
<div x-data="dashboardApp()">

    <!-- Editorial Hero -->
    <div class="mb-16 animate-fade-up">
        <div class="grid lg:grid-cols-[1fr_auto] gap-8 items-end pb-8 border-b border-[color:var(--rule)]">
            <div>
                <div class="section-label mb-6">Beranda</div>
                <h1 class="title-editorial">
                    Selamat datang,<br>
                    <span class="italic">pemilik</span>.
                </h1>
                <p class="font-serif text-base text-paper-soft leading-relaxed max-w-lg mt-6">
                    Ringkasan sistem dan proyek yang sedang dipantau Hermes pada
                    <span class="text-copper italic">{{ now()->locale('id')->translatedFormat('l, d F Y') }}</span>.
                </p>
            </div>
            <div class="font-mono text-[10px] tracking-[0.22em] uppercase text-paper-dim text-right hidden lg:block">
                <div>Sesi:</div>
                <div class="font-serif italic text-copper text-3xl mt-1" style="font-variation-settings: 'opsz' 72, 'wght' 300, 'WONK' 1;">{{ now()->setTimezone('Asia/Jakarta')->format('H:i') }}</div>
            </div>
        </div>
    </div>

    <!-- Stat Grid: 4 columns separated by hairlines -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-px bg-[color:var(--rule)] border border-[color:var(--rule)] mb-16 animate-fade-up-1">
        @foreach ([
            ['Tabel', $stats['tables'], '001', 'α'],
            ['Berkas', $stats['files'], '002', 'β'],
            ['Penyimpanan', $stats['storage_used'], '003', 'γ'],
            ['Proyek', $stats['projects'], '004', 'δ'],
        ] as $stat)
        <div class="bg-ink p-7 hover:bg-ink-soft transition-colors group">
            <div class="flex items-start justify-between mb-6">
                <span class="font-mono text-[9px] tracking-[0.22em] uppercase text-paper-dim">N° {{ $stat[2] }}</span>
                <span class="glyph text-2xl leading-none opacity-50 group-hover:opacity-100 transition-opacity">{{ $stat[3] }}</span>
            </div>
            <div class="font-serif text-[44px] leading-none text-paper italic mb-3" style="font-variation-settings: 'opsz' 144, 'wght' 300, 'WONK' 1; letter-spacing: -0.03em;">
                {{ is_numeric($stat[1]) ? number_format($stat[1]) : $stat[1] }}
            </div>
            <div class="font-mono text-[10px] tracking-[0.22em] uppercase text-paper-dim">{{ $stat[0] }}</div>
        </div>
        @endforeach
    </div>

    <!-- Quick Actions -->
    <section class="mb-16 animate-fade-up-2">
        <div class="flex items-center justify-between mb-8 pb-4 border-b border-[color:var(--rule)]">
            <h2 class="section-label">Tindakan Cepat</h2>
            <span class="font-mono text-[9px] tracking-[0.22em] uppercase text-paper-dim">/ 4 perintah</span>
        </div>
        <div class="flex flex-wrap gap-3">
            <button @click="cacheClear()" :disabled="loading" class="btn-ghost">
                <span class="font-serif italic text-base text-copper leading-none">⚡</span>
                <span x-text="loading ? 'Memproses…' : 'Bersihkan Cache'"></span>
            </button>
            <button @click="viewLogs()" class="btn-ghost">
                <span class="font-serif italic text-base text-copper leading-none">≡</span>
                Log Terbaru
            </button>
            <a href="{{ route('panel.files') }}" class="btn-ghost">
                <span class="font-serif italic text-base text-copper leading-none">▢</span>
                Manajer Berkas
            </a>
            <a href="{{ route('panel.database') }}" class="btn-ghost">
                <span class="font-serif italic text-base text-copper leading-none">◇</span>
                Basis Data
            </a>
        </div>
    </section>

    <!-- Projects Grid -->
    <section class="animate-fade-up-3">
        <div class="flex items-end justify-between mb-8 pb-4 border-b border-[color:var(--rule)]">
            <div>
                <h2 class="section-label mb-3">Proyek Terdaftar</h2>
                <h3 class="font-serif text-3xl text-paper" style="font-variation-settings: 'opsz' 144, 'wght' 500, 'WONK' 1;">
                    Semua proyek <span class="italic text-copper">Laravel</span>.
                </h3>
            </div>
            <span class="font-mono text-[10px] tracking-[0.22em] uppercase text-paper-dim">/ {{ count($allProjects) }} item</span>
        </div>

        @if(count($allProjects) > 0)
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-px bg-[color:var(--rule)] border border-[color:var(--rule)]">
            @foreach ($allProjects as $name => $project)
            @php $i = $loop->iteration; @endphp
            <article class="bg-ink p-7 hover:bg-ink-soft transition-colors group flex flex-col">
                <!-- Header -->
                <div class="flex items-start justify-between mb-6 pb-5 border-b border-[color:var(--rule)]">
                    <div class="flex-1 min-w-0">
                        <div class="font-mono text-[9px] tracking-[0.22em] uppercase text-paper-dim mb-2">N° {{ str_pad($i, 3, '0', STR_PAD_LEFT) }}</div>
                        <h3 class="font-serif text-2xl text-paper leading-tight truncate" style="font-variation-settings: 'opsz' 60, 'wght' 500, 'WONK' 1;">
                            {{ $project['display_name'] ?? $name }}
                        </h3>
                        <div class="font-mono text-[10px] tracking-wider uppercase text-paper-dim mt-1.5">{{ $name }}</div>
                    </div>
                    @if($project['manual'] ?? false)
                    <span class="font-mono text-[8px] tracking-[0.22em] uppercase border border-[color:var(--rule-strong)] px-2 py-1 text-paper-soft">Manual</span>
                    @else
                    <span class="font-mono text-[8px] tracking-[0.22em] uppercase border border-copper text-copper px-2 py-1">{{ strtoupper($project['type']) }}</span>
                    @endif
                </div>

                <!-- Status -->
                <div class="flex flex-wrap gap-1.5 mb-5">
                    @foreach ($project['status'] as $key => $active)
                    <span class="font-mono text-[8px] tracking-[0.18em] uppercase px-2 py-0.5 border
                                 {{ $active ? 'border-[color:var(--copper)]/40 text-copper' : 'border-[color:var(--rust)]/40 text-[color:var(--rust)] opacity-60' }}">
                        {{ str_replace('_', ' ', $key) }}
                    </span>
                    @endforeach
                </div>

                <!-- Metadata Table -->
                <dl class="grid grid-cols-2 gap-y-2 mb-auto font-mono text-[11px]">
                    <dt class="text-paper-dim tracking-wider uppercase text-[9px]">Laravel</dt>
                    <dd class="text-paper text-right">{{ $project['laravel_version'] }}</dd>

                    <dt class="text-paper-dim tracking-wider uppercase text-[9px]">PHP</dt>
                    <dd class="text-paper text-right">{{ $project['php_version'] }}</dd>

                    <dt class="text-paper-dim tracking-wider uppercase text-[9px]">Berkas</dt>
                    <dd class="text-paper text-right">{{ number_format($project['file_count']) }}</dd>

                    <dt class="text-paper-dim tracking-wider uppercase text-[9px]">Ukuran</dt>
                    <dd class="text-paper text-right">{{ $project['storage_used'] }}</dd>
                </dl>

                <!-- Action -->
                <button @click="switchProject('{{ $name }}')"
                        class="mt-6 pt-5 border-t border-[color:var(--rule)] font-mono text-[10px] tracking-[0.22em] uppercase text-paper-dim hover:text-copper transition-colors flex items-center justify-between group/btn">
                    <span>Buka proyek</span>
                    <span class="font-serif italic text-lg leading-none transition-transform group-hover/btn:translate-x-1">↗</span>
                </button>
            </article>
            @endforeach
        </div>
        @else
        <div class="text-center py-24 border border-[color:var(--rule)]">
            <div class="glyph text-6xl mb-6 opacity-50">∅</div>
            <p class="font-serif italic text-xl text-paper-soft mb-2">Belum ada proyek terdaftar.</p>
            <p class="font-mono text-[10px] tracking-[0.22em] uppercase text-paper-dim">
                Letakkan proyek di <code class="text-copper not-italic">{{ config('panel.projects_dir', 'Project') }}/</code>
            </p>
        </div>
        @endif
    </section>

    <!-- Logs Modal -->
    <div x-show="showLogs" x-cloak class="modal-overlay" @click.self="showLogs = false">
        <div class="modal-card" style="max-width: 800px;">
            <div class="modal-header">
                <h3 class="modal-title">Catatan <span class="italic">terbaru</span></h3>
                <button @click="showLogs = false" class="text-paper-dim hover:text-copper transition-colors text-xl leading-none">×</button>
            </div>
            <div class="modal-body max-h-[60vh] overflow-y-auto">
                <template x-for="(line, i) in logs" :key="i">
                    <div class="font-mono text-[11px] py-1 border-b border-[color:var(--rule)] last:border-0"
                         :class="{
                            'text-[color:var(--rust)]': line.includes('ERROR'),
                            'text-[color:var(--copper)]': line.includes('WARNING') && !line.includes('ERROR'),
                            'text-paper-soft': !line.includes('ERROR') && !line.includes('WARNING')
                         }"
                         x-text="line"></div>
                </template>
                <div x-show="logs.length === 0" class="font-serif italic text-paper-dim py-8 text-center">Tidak ada catatan terbaru.</div>
            </div>
        </div>
    </div>

</div>
@endsection

@push('scripts')
<script>
function dashboardApp() {
    return {
        loading: false,
        showLogs: false,
        logs: [],
        cacheClear() {
            this.loading = true;
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            fetch('{{ route("panel.api.cache-clear") }}', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf }
            }).then(r => r.json()).then(data => {
                this.loading = false;
                if (data.success) showToast('Cache dibersihkan', 'success');
                else showToast(data.error || 'Gagal', 'error');
            }).catch(() => { this.loading = false; showToast('Permintaan gagal', 'error'); });
        },
        viewLogs() {
            fetch('{{ route("panel.api.recent-logs") }}', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(r => r.json()).then(data => {
                if (data.success) { this.logs = data.logs; this.showLogs = true; }
            });
        },
        switchProject(name) { switchProject(name); }
    };
}
</script>
@endpush
