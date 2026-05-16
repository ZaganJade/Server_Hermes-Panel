@extends('panel.layout')

@section('title', 'Tools')
@section('section-label', 'Modul · N° 004')
@section('breadcrumb', 'Tools')

@section('content')
<div x-data="toolsApp({{ json_encode($suggestedCommands) }}, {{ json_encode($allProjects) }})">

    <!-- Editorial Header -->
    <div class="mb-12 animate-fade-up">
        <div class="grid lg:grid-cols-[1fr_auto] gap-8 items-end pb-8 border-b border-[color:var(--rule)]">
            <div>
                <div class="section-label mb-6">Peralatan Laravel</div>
                <h1 class="title-editorial">
                    Artisan,<br>
                    <span class="italic">Composer</span>, dan kawan.
                </h1>
                <p class="font-serif text-base text-paper-soft leading-relaxed max-w-lg mt-6">
                    Jalankan perintah, pantau log, kelola antrian — tanpa membuka SSH.
                </p>
            </div>
        </div>
    </div>

    @if(!$activeProject)
    <div class="text-center py-24 border border-[color:var(--rule)] animate-fade-up-1">
        <div class="glyph text-6xl mb-6 opacity-50">∅</div>
        <p class="font-serif italic text-xl text-paper-soft mb-2">Tidak ada proyek aktif.</p>
        <p class="font-mono text-[10px] tracking-[0.22em] uppercase text-paper-dim">Pilih proyek di sidebar untuk membuka peralatan.</p>
    </div>
    @else

    <!-- Tabs -->
    <div class="tabs-editorial animate-fade-up-1">
        <button @click="activeTab = 'artisan'" class="tab-editorial" :class="activeTab === 'artisan' ? 'active' : ''">
            <span class="glyph text-base leading-none">α</span> Artisan
        </button>
        <button @click="activeTab = 'logs'; loadLogs()" class="tab-editorial" :class="activeTab === 'logs' ? 'active' : ''">
            <span class="glyph text-base leading-none">β</span> Catatan
        </button>
        <button @click="activeTab = 'queue'; loadQueueStatus()" class="tab-editorial" :class="activeTab === 'queue' ? 'active' : ''">
            <span class="glyph text-base leading-none">γ</span> Antrian
        </button>
        <button @click="activeTab = 'composer'" class="tab-editorial" :class="activeTab === 'composer' ? 'active' : ''">
            <span class="glyph text-base leading-none">δ</span> Composer & NPM
        </button>
    </div>

    <!-- Artisan Tab -->
    <div x-show="activeTab === 'artisan'" x-cloak class="animate-fade-up-2">
        <div class="grid grid-cols-1 lg:grid-cols-[1fr_240px_auto] gap-3 mb-6">
            <div>
                <label class="label-mono">Perintah</label>
                <select x-model="artisanCommand" class="select-editorial">
                    <option value="">— Pilih perintah —</option>
                    <template x-for="cmd in suggestedCommands" :key="cmd">
                        <option :value="cmd" x-text="cmd"></option>
                    </template>
                </select>
            </div>
            <div>
                <label class="label-mono">Opsi</label>
                <input type="text" x-model="artisanOptions" placeholder="--seed --force" class="input-editorial">
            </div>
            <div class="flex items-end">
                <button @click="runArtisan()" :disabled="!artisanCommand || artisanRunning" class="btn-copper" :class="{ 'disabled': !artisanCommand || artisanRunning }">
                    <span x-text="artisanRunning ? 'Menjalankan…' : 'Eksekusi'"></span>
                    <span class="font-serif italic" x-show="!artisanRunning">↗</span>
                </button>
            </div>
        </div>

        <div x-show="artisanOutput" class="border border-[color:var(--rule)]">
            <div class="flex items-center justify-between px-5 py-3 border-b border-[color:var(--rule)] bg-ink-soft">
                <span class="section-label">Keluaran</span>
                <button @click="artisanOutput = ''" class="font-mono text-[9px] tracking-[0.22em] uppercase text-paper-dim hover:text-copper transition-colors">Bersihkan ↗</button>
            </div>
            <pre class="bg-ink p-5 font-mono text-[11px] text-paper-soft whitespace-pre-wrap max-h-[320px] md:max-h-[480px] overflow-y-auto leading-relaxed" x-text="artisanOutput"></pre>
        </div>
    </div>

    <!-- Logs Tab -->
    <div x-show="activeTab === 'logs'" x-cloak class="animate-fade-up-2">
        <div class="grid grid-cols-1 sm:grid-cols-[180px_1fr_auto_auto] gap-3 mb-6 items-end">
            <div>
                <label class="label-mono">Tingkat</label>
                <select x-model="logFilter" @change="loadLogs()" class="select-editorial">
                    <option value="all">Semua</option>
                    <option value="error">Error</option>
                    <option value="warning">Warning</option>
                    <option value="info">Info</option>
                    <option value="debug">Debug</option>
                </select>
            </div>
            <div>
                <label class="label-mono">Pencarian</label>
                <input type="text" x-model="logSearch" @input="loadLogs()" placeholder="Cari..." class="input-editorial">
            </div>
            <label class="font-mono text-[10px] tracking-[0.22em] uppercase text-paper-dim flex items-center gap-2 pb-3">
                <input type="checkbox" x-model="autoRefresh" @change="toggleAutoRefresh()" style="accent-color: var(--copper);">
                Auto · 5s
            </label>
            <button @click="clearLogs()" class="btn-danger pb-3" style="padding: 12px 18px;">Bersihkan</button>
        </div>

        <div class="border border-[color:var(--rule)] bg-ink">
            <div class="flex items-center justify-between px-5 py-3 border-b border-[color:var(--rule)] bg-ink-soft">
                <span class="section-label">Catatan Laravel</span>
                <span class="font-mono text-[9px] tracking-[0.22em] uppercase text-paper-dim" x-text="`${logs.length} baris`"></span>
            </div>
            <div class="p-5 font-mono text-[11px] max-h-[320px] md:max-h-[560px] overflow-y-auto leading-relaxed">
                <template x-for="(line, i) in logs" :key="i">
                    <div class="py-0.5 border-b border-[color:var(--rule)] last:border-0"
                         :class="{
                            'text-[color:var(--rust)]': line.includes('ERROR') || line.includes('[ERROR]'),
                            'text-[color:#c8a04a]': (line.includes('WARNING') || line.includes('[WARNING]')) && !line.includes('ERROR'),
                            'text-[color:var(--copper)]': line.includes('INFO') && !line.includes('ERROR') && !line.includes('WARNING'),
                            'text-paper-soft': !line.includes('ERROR') && !line.includes('WARNING') && !line.includes('INFO')
                         }"
                         x-text="line"></div>
                </template>
                <div x-show="logs.length === 0" class="font-serif italic text-paper-dim py-6 text-center">Tidak ada catatan.</div>
            </div>
            <div x-show="logs.length > 0" class="px-5 py-3 border-t border-[color:var(--rule)] bg-ink-soft">
                <button @click="loadMoreLogs()" class="btn-mini">Muat lebih banyak →</button>
            </div>
        </div>
    </div>

    <!-- Queue Tab -->
    <div x-show="activeTab === 'queue'" x-cloak class="animate-fade-up-2">
        <div class="grid grid-cols-1 sm:grid-cols-[1fr_auto] gap-6 items-end mb-6 pb-4 border-b border-[color:var(--rule)]">
            <div>
                <div class="section-label mb-3">Antrian</div>
                <div class="font-serif text-3xl text-paper" style="font-variation-settings: 'opsz' 144, 'wght' 500, 'WONK' 1;">
                    Pekerjaan <span class="italic text-copper">gagal</span>:
                    <span class="font-mono text-3xl text-copper" x-text="failedCount"></span>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <button @click="queueAction('restart')" class="btn-mini">⟲ Restart Worker</button>
                <button @click="queueAction('flush')" class="btn-mini">↯ Flush Failed</button>
            </div>
        </div>

        <div class="border border-[color:var(--rule)] overflow-x-auto">
            <table class="table-editorial">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Antrian</th>
                        <th>Gagal Pada</th>
                        <th>Pengecualian</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="job in failedJobs" :key="job.id">
                        <tr>
                            <td class="text-paper" x-text="job.id"></td>
                            <td class="text-paper-soft" x-text="job.queue"></td>
                            <td class="text-paper-dim text-[10px]" x-text="job.failed_at"></td>
                            <td class="text-[color:var(--rust)]/80 text-[10px] truncate max-w-[400px]" :title="job.exception" x-text="job.exception"></td>
                            <td class="text-right">
                                <button @click="retryJob(job.id)" class="font-mono text-[9px] tracking-[0.22em] uppercase text-paper-dim hover:text-copper transition-colors">Coba Lagi ↗</button>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="failedJobs.length === 0">
                        <td colspan="5" class="text-center py-12 font-serif italic text-paper-dim">Tidak ada pekerjaan gagal.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Composer & NPM Tab -->
    <div x-show="activeTab === 'composer'" x-cloak class="animate-fade-up-2">
        <div class="mb-8">
            <label class="label-mono">Jalankan pada proyek</label>
            <select x-model="composerProject" class="select-editorial" style="max-width: 320px;">
                <option value="">— Proyek aktif —</option>
                <template x-for="(proj, key) in projects" :key="key">
                    <option :value="key" x-text="proj.display_name || key"></option>
                </template>
            </select>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-px bg-[color:var(--rule)] border border-[color:var(--rule)] mb-8">

            <!-- Composer -->
            <div class="bg-ink p-7">
                <div class="flex items-center justify-between mb-5 pb-4 border-b border-[color:var(--rule)]">
                    <div>
                        <div class="font-mono text-[9px] tracking-[0.22em] uppercase text-paper-dim mb-1">N° 001</div>
                        <h3 class="font-serif text-2xl text-paper" style="font-variation-settings: 'opsz' 60, 'wght' 500, 'WONK' 1;">
                            Compo<span class="italic text-copper">ser</span>
                        </h3>
                    </div>
                    <span class="glyph text-3xl leading-none">α</span>
                </div>
                <div class="space-y-2">
                    <button @click="runComposer('install')" :disabled="composerRunning" class="btn-ghost w-full justify-between" :class="{ 'disabled': composerRunning }">
                        <span>Install</span>
                        <span class="font-serif italic">↓</span>
                    </button>
                    <button @click="runComposer('update')" :disabled="composerRunning" class="btn-ghost w-full justify-between" :class="{ 'disabled': composerRunning }">
                        <span>Update</span>
                        <span class="font-serif italic">⟳</span>
                    </button>
                    <button @click="runComposer('dump-autoload')" :disabled="composerRunning" class="btn-ghost w-full justify-between" :class="{ 'disabled': composerRunning }">
                        <span>Dump Autoload</span>
                        <span class="font-serif italic">⊡</span>
                    </button>
                </div>
            </div>

            <!-- NPM -->
            <div class="bg-ink p-7">
                <div class="flex items-center justify-between mb-5 pb-4 border-b border-[color:var(--rule)]">
                    <div>
                        <div class="font-mono text-[9px] tracking-[0.22em] uppercase text-paper-dim mb-1">N° 002</div>
                        <h3 class="font-serif text-2xl text-paper" style="font-variation-settings: 'opsz' 60, 'wght' 500, 'WONK' 1;">
                            <span class="italic text-copper">NPM</span>
                        </h3>
                    </div>
                    <span class="glyph text-3xl leading-none">β</span>
                </div>
                <div class="space-y-2">
                    <button @click="runNpm('install')" :disabled="npmRunning" class="btn-ghost w-full justify-between" :class="{ 'disabled': npmRunning }">
                        <span>Install</span>
                        <span class="font-serif italic">↓</span>
                    </button>
                    <button @click="runNpm('run build')" :disabled="npmRunning" class="btn-ghost w-full justify-between" :class="{ 'disabled': npmRunning }">
                        <span>Build</span>
                        <span class="font-serif italic">▲</span>
                    </button>
                    <button @click="runNpm('run dev')" :disabled="npmRunning" class="btn-ghost w-full justify-between" :class="{ 'disabled': npmRunning }">
                        <span>Dev</span>
                        <span class="font-serif italic">»</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Output -->
        <div x-show="composerOutput || npmOutput" class="border border-[color:var(--rule)]">
            <div class="flex items-center justify-between px-5 py-3 border-b border-[color:var(--rule)] bg-ink-soft">
                <span class="section-label">Keluaran</span>
                <button @click="composerOutput = ''; npmOutput = ''" class="font-mono text-[9px] tracking-[0.22em] uppercase text-paper-dim hover:text-copper transition-colors">Bersihkan ↗</button>
            </div>
            <pre class="bg-ink p-5 font-mono text-[11px] text-paper-soft whitespace-pre-wrap max-h-[320px] md:max-h-[480px] overflow-y-auto leading-relaxed" x-text="composerOutput || npmOutput"></pre>
        </div>
    </div>

    @endif

