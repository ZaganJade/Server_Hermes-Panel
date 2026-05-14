@extends('panel.layout')

@section('title', 'Database')
@section('section-label', 'Modul · N° 002')
@section('breadcrumb', 'Database')

@section('content')
<div x-data="dbApp({{ json_encode($connections) }}, {{ json_encode(session('query_history', [])) }})">

    <!-- Editorial Header -->
    <div class="mb-12 animate-fade-up">
        <div class="grid lg:grid-cols-[1fr_auto] gap-8 items-end pb-8 border-b border-[color:var(--rule)]">
            <div>
                <div class="section-label mb-6">Manajer Basis Data</div>
                <h1 class="title-editorial">
                    Tabel, baris,<br>
                    <span class="italic">kueri</span>.
                </h1>
                <p class="font-serif text-base text-paper-soft leading-relaxed max-w-lg mt-6">
                    Jelajahi struktur, edit data, atau tulis SQL bebas — semuanya pada koneksi yang dikenali Hermes dari
                    <span class="italic text-copper">.env</span> proyek aktif.
                </p>
            </div>
        </div>
    </div>

    @if(empty($connections))
    <div class="text-center py-24 border border-[color:var(--rule)] animate-fade-up-1">
        <div class="glyph text-6xl mb-6 opacity-50">∅</div>
        <p class="font-serif italic text-xl text-paper-soft mb-2">Tidak ada koneksi terkonfigurasi.</p>
        <p class="font-mono text-[10px] tracking-[0.22em] uppercase text-paper-dim">Pastikan proyek aktif punya <code class="text-copper">DB_*</code> di <code class="text-copper">.env</code></p>
    </div>
    @else

    <!-- Connection Selector -->
    <div class="flex flex-wrap items-center gap-6 mb-10 animate-fade-up-1">
        <span class="label-mono mb-0">Koneksi</span>
        <select x-model="activeConnection" @change="loadTables()" class="select-editorial" style="max-width: 280px;">
            <template x-for="(conn, key) in connections" :key="key">
                <option :value="key" x-text="conn.name"></option>
            </template>
        </select>
        <span class="font-mono text-[10px] tracking-[0.22em] uppercase text-paper-dim flex items-center gap-2">
            <span class="pulse-dot"></span>
            <span x-text="`${tables.length} tabel`"></span>
        </span>
    </div>

    <!-- Tabs -->
    <div class="tabs-editorial animate-fade-up-2">
        <button @click="activeTab = 'tables'" class="tab-editorial" :class="activeTab === 'tables' ? 'active' : ''">
            <span class="glyph text-base leading-none">α</span>
            <span>Tabel</span>
        </button>
        <button @click="activeTab = 'browse'" class="tab-editorial" :class="activeTab === 'browse' ? 'active' : ''" :disabled="!selectedTable">
            <span class="glyph text-base leading-none">β</span>
            <span>Jelajahi</span>
        </button>
        <button @click="activeTab = 'editor'" class="tab-editorial" :class="activeTab === 'editor' ? 'active' : ''">
            <span class="glyph text-base leading-none">γ</span>
            <span>SQL Editor</span>
        </button>
    </div>

    <!-- Tables Tab -->
    <div x-show="activeTab === 'tables'" x-cloak>
        <div x-show="loadingTables" class="font-mono text-[11px] tracking-[0.22em] uppercase text-paper-dim py-12 text-center">
            <span class="font-serif italic text-paper-soft">Memuat tabel</span> ...
        </div>
        <div x-show="!loadingTables" class="border border-[color:var(--rule)] overflow-x-auto">
            <table class="table-editorial">
                <thead>
                    <tr>
                        <th>Nama Tabel</th>
                        <th>Baris</th>
                        <th>Ukuran</th>
                        <th>Engine</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(table, i) in tables" :key="table.name">
                        <tr @click="selectedTable = table.name; activeTab = 'browse'; loadTableData()" class="cursor-pointer">
                            <td>
                                <span class="font-mono text-[9px] text-paper-dim tracking-wider mr-3" x-text="`N°${String(i+1).padStart(3,'0')}`"></span>
                                <span class="text-paper" x-text="table.name"></span>
                            </td>
                            <td class="text-paper-soft" x-text="Number(table.rows).toLocaleString()"></td>
                            <td class="text-paper-soft" x-text="table.size"></td>
                            <td class="text-paper-dim text-[10px]" x-text="table.engine"></td>
                            <td class="text-right">
                                <button @click.stop="exportTable(table.name, 'json')" class="font-mono text-[9px] tracking-[0.2em] uppercase text-paper-dim hover:text-copper transition-colors" title="Export JSON">
                                    Export ↗
                                </button>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="!loadingTables && tables.length === 0">
                        <td colspan="5" class="text-center py-12 font-serif italic text-paper-dim">Tidak ada tabel.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Browse Data Tab -->
    <div x-show="activeTab === 'browse'" x-cloak>
        <div x-show="!selectedTable" class="font-serif italic text-paper-dim py-8 text-center">Pilih tabel dulu untuk menjelajahi data.</div>
        <div x-show="selectedTable">
            <div class="flex flex-wrap items-center justify-between gap-4 mb-6 pb-4 border-b border-[color:var(--rule)]">
                <div class="flex items-baseline gap-4">
                    <span class="label-mono mb-0">Tabel</span>
                    <span class="font-serif text-2xl italic text-copper" style="font-variation-settings: 'opsz' 60, 'wght' 400, 'WONK' 1;" x-text="selectedTable"></span>
                </div>
                <div class="flex gap-2">
                    <button @click="exportTable(selectedTable, 'json')" class="btn-mini">Export JSON ↗</button>
                    <button @click="exportTable(selectedTable, 'csv')" class="btn-mini">Export CSV ↗</button>
                </div>
            </div>

            <div class="border border-[color:var(--rule)] overflow-x-auto">
                <table class="table-editorial">
                    <thead x-show="browseData.length > 0">
                        <tr>
                            <template x-for="col in browseColumns" :key="col">
                                <th class="cursor-pointer hover:text-copper transition-colors" @click="sortByColumn(col)">
                                    <span x-text="col"></span>
                                    <span x-show="browseSortBy === col" class="text-copper" x-text="browseSortDir === 'asc' ? '↑' : '↓'"></span>
                                </th>
                            </template>
                            <th class="text-right">·</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(row, ri) in browseData" :key="ri">
                            <tr>
                                <template x-for="col in browseColumns" :key="col">
                                    <td class="text-paper-soft text-[11px] max-w-[260px] truncate" :title="String(row[col])" x-text="String(row[col] ?? 'NULL')"></td>
                                </template>
                                <td class="text-right">
                                    <button @click="deleteRow(selectedTable, row.id)" class="font-serif italic text-base leading-none text-paper-dim hover:text-[color:var(--rust)] transition-colors" title="Hapus baris">✕</button>
                                </td>
                            </tr>
                        </template>
                        <tr x-show="browseData.length === 0">
                            <td colspan="99" class="text-center py-12 font-serif italic text-paper-dim">Tabel kosong.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div x-show="browseLastPage > 1" class="flex items-center justify-between mt-6">
                <span class="font-mono text-[10px] tracking-[0.22em] uppercase text-paper-dim" x-text="`Halaman ${browsePage} / ${browseLastPage} · ${browseTotal.toLocaleString()} baris`"></span>
                <div class="flex gap-2">
                    <button @click="browsePage = Math.max(1, browsePage - 1); loadTableData()" :disabled="browsePage <= 1" class="btn-mini" :class="{ 'opacity-40 cursor-not-allowed': browsePage <= 1 }">← Sebelumnya</button>
                    <button @click="browsePage = Math.min(browseLastPage, browsePage + 1); loadTableData()" :disabled="browsePage >= browseLastPage" class="btn-mini" :class="{ 'opacity-40 cursor-not-allowed': browsePage >= browseLastPage }">Selanjutnya →</button>
                </div>
            </div>
        </div>
    </div>

    <!-- SQL Editor Tab -->
    <div x-show="activeTab === 'editor'" x-cloak>

        <!-- History -->
        <div x-show="queryHistory.length > 0" class="mb-6">
            <button @click="showHistory = !showHistory" class="font-mono text-[10px] tracking-[0.22em] uppercase text-paper-dim hover:text-copper transition-colors flex items-center gap-2">
                <span class="font-serif italic text-base leading-none">⟲</span>
                Riwayat (<span x-text="queryHistory.length"></span>) <span x-text="showHistory ? '▲' : '▼'"></span>
            </button>
            <div x-show="showHistory" x-collapse class="mt-3 space-y-1.5 max-h-40 overflow-y-auto">
                <template x-for="(item, i) in queryHistory" :key="i">
                    <div @click="query = item.query; showHistory = false" class="border border-[color:var(--rule)] hover:border-copper px-4 py-2.5 font-mono text-[11px] text-paper-soft cursor-pointer transition-colors">
                        <span class="truncate inline-block max-w-[80%]" x-text="item.query"></span>
                        <span class="text-paper-dim text-[9px] tracking-wider ml-2" x-text="item.time"></span>
                    </div>
                </template>
            </div>
        </div>

        <!-- Editor -->
        <label class="label-mono">Pernyataan SQL</label>
        <textarea x-model="query"
                  rows="8"
                  class="textarea-editorial"
                  placeholder="SELECT * FROM users WHERE active = 1"></textarea>

        <div class="flex gap-3 mt-5">
            <button @click="runQuery()" :disabled="!query.trim() || running" class="btn-copper" :class="{ 'disabled': !query.trim() || running }">
                <span x-text="running ? 'Mengeksekusi…' : 'Jalankan'"></span>
                <span class="font-serif italic" x-show="!running">↗</span>
            </button>
        </div>

        <!-- Results -->
        <div x-show="queryResult" class="mt-8">

            <!-- SELECT -->
            <div x-show="queryResult.type === 'select'">
                <div class="flex items-center justify-between mb-4 pb-2 border-b border-[color:var(--rule)]">
                    <span class="section-label">Hasil</span>
                    <span class="font-mono text-[10px] tracking-[0.22em] uppercase text-paper-dim" x-text="`${queryResult.count} baris dikembalikan`"></span>
                </div>
                <div class="border border-[color:var(--rule)] overflow-x-auto">
                    <table class="table-editorial">
                        <thead x-show="queryResult.data.length > 0">
                            <tr>
                                <template x-for="(value, col) in queryResult.data[0]" :key="col">
                                    <th x-text="col"></th>
                                </template>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(row, ri) in queryResult.data" :key="ri">
                                <tr>
                                    <template x-for="(value, col) in row" :key="col">
                                        <td class="text-paper-soft text-[11px]" x-text="String(value ?? 'NULL')"></td>
                                    </template>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- DML -->
            <div x-show="queryResult.type === 'modify'" class="border border-copper bg-[color:var(--copper-glow)] p-5 font-mono text-[11px] tracking-wider uppercase text-copper flex items-start gap-3">
                <span class="font-serif italic text-xl leading-none text-copper">✓</span>
                <span x-text="queryResult.message"></span>
            </div>

            <!-- DDL -->
            <div x-show="queryResult.type === 'ddl'" class="border border-[color:#c8a04a] bg-[color:#c8a04a]/10 p-5 font-mono text-[11px] tracking-wider uppercase text-[color:#c8a04a] flex items-start gap-3">
                <span class="font-serif italic text-xl leading-none">⚠</span>
                <span x-text="queryResult.message"></span>
            </div>

            <!-- ERROR -->
            <div x-show="queryResult.type === 'error'" class="border border-[color:var(--rust)] bg-[color:var(--rust)]/10 p-5 font-mono text-[11px] text-[color:var(--rust)]">
                <div class="font-mono text-[10px] tracking-[0.22em] uppercase mb-2 flex items-center gap-2">
                    <span class="font-serif italic text-xl leading-none">!</span>
                    Galat
                </div>
                <div x-text="queryResult.error" class="leading-relaxed"></div>
            </div>
        </div>
    </div>

    @endif
