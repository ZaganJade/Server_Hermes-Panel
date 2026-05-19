@extends('panel.layout')

@section('title', 'Tools')
@section('section-label', 'Modul · N° 004')
@section('breadcrumb', 'Tools')

@section('content')
<div x-data="toolsApp({{ json_encode($suggestedCommands) }}, {{ json_encode($allProjects) }})">

    <!-- Toast Container -->
    <div x-show="toastMessage" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="fixed top-6 right-6 z-[100] px-5 py-3 font-mono text-[11px] tracking-wider uppercase text-ink shadow-lg"
         :class="toastType === 'success' ? 'bg-[#5a7a5a]' : toastType === 'error' ? 'bg-[#b85c44]' : 'bg-[#d4a45c]'"
         x-text="toastMessage" style="display: none;"></div>

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
        <button @click="activeTab = 'artisan'; loadSeeders()" class="tab-editorial" :class="activeTab === 'artisan' ? 'active' : ''">
            <span class="glyph text-base leading-none">α</span> Artisan
        </button>
        <button @click="activeTab = 'logs'; loadLogs()" class="tab-editorial" :class="activeTab === 'logs' ? 'active' : ''">
            <span class="glyph text-base leading-none">β</span> Catatan
        </button>
        <button @click="activeTab = 'artisan'; artisanCommand = 'db:seed'; loadSeeders()" class="tab-editorial" :class="activeTab === 'artisan' && artisanCommand === 'db:seed' ? 'active' : ''">
            <span class="glyph text-base leading-none">γ</span> Seeder
        </button>
        <button @click="activeTab = 'queue'; loadQueueStatus()" class="tab-editorial" :class="activeTab === 'antrian' ? 'active' : ''">
            <span class="glyph text-base leading-none">⚙</span> Antrian
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
                <select x-model="artisanCommand" @change="artisanCommand === 'db:seed' ? loadSeeders() : null" class="select-editorial">
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

        <!-- Seeder Panel -->
        <div x-show="artisanCommand === 'db:seed'" x-cloak class="border border-[color:var(--rule)] mb-6">
            <div class="flex items-center justify-between px-5 py-4 border-b border-[color:var(--rule)] bg-ink-soft">
                <div>
                    <div class="section-label">Seeder</div>
                    <p class="font-mono text-[10px] text-paper-dim tracking-wide mt-0.5">Jalankan seeder pada proyek aktif</p>
                </div>
                <button @click="loadSeeders()" class="font-mono text-[9px] tracking-[0.22em] uppercase text-paper-dim hover:text-copper transition-colors">
                    ⟳ Refresh
                </button>
            </div>
            <div class="p-5 bg-ink">
                <div class="grid grid-cols-1 sm:grid-cols-[1fr_auto] gap-3 items-end">
                    <div>
                        <label class="label-mono">Pilih Seeder</label>
                        <select x-model="selectedSeeder" class="select-editorial">
                            <option value="">— Semua Seeder (DatabaseSeeder) —</option>
                            <template x-for="seeder in seeders" :key="seeder.class">
                                <option :value="seeder.class" x-text="seeder.file"></option>
                            </template>
                        </select>
                        <p class="font-mono text-[9px] text-paper-dim mt-1" x-show="seeders.length > 0" x-text="seeders.length + ' seeder tersedia'"></p>
                        <p class="font-mono text-[9px] text-rust mt-1" x-show="seedersError" x-text="seedersError"></p>
                    </div>
                    <button @click="runSeeder()" :disabled="seederRunning" class="btn-copper" :class="{ 'disabled': seederRunning }">
                        <span x-text="seederRunning ? 'Menjalankan…' : 'Jalankan Seeder'"></span>
                        <span class="font-serif italic" x-show="!seederRunning">↗</span>
                    </button>
                </div>
                <div x-show="seederOutput" class="mt-4">
                    <pre class="bg-ink-2 p-4 font-mono text-[11px] text-paper-soft whitespace-pre-wrap max-h-[200px] overflow-y-auto leading-relaxed border border-[color:var(--rule)]" x-text="seederOutput"></pre>
                </div>
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

    <!-- Antrian Tab (Queue Monitor) -->
    <div x-show="activeTab === 'antrian'" x-cloak class="animate-fade-up-2">

        <!-- Queue Status Card -->
        <div class="grid grid-cols-1 sm:grid-cols-[1fr_auto] gap-6 items-end mb-8 pb-6 border-b border-[color:var(--rule)]">
            <div>
                <div class="section-label mb-3">⚙ Antrian</div>
                <h2 class="font-serif text-3xl text-paper" style="font-variation-settings: 'opsz' 60, 'wght' 400, 'WONK' 1;">
                    Pemroses <span class="italic text-copper">Tugas</span>
                </h2>
            </div>
            <div class="flex flex-wrap gap-2">
                <button @click="dispatchCleanup()" :disabled="dispatchRunning" class="btn-copper" :class="{ 'disabled': dispatchRunning }">
                    <span x-text="dispatchRunning ? 'Menjalankan…' : 'Jalankan Sekarang'"></span>
                    <span class="font-serif italic" x-show="!dispatchRunning">↗</span>
                </button>
                <button @click="flushQueue()" class="btn-mini" :class="{ 'disabled': flushRunning }">
                    ↯ Flush Semua
                </button>
                <button @click="loadQueueStatus()" class="btn-mini">⟳ Refresh</button>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-8">
            <div class="border border-[color:var(--rule)] p-5 bg-ink">
                <div class="font-mono text-[9px] tracking-[0.22em] uppercase text-paper-dim mb-2">Driver</div>
                <div class="font-serif text-2xl text-copper" x-text="queueStats.driver || 'Database'"></div>
            </div>
            <div class="border border-[color:var(--rule)] p-5 bg-ink">
                <div class="font-mono text-[9px] tracking-[0.22em] uppercase text-paper-dim mb-2">Connection</div>
                <div class="font-serif text-2xl text-paper" x-text="queueStats.connection || 'default'"></div>
            </div>
            <div class="border border-[color:var(--rule)] p-5 bg-ink">
                <div class="font-mono text-[9px] tracking-[0.22em] uppercase text-paper-dim mb-2">Status</div>
                <div class="flex items-center gap-2">
                    <span class="w-2.5 h-2.5 rounded-full" :class="queueStats.workers > 0 ? 'bg-[#5a7a5a]' : 'bg-[#b85c44]'"></span>
                    <span class="font-serif text-2xl" :class="queueStats.workers > 0 ? 'text-[#5a7a5a]' : 'text-[#b85c44]'" x-text="queueStats.workers > 0 ? 'Running' : 'Stopped'"></span>
                </div>
            </div>
            <div class="border border-[color:var(--rule)] p-5 bg-ink">
                <div class="font-mono text-[9px] tracking-[0.22em] uppercase text-paper-dim mb-2">Jobs Hari Ini</div>
                <div class="font-serif text-2xl text-paper" x-text="queueStats.jobs_today || 0"></div>
            </div>
        </div>

        <!-- Worker Info (if running) -->
        <div x-show="queueStats.workers > 0" class="mb-8 p-5 border border-[color:var(--verdigris)] bg-[color:var(--verdigris)]/10">
            <div class="flex items-center gap-3">
                <span class="font-serif italic text-xl text-[#5a7a5a]">●</span>
                <div>
                    <div class="font-mono text-[10px] tracking-[0.22em] uppercase text-paper-dim mb-1">Worker aktif</div>
                    <div class="font-mono text-[11px] text-paper" x-text="`PID: ${queueStats.pid || '—'} · Menunggu job`"></div>
                </div>
            </div>
        </div>

        <!-- Failed Jobs Table -->
        <div class="border border-[color:var(--rule)] overflow-x-auto mb-6">
            <table class="table-editorial">
                <thead>
                    <tr>
                        <th>Job ID</th>
                        <th>Nama</th>
                        <th>Antrian</th>
                        <th>Status</th>
                        <th>Gagal Pada</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="job in failedJobs" :key="job.id">
                        <tr>
                            <td class="text-paper font-mono text-[11px]" x-text="'#' + job.id"></td>
                            <td class="text-paper-soft" x-text="job.payload ? JSON.parse(job.payload).displayName : 'Unknown'"></td>
                            <td class="text-paper-dim text-[10px]" x-text="job.queue || 'default'"></td>
                            <td>
                                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 text-[9px] font-mono tracking-[0.15em] uppercase border"
                                      :class="job.failed_at ? 'border-[#b85c44] text-[#b85c44]' : 'border-[#c8a04a] text-[#c8a04a]'">
                                    <span class="w-1.5 h-1.5 rounded-full" :class="job.failed_at ? 'bg-[#b85c44]' : 'bg-[#c8a04a]'"></span>
                                    <span x-text="job.failed_at ? 'Failed' : 'Pending'"></span>
                                </span>
                            </td>
                            <td class="text-paper-dim text-[10px]" x-text="job.failed_at ? formatDate(job.failed_at) : '—'"></td>
                            <td class="text-right">
                                <button x-show="job.failed_at" @click="retryJob(job.id)" class="font-mono text-[9px] tracking-[0.22em] uppercase text-paper-dim hover:text-copper transition-colors">⟳ Coba Lagi</button>
                                <button @click="forgetJob(job.id)" class="font-mono text-[9px] tracking-[0.22em] uppercase text-paper-dim hover:text-[#b85c44] transition-colors ml-3">✕ Hapus</button>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="failedJobs.length === 0">
                        <td colspan="6" class="text-center py-12 font-serif italic text-paper-dim">
                            <span x-show="queueStats.workers > 0">Tidak ada pekerjaan gagal.</span>
                            <span x-show="queueStats.workers === 0">Worker tidak aktif. Jalankan <code class="text-copper">php artisan queue:work</code> dulu.</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Recent Jobs -->
        <div class="border border-[color:var(--rule)] overflow-x-auto">
            <div class="flex items-center justify-between px-5 py-3 border-b border-[color:var(--rule)] bg-ink-soft">
                <span class="section-label">Pekerjaan Terakhir</span>
                <span class="font-mono text-[9px] tracking-[0.22em] uppercase text-paper-dim" x-text="recentJobs.length + ' pekerjaan'"></span>
            </div>
            <table class="table-editorial">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nama</th>
                        <th>Status</th>
                        <th>Waktu</th>
                        <th>Diaktifkan</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(job, i) in recentJobs" :key="i">
                        <tr>
                            <td class="font-mono text-[10px] text-paper-dim" x-text="'#' + (i + 1)"></td>
                            <td class="text-paper" x-text="job.name || 'CleanupDatabaseTrash'"></td>
                            <td>
                                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 text-[9px] font-mono tracking-[0.15em] uppercase border"
                                      :class="job.status === 'completed' ? 'border-[#5a7a5a] text-[#5a7a5a]' : job.status === 'failed' ? 'border-[#b85c44] text-[#b85c44]' : 'border-[#c8a04a] text-[#c8a04a]'">
                                    <span class="w-1.5 h-1.5 rounded-full" :class="job.status === 'completed' ? 'bg-[#5a7a5a]' : job.status === 'failed' ? 'bg-[#b85c44]' : 'bg-[#c8a04a]'"></span>
                                    <span x-text="job.status || 'pending'"></span>
                                </span>
                            </td>
                            <td class="text-paper-soft text-[10px]" x-text="job.runtime ? job.runtime + 's' : '—'"></td>
                            <td class="text-paper-dim text-[10px]" x-text="job.created_at ? formatDate(job.created_at) : '—'"></td>
                        </tr>
                    </template>
                    <tr x-show="recentJobs.length === 0">
                        <td colspan="5" class="text-center py-8 font-serif italic text-paper-dim">Belum ada pekerjaan.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Dispatch output -->
        <div x-show="dispatchOutput" class="mt-6 border border-[color:var(--rule)]">
            <div class="flex items-center justify-between px-5 py-3 border-b border-[color:var(--rule)] bg-ink-soft">
                <span class="section-label">Keluaran Dispatch</span>
                <button @click="dispatchOutput = ''" class="font-mono text-[9px] tracking-[0.22em] uppercase text-paper-dim hover:text-copper transition-colors">Bersihkan ↗</button>
            </div>
            <pre class="bg-ink p-5 font-mono text-[11px] text-paper-soft whitespace-pre-wrap max-h-[200px] overflow-y-auto leading-relaxed" x-text="dispatchOutput"></pre>
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
        seeders: [], selectedSeeder: '', seederRunning: false, seederOutput: '', seedersError: '',
        logs: [], logFilter: 'all', logSearch: '', autoRefresh: false, autoRefreshTimer: null,
        failedJobs: [], recentJobs: [], queueStats: {},
        dispatchRunning: false, flushRunning: false, dispatchOutput: '',
        composerProject: '', composerRunning: false, npmRunning: false, composerOutput: '', npmOutput: '',
        csrf: document.querySelector('meta[name="csrf-token"]')?.content || '',

        // Toast
        toastMessage: '',
        toastType: 'success',
        toastTimeout: null,

        showToast(message, type = 'success') {
            this.toastMessage = message;
            this.toastType = type;
            if (this.toastTimeout) clearTimeout(this.toastTimeout);
            this.toastTimeout = setTimeout(() => { this.toastMessage = ''; }, 3000);
        },

        formatDate(val) {
            if (!val) return '—';
            try {
                return new Date(val).toLocaleString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
            } catch(e) { return val; }
        },

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
                if (!data.success) this.showToast('Perintah gagal', 'error');
                else this.showToast('Selesai');
            } catch(e) { this.artisanOutput = 'Permintaan gagal'; }
            this.artisanRunning = false;
        },

        async loadSeeders() {
            this.seedersError = '';
            try {
                const res = await fetch('{{ route("panel.api.seeders") }}', {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const data = await res.json();
                if (data.success) {
                    this.seeders = data.seeders || [];
                } else {
                    this.seedersError = data.error || 'Gagal memuat seeder';
                }
            } catch(e) {
                this.seedersError = 'Permintaan gagal';
                this.seeders = [];
            }
        },

        async runSeeder() {
            this.seederRunning = true; this.seederOutput = '';
            try {
                const res = await fetch('{{ route("panel.api.db-seed") }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrf },
                    body: JSON.stringify({ seeder: this.selectedSeeder })
                });
                const data = await res.json();
                this.seederOutput = (data.output || '') + (data.error ? '\n' + data.error : '');
                if (data.success) this.showToast('Seeder berhasil dijalankan');
                else this.showToast('Seeder gagal', 'error');
            } catch(e) { this.seederOutput = 'Permintaan gagal'; }
            this.seederRunning = false;
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
            this.logs = []; this.showToast('Catatan dibersihkan');
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
                this.queueStats = data.queue_stats || {};
                this.recentJobs = data.recent_jobs || [];
            } catch(e) {
                this.failedJobs = [];
                this.queueStats = {};
                this.recentJobs = [];
            }
        },

        async retryJob(id) {
            await fetch(`{{ route("panel.api.queue-retry", ["id" => "__I__"]) }}`.replace('__I__', id), {
                method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrf }
            });
            this.loadQueueStatus(); this.showToast('Pekerjaan dijadwalkan ulang');
        },

        async forgetJob(id) {
            await fetch(`{{ route("panel.api.queue-forget", ["id" => "__I__"]) }}`.replace('__I__', id), {
                method: 'DELETE', headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrf }
            });
            this.loadQueueStatus(); this.showToast('Pekerjaan dihapus');
        },

        async dispatchCleanup() {
            this.dispatchRunning = true; this.dispatchOutput = '';
            try {
                const res = await fetch('{{ route("panel.api.queue-dispatch-cleanup") }}', {
                    method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrf }
                });
                const data = await res.json();
                this.dispatchOutput = (data.output || '') + (data.error ? '\n' + data.error : '');
                if (data.success) this.showToast('Cleanup job dispatched');
                else this.showToast(data.error || 'Gagal', 'error');
            } catch(e) { this.dispatchOutput = 'Permintaan gagal'; }
            this.dispatchRunning = false;
            this.loadQueueStatus();
        },

        async flushQueue() {
            if (!confirm('Flush semua failed jobs?')) return;
            this.flushRunning = true;
            await fetch('{{ route("panel.api.queue-flush") }}', {
                method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrf }
            });
            this.flushRunning = false;
            this.loadQueueStatus(); this.showToast('Queue flushed');
        },

        async queueAction(action) {
            const route = action === 'restart' ? '{{ route("panel.api.queue-restart") }}' : '{{ route("panel.api.queue-flush") }}';
            await fetch(route, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrf } });
            this.loadQueueStatus(); this.showToast(`Antrian: ${action} berhasil`);
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