</div>
@endsection

@push('scripts')
<script>
function toolsApp(suggestedCommands, projects) {
    return {
        suggestedCommands, projects,
        activeTab: 'artisan',
        artisanCommand: '', artisanOptions: '', artisanRunning: false, artisanOutput: '',
        logs: [], logFilter: 'all', logSearch: '', autoRefresh: false, autoRefreshTimer: null,
        failedJobs: [], failedCount: 0,
        composerProject: '', composerRunning: false, npmRunning: false, composerOutput: '', npmOutput: '',
        csrf: document.querySelector('meta[name="csrf-token"]')?.content || '',

        async runArtisan() {
            this.artisanRunning = true; this.artisanOutput = '';
            const projectPath = this.composerProject ? (this.projects[this.composerProject]?.path || '') : '';
            try {
                const res = await fetch('{{ route("panel.api.artisan") }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrf },
                    body: JSON.stringify({ command: this.artisanCommand + (this.artisanOptions ? ' ' + this.artisanOptions : ''), project_path: projectPath })
                });
                const data = await res.json();
                this.artisanOutput = (data.output || '') + (data.error ? '\n' + data.error : '');
                if (!data.success) showToast('Perintah gagal', 'error');
                else showToast('Selesai');
            } catch(e) { this.artisanOutput = 'Permintaan gagal'; }
            this.artisanRunning = false;
        },

        async loadLogs(offset = 0) {
            try {
                const params = new URLSearchParams({ filter: this.logFilter, search: this.logSearch, lines: 100, offset });
                const res = await fetch(`{{ route("panel.api.logs") }}?${params}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                const data = await res.json();
                this.logs = data.logs || [];
            } catch(e) { this.logs = []; }
        },

        loadMoreLogs() { this.loadLogs(this.logs.length); },

        async clearLogs() {
            if (!confirm('Bersihkan semua catatan?')) return;
            await fetch('{{ route("panel.api.logs-clear") }}', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrf } });
            this.logs = []; showToast('Catatan dibersihkan');
        },

        toggleAutoRefresh() {
            if (this.autoRefresh) this.autoRefreshTimer = setInterval(() => this.loadLogs(), 5000);
            else clearInterval(this.autoRefreshTimer);
        },

        async loadQueueStatus() {
            try {
                const res = await fetch('{{ route("panel.api.queue-status") }}', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                const data = await res.json();
                this.failedJobs = data.failed_jobs || [];
                this.failedCount = data.failed_count || 0;
            } catch(e) { this.failedJobs = []; this.failedCount = 0; }
        },

        async retryJob(id) {
            await fetch(`{{ route("panel.api.queue-retry", ["id" => "__I__"]) }}`.replace('__I__', id), {
                method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrf }
            });
            this.loadQueueStatus(); showToast('Pekerjaan dijadwalkan ulang');
        },

        async queueAction(action) {
            const route = action === 'restart' ? '{{ route("panel.api.queue-restart") }}' : '{{ route("panel.api.queue-flush") }}';
            await fetch(route, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrf } });
            this.loadQueueStatus(); showToast(`Antrian: ${action} berhasil`);
        },

        async runComposer(command) {
            this.composerRunning = true; this.composerOutput = '';
            const projectPath = this.composerProject ? (this.projects[this.composerProject]?.path || '') : '';
            try {
                const res = await fetch('{{ route("panel.api.composer") }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrf },
                    body: JSON.stringify({ command, project_path: projectPath })
                });
                const data = await res.json();
                this.composerOutput = (data.output || '') + (data.error ? '\n' + data.error : '');
            } catch(e) { this.composerOutput = 'Permintaan gagal'; }
            this.composerRunning = false;
        },

        async runNpm(command) {
            this.npmRunning = true; this.npmOutput = '';
            const projectPath = this.composerProject ? (this.projects[this.composerProject]?.path || '') : '';
            try {
                const res = await fetch('{{ route("panel.api.npm") }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrf },
                    body: JSON.stringify({ command, project_path: projectPath })
                });
                const data = await res.json();
                this.npmOutput = (data.output || '') + (data.error ? '\n' + data.error : '');
            } catch(e) { this.npmOutput = 'Permintaan gagal'; }
            this.npmRunning = false;
        }
    };
}
</script>
@endpush