</div>
@endsection

@push('scripts')
<script>
function dbApp(connections, queryHistory) {
    return {
        connections: connections,
        activeConnection: Object.keys(connections)[0] || 'primary',
        activeTab: 'tables',
        selectedTable: null,
        tables: [],
        loadingTables: false,
        browseData: [], browseColumns: [], browsePage: 1, browseTotal: 0, browseLastPage: 1,
        browseSortBy: null, browseSortDir: 'asc',
        query: '', queryResult: null, queryHistory: queryHistory || [], showHistory: false, running: false,
        csrf: document.querySelector('meta[name="csrf-token"]')?.content || '',

        init() { if (this.activeConnection) this.loadTables(); },

        async loadTables() {
            this.loadingTables = true;
            this.selectedTable = null;
            try {
                const res = await fetch(`{{ route('panel.api.tables') }}?connection=${this.activeConnection}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                const data = await res.json();
                this.tables = data.tables || [];
                if (data.error) showToast(data.error, 'warning');
            } catch(e) { showToast('Gagal memuat tabel', 'error'); }
            this.loadingTables = false;
        },

        async loadTableData() {
            if (!this.selectedTable) return;
            try {
                const params = new URLSearchParams({
                    connection: this.activeConnection,
                    page: this.browsePage,
                    per_page: 25,
                    sort_by: this.browseSortBy || '',
                    sort_dir: this.browseSortDir
                });
                const res = await fetch(`{{ route('panel.api.table-data', ['table' => '__T__']) }}`.replace('__T__', encodeURIComponent(this.selectedTable)) + '?' + params, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                const data = await res.json();
                this.browseData = data.data || [];
                this.browseTotal = data.total || 0;
                this.browseLastPage = data.last_page || 1;
                this.browseColumns = this.browseData.length > 0 ? Object.keys(this.browseData[0]) : [];
            } catch(e) { showToast('Gagal memuat data', 'error'); }
        },

        sortByColumn(col) {
            if (this.browseSortBy === col) this.browseSortDir = this.browseSortDir === 'asc' ? 'desc' : 'asc';
            else { this.browseSortBy = col; this.browseSortDir = 'asc'; }
            this.browsePage = 1;
            this.loadTableData();
        },

        async deleteRow(table, id) {
            if (!confirm('Hapus baris ini?')) return;
            try {
                const res = await fetch(`{{ route('panel.api.delete-row', ['table' => '__T__', 'id' => '__I__']) }}`.replace('__T__', encodeURIComponent(table)).replace('__I__', id), {
                    method: 'DELETE',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrf }
                });
                const data = await res.json();
                if (data.success) { this.loadTableData(); showToast('Baris dihapus'); }
                else showToast(data.error || 'Gagal', 'error');
            } catch(e) { showToast('Gagal', 'error'); }
        },

        exportTable(table, format) {
            window.open(`{{ route('panel.api.export', ['table' => '__T__', 'format' => '__F__']) }}`.replace('__T__', encodeURIComponent(table)).replace('__F__', format), '_blank');
        },

        async runQuery() {
            this.running = true; this.queryResult = null;
            try {
                const res = await fetch('{{ route('panel.api.query') }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrf },
                    body: JSON.stringify({ query: this.query, connection: this.activeConnection })
                });
                this.queryResult = await res.json();
                if (this.queryResult.type === 'error') showToast('Kueri galat', 'error');
            } catch(e) { showToast('Kueri gagal', 'error'); }
            this.running = false;
        }
    };
}
</script>
@endpush
