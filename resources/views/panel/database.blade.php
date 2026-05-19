@extends('panel.layout')

@section('title', 'Database')
@section('section-label', 'Modul · N° 002')
@section('breadcrumb', 'Database')

@section('content')
<div x-data="dbApp({{ json_encode($connections) }}, {{ json_encode(session('query_history', [])) }})">

    <!-- Toast Container -->
    <div x-show="toastMessage" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="fixed top-6 right-6 z-[100] px-5 py-3 font-mono text-[11px] tracking-wider uppercase text-ink shadow-lg"
         :class="toastType === 'success' ? 'bg-[#5a7a5a]' : toastType === 'error' ? 'bg-[#b85c44]' : 'bg-[#d4a45c]'"
         x-text="toastMessage" style="display: none;"></div>

    <!-- Delete Confirm Modal -->
    <div x-show="showDeleteModal" x-cloak class="modal-overlay" @click.self="showDeleteModal = false">
        <div class="modal-card" style="border-color: var(--rust); box-shadow: 8px 8px 0 var(--rust);">
            <div class="modal-header" style="border-color: rgba(184, 92, 68, 0.3);">
                <h3 class="modal-title" style="color: var(--rust);">Hapus <span class="italic">permanen</span></h3>
                <button @click="showDeleteModal = false" class="text-paper-dim hover:text-[color:var(--rust)] text-xl leading-none">×</button>
            </div>
            <div class="modal-body space-y-5">
                <div class="border border-[color:var(--rust)]/40 bg-[color:var(--rust)]/10 p-4">
                    <p class="font-serif text-sm text-paper leading-relaxed">
                        Tindakan ini akan menghapus <strong x-text="deleteLabel"></strong>
                        dari tabel <span class="italic text-copper" x-text="selectedTable"></span>. Data masih bisa dipulihkan dari <span class="italic">Sampah</span>.
                    </p>
                </div>
                <div class="bg-[color:var(--rust)]/5 border border-[color:var(--rust)]/20 px-4 py-3">
                    <div class="font-mono text-[10px] tracking-[0.22em] uppercase text-[color:var(--rust)] mb-1">ID yang akan dihapus</div>
                    <div class="font-mono text-lg tracking-wider text-paper" x-text="deleteId"></div>
                </div>
                <div class="flex gap-3 pt-4 border-t border-[color:var(--rule)]">
                    <button @click="showDeleteModal = false" class="btn-ghost flex-1 justify-center">Batal</button>
                    <button @click="confirmDelete()" class="btn-danger flex-1 justify-center">Hapus</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Empty Trash Confirm Modal -->
    <div x-show="showEmptyTrashModal" x-cloak class="modal-overlay" @click.self="showEmptyTrashModal = false">
        <div class="modal-card" style="border-color: var(--rust); box-shadow: 8px 8px 0 var(--rust);">
            <div class="modal-header" style="border-color: rgba(184, 92, 68, 0.3);">
                <h3 class="modal-title" style="color: var(--rust);">Kosongkan <span class="italic">Sampah</span></h3>
                <button @click="showEmptyTrashModal = false" class="text-paper-dim hover:text-[color:var(--rust)] text-xl leading-none">×</button>
            </div>
            <div class="modal-body space-y-5">
                <div class="border border-[color:var(--rust)]/40 bg-[color:var(--rust)]/10 p-4">
                    <p class="font-serif text-sm text-paper leading-relaxed">
                        Hapus permanen <strong x-text="trashTotal"></strong> baris di <span class="italic">Sampah</span>.
                        Tindakan ini <strong>tidak bisa</strong> diurungkan.
                    </p>
                </div>
                <div class="bg-[color:var(--rust)]/5 border border-[color:var(--rust)]/20 px-4 py-3">
                    <div class="font-mono text-[10px] tracking-[0.22em] uppercase text-[color:var(--rust)] mb-1">Tabel</div>
                    <div class="font-mono text-lg tracking-wider text-paper" x-text="selectedTable"></div>
                </div>
                <div class="flex gap-3 pt-4 border-t border-[color:var(--rule)]">
                    <button @click="showEmptyTrashModal = false" class="btn-ghost flex-1 justify-center">Batal</button>
                    <button @click="confirmEmptyTrash()" class="btn-danger flex-1 justify-center">Kosongkan Permanen</button>
                </div>
            </div>
        </div>
    </div>

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
        <button @click="activeTab = 'trash'; loadTrash()" class="tab-editorial" :class="activeTab === 'trash' ? 'active' : ''" :disabled="!selectedTable || !tableSoftDeletes">
            <span class="glyph text-base leading-none">🗑</span>
            <span>Sampah</span>
        </button>
    </div>

    <!-- Tables Tab -->
    <div x-show="activeTab === 'tables'" x-cloak>
        <div x-show="loadingTables" class="font-mono text-[11px] tracking-[0.22em] uppercase text-paper-dim py-12 text-center">
            <span class="font-serif italic text-paper-soft">Memuat tabel</span> ...
        </div>
        <div x-show="!loadingTables" class="border border-[color:var(--rule)] overflow-x-auto">
            <table class="table-editorial min-w-[600px]">
                <thead>
                    <tr>
                        <th class="sticky left-0 bg-ink z-10">Nama Tabel</th>
                        <th>Baris</th>
                        <th>Ukuran</th>
                        <th>Engine</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(table, i) in tables" :key="table.name">
                        <tr @click="selectedTable = table.name; activeTab = 'browse'; loadTableData()" class="cursor-pointer">
                            <td class="sticky left-0 bg-ink">
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
                    <button @click="showAddRow = !showAddRow" class="btn-mini px-4 py-2.5">
                        <span x-text="showAddRow ? 'Batal' : '+ Tambah Baris'"></span>
                    </button>
                    <button @click="exportTable(selectedTable, 'json')" class="btn-mini px-4 py-2.5">Export JSON ↗</button>
                    <button @click="exportTable(selectedTable, 'csv')" class="btn-mini px-4 py-2.5">Export CSV ↗</button>
                </div>
            </div>

            <!-- Add Row Form -->
            <div x-show="showAddRow" x-transition class="mb-6 border border-[#d4a45c] bg-[#1a1812] p-5">
                <div class="section-label mb-4">Tambah Baris Baru</div>
                <div class="grid gap-3 mb-4" :style="`grid-template-columns: repeat(${addRowColumns.length}, 1fr)`">
                    <template x-for="(col, ci) in addRowColumns" :key="col.name">
                        <div>
                            <label class="font-mono text-[9px] tracking-[0.22em] uppercase text-paper-dim block mb-1.5">
                                <span x-text="col.name"></span>
                                <span x-show="!col.nullable" class="text-[#b85c44]">*</span>
                            </label>
                            <template x-if="col.type === 'boolean'">
                                <input type="checkbox" x-model="newRow[col.name]" class="w-5 h-5 accent-[#d4a45c]">
                            </template>
                            <template x-if="col.type === 'select'">
                                <select x-model="newRow[col.name]" class="select-editorial text-[11px] py-2">
                                    <option value="">— Pilih —</option>
                                    <template x-for="opt in col.options" :key="opt">
                                        <option :value="opt" x-text="opt"></option>
                                    </template>
                                </select>
                            </template>
                            <template x-if="col.type !== 'boolean' && col.type !== 'select'">
                                <input :type="col.type"
                                       x-model="newRow[col.name]"
                                       class="input-editorial text-[11px]"
                                       :class="addRowErrors[col.name] ? 'border-[#b85c44]' : ''"
                                       @keydown.enter="submitAddRow()">
                            </template>
                            <template x-if="addRowErrors[col.name]">
                                <div class="font-mono text-[9px] text-[#b85c44] mt-1" x-text="addRowErrors[col.name]"></div>
                            </template>
                        </div>
                    </template>
                </div>
                <div class="flex justify-end gap-3">
                    <button @click="cancelAddRow()" class="px-5 py-2.5 font-mono text-[10px] tracking-[0.22em] uppercase border border-[rgba(244,237,225,0.24)] hover:border-[#d4a45c] transition-colors">✕ Batal</button>
                    <button @click="submitAddRow()" class="px-5 py-2.5 font-mono text-[10px] tracking-[0.22em] uppercase bg-[#d4a45c] hover:bg-[#c49650] text-ink transition-colors">✔ Simpan</button>
                </div>
            </div>

            <!-- Loading columns for add row -->
            <div x-show="loadingColumns" class="font-mono text-[11px] tracking-[0.22em] uppercase text-paper-dim py-4 text-center">
                <span class="font-serif italic text-paper-soft">Memuat kolom</span> ...
            </div>

            <!-- Data Table -->
            <div class="border border-[color:var(--rule)] overflow-x-auto">
                <table class="table-editorial min-w-[600px]">
                    <thead x-show="browseData.length > 0">
                        <tr>
                            <template x-for="(col, ci) in browseColumns" :key="col">
                                <th class="cursor-pointer hover:text-copper transition-colors" :class="ci === 0 ? 'sticky left-0 bg-ink z-10' : ''" @click="sortByColumn(col)">
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
                                <template x-for="(col, ci) in browseColumns" :key="col">
                                    <td class="text-paper-soft text-[11px] max-w-[260px] truncate"
                                        :class="ci === 0 ? 'sticky left-0 bg-ink z-10' : ''"
                                        :title="String(row[col] ?? 'NULL')"
                                        @dblclick.prevent="startEdit($el, row.id, col, row[col])">
                                        <div x-show="editingCell?.rowId !== row.id || editingCell?.column !== col" x-text="String(row[col] ?? 'NULL')"></div>
                                        <div x-show="editingCell?.rowId === row.id && editingCell?.column === col" @click.self="cancelEdit()">
                                            <div class="relative">
                                                <input :type="getColumnInputType(col)"
                                                       :value="editingCell?.column === col ? editingValue : row[col]"
                                                       @input="editingValue = $event.target.value"
                                                       @keydown.enter="saveCell()"
                                                       @keydown.escape="cancelEdit()"
                                                       @blur="saveCell()"
                                                       class="w-full h-full px-3 py-2 bg-[#1a1812] border-2 border-[#d4a45c] text-paper text-[11px] font-mono focus:outline-none focus:border-[#d4a45c]"
                                                       :class="savingCell ? 'opacity-50' : ''">
                                                <div x-show="savingCell" class="absolute inset-0 flex items-center justify-center bg-[#1a1812]/60">
                                                    <span class="font-serif italic text-copper text-sm">⟳</span>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </template>
                                <td class="text-right">
                                    <button @click="deleteRow(selectedTable, row.id, row[browseColumns[1]] || row.id)" class="font-serif italic text-base leading-none text-paper-dim hover:text-[#b85c44] transition-colors" title="Hapus baris">🗑</button>
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

    <!-- Trash Tab -->
    <div x-show="activeTab === 'trash'" x-cloak>
        <div x-show="!selectedTable" class="font-serif italic text-paper-dim py-8 text-center">Pilih tabel dulu.</div>
        <div x-show="selectedTable">
            <div class="flex flex-wrap items-center justify-between gap-4 mb-6 pb-4 border-b border-[color:var(--rule)]">
                <div class="flex items-baseline gap-4">
                    <span class="label-mono mb-0">🗑 Sampah</span>
                    <span class="font-serif text-2xl italic text-copper" style="font-variation-settings: 'opsz' 60, 'wght' 400, 'WONK' 1;" x-text="selectedTable"></span>
                </div>
                <div class="flex gap-2">
                    <button @click="loadTrash()" class="btn-mini px-4 py-2.5">⟳ Refresh</button>
                    <button @click="showEmptyTrashModal = true; loadTrash()" class="btn-mini px-4 py-2.5 bg-[#b85c44] hover:bg-[#c96a50]" x-show="trashTotal > 0">Kosongkan Sampah</button>
                </div>
            </div>

            <p class="font-mono text-[10px] tracking-[0.22em] uppercase text-paper-dim mb-6" x-text="`${trashTotal} baris di sampah · restore atau hapus permanen`"></p>

            <div class="border border-[color:var(--rule)] overflow-x-auto">
                <table class="table-editorial min-w-[600px]">
                    <thead x-show="trashData.length > 0">
                        <tr>
                            <template x-for="(col, ci) in trashColumns" :key="col">
                                <th :class="ci === 0 ? 'sticky left-0 bg-ink z-10' : ''" x-text="col"></th>
                            </template>
                            <th class="text-right">·</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(row, ri) in trashData" :key="ri">
                            <tr>
                                <template x-for="(col, ci) in trashColumns" :key="col">
                                    <td class="text-paper-soft text-[11px]"
                                        :class="ci === 0 ? 'sticky left-0 bg-ink' : ''"
                                        :title="String(row[col] ?? 'NULL')"
                                        x-text="col === 'deleted_at' ? formatDate(row[col]) : String(row[col] ?? 'NULL')"></td>
                                </template>
                                <td class="text-right">
                                    <button @click="restoreRow(selectedTable, row.id)" class="font-mono text-[9px] tracking-[0.22em] uppercase text-paper-dim hover:text-[#5a7a5a] transition-colors mr-3" title="Kembalikan">⟳ Kembalikan</button>
                                    <button @click="deleteRow(selectedTable, row.id, row[trashColumns[1]] || row.id, true)" class="font-serif italic text-base leading-none text-paper-dim hover:text-[#b85c44] transition-colors" title="Hapus permanen">🗑</button>
                                </td>
                            </tr>
                        </template>
                        <tr x-show="trashData.length === 0">
                            <td colspan="99" class="text-center py-12 font-serif italic text-paper-dim">Sampah kosong.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Trash Pagination -->
            <div x-show="trashLastPage > 1" class="flex items-center justify-between mt-6">
                <span class="font-mono text-[10px] tracking-[0.22em] uppercase text-paper-dim" x-text="`Halaman ${trashPage} / ${trashLastPage} · ${trashTotal} baris`"></span>
                <div class="flex gap-2">
                    <button @click="trashPage = Math.max(1, trashPage - 1); loadTrash()" :disabled="trashPage <= 1" class="btn-mini" :class="{ 'opacity-40 cursor-not-allowed': trashPage <= 1 }">← Sebelumnya</button>
                    <button @click="trashPage = Math.min(trashLastPage, trashPage + 1); loadTrash()" :disabled="trashPage >= trashLastPage" class="btn-mini" :class="{ 'opacity-40 cursor-not-allowed': trashPage >= trashLastPage }">Selanjutnya →</button>
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
            <div x-show="showHistory" class="mt-3 space-y-1.5 max-h-40 overflow-y-auto transition-all" style="transition: max-height 0.3s ease;">
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
                  rows="4 md:rows-8"
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
            <div x-show="queryResult?.type === 'select'">
                <div class="flex items-center justify-between mb-4 pb-2 border-b border-[color:var(--rule)]">
                    <span class="section-label">Hasil</span>
                    <span class="font-mono text-[10px] tracking-[0.22em] uppercase text-paper-dim" x-text="`${queryResult?.count ?? 0} baris dikembalikan`"></span>
                </div>
                <div class="border border-[color:var(--rule)] overflow-x-auto">
                    <table class="table-editorial min-w-[600px]">
                        <thead x-show="queryResult?.data?.length > 0">
                            <tr>
                                <template x-for="(value, col, ci) in queryResult?.data?.[0]" :key="col">
                                    <th x-text="col" :class="ci === 0 ? 'sticky left-0 bg-ink z-10' : ''"></th>
                                </template>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(row, ri) in (queryResult?.data ?? [])" :key="ri">
                                <tr>
                                    <template x-for="(value, col, ci) in row" :key="col">
                                        <td class="text-paper-soft text-[11px]" :class="ci === 0 ? 'sticky left-0 bg-ink' : ''" x-text="String(value ?? 'NULL')"></td>
                                    </template>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- DML modify -->
            <div x-show="queryResult?.type === 'modify'" class="border border-copper bg-[color:var(--copper-glow)] p-5 font-mono text-[11px] tracking-wider uppercase text-copper flex items-start gap-3">
                <span class="font-serif italic text-xl leading-none text-copper">✓</span>
                <span x-text="queryResult?.message ?? ''"></span>
            </div>

            <!-- DDL -->
            <div x-show="queryResult?.type === 'ddl'" class="border border-[color:#c8a04a] bg-[color:#c8a04a]/10 p-5 font-mono text-[11px] tracking-wider uppercase text-[color:#c8a04a] flex items-start gap-3">
                <span class="font-serif italic text-xl leading-none">⚠</span>
                <span x-text="queryResult?.message ?? ''"></span>
            </div>

            <!-- ERROR -->
            <div x-show="queryResult?.type === 'error'" class="border border-[color:var(--rust)] bg-[color:var(--rust)]/10 p-5 font-mono text-[11px] text-[color:var(--rust)]">
                <div class="font-mono text-[10px] tracking-[0.22em] uppercase mb-2 flex items-center gap-2">
                    <span class="font-serif italic text-xl leading-none">!</span>
                    Galat
                </div>
                <div x-text="queryResult?.error ?? 'Unknown error'" class="leading-relaxed"></div>
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
        browseData: [], browseColumns: [], browseColumnsMeta: [], browsePage: 1, browseTotal: 0, browseLastPage: 1,
        browseSortBy: null, browseSortDir: 'asc',
        tableSoftDeletes: false,
        query: '', queryResult: null, queryHistory: queryHistory || [], showHistory: false, running: false,
        csrf: document.querySelector('meta[name="csrf-token"]')?.content || '',

        // Add row
        showAddRow: false,
        addRowColumns: [],
        loadingColumns: false,
        newRow: {},
        addRowErrors: {},

        // Inline edit
        editingCell: null,
        editingValue: '',
        savingCell: false,

        // Delete modal
        showDeleteModal: false,
        deleteId: null,
        deleteLabel: '',
        deleteForce: false,

        // Empty trash modal
        showEmptyTrashModal: false,

        // Trash
        trashData: [], trashColumns: [], trashPage: 1, trashTotal: 0, trashLastPage: 1,

        // Toast
        toastMessage: '',
        toastType: 'success',
        toastTimeout: null,

        init() { if (this.activeConnection) this.loadTables(); },

        showToast(message, type = 'success') {
            this.toastMessage = message;
            this.toastType = type;
            if (this.toastTimeout) clearTimeout(this.toastTimeout);
            this.toastTimeout = setTimeout(() => { this.toastMessage = ''; }, 3000);
        },

        async loadTables() {
            this.loadingTables = true;
            this.selectedTable = null;
            try {
                const res = await fetch(`{{ route('panel.api.tables') }}?connection=${this.activeConnection}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                const data = await res.json();
                this.tables = data.tables || [];
                if (data.error) this.showToast(data.error, 'warning');
            } catch(e) { this.showToast('Gagal memuat tabel', 'error'); }
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
                this.browseColumns = (data.columns || []).map(c => c.name);
                this.browseColumnsMeta = data.columns || [];
                this.tableSoftDeletes = data.softDeletes || false;

                // Load column metadata for add row form
                this.loadColumnMeta();
            } catch(e) { this.showToast('Gagal memuat data', 'error'); }
        },

        async loadColumnMeta() {
            if (!this.selectedTable) return;
            this.loadingColumns = true;
            try {
                // Use already-loaded browseColumnsMeta from loadTableData()
                const meta = this.browseColumnsMeta || [];
                // Filter columns for add row (exclude auto_increment, include nullable and required)
                this.addRowColumns = meta.filter(col => {
                    return col.extra !== 'auto_increment' && col.name !== 'deleted_at';
                }).map(col => {
                    let type = 'text';
                    if (col.type === 'varchar' || col.type === 'text' || col.type === 'char') type = 'text';
                    else if (col.type === 'int' || col.type === 'bigint' || col.type === 'smallint' || col.type === 'decimal' || col.type === 'float' || col.type === 'double') type = 'number';
                    else if (col.type === 'date') type = 'date';
                    else if (col.type === 'datetime' || col.type === 'timestamp') type = 'datetime-local';
                    else if (col.type === 'time') type = 'time';
                    else if (col.type === 'boolean' || (col.type === 'tinyint' && col.type_args === '1')) type = 'boolean';
                    else if (col.type === 'enum' && col.type_args) type = 'select';
                    return { ...col, type };
                });
            } catch(e) { /* silent */ }
            this.loadingColumns = false;
        },

        getColumnInputType(colName) {
            const col = this.addRowColumns.find(c => c.name === colName);
            return col ? col.type : 'text';
        },

        sortByColumn(col) {
            if (this.browseSortBy === col) this.browseSortDir = this.browseSortDir === 'asc' ? 'desc' : 'asc';
            else { this.browseSortBy = col; this.browseSortDir = 'asc'; }
            this.browsePage = 1;
            this.loadTableData();
        },

        // Inline edit
        startEdit(el, rowId, column, value) {
            this.editingCell = { rowId, column, originalValue: value };
            this.editingValue = value;
            this.$nextTick(() => {
                const input = el.querySelector('input');
                if (input) { input.focus(); input.select(); }
            });
        },

        async saveCell() {
            if (!this.editingCell || this.savingCell) return;
            const { rowId, column, originalValue } = this.editingCell;
            if (this.editingValue === originalValue) { this.cancelEdit(); return; }

            this.savingCell = true;
            try {
                const res = await fetch(`{{ route('panel.api.update-cell', ['table' => '__T__', 'id' => '__I__']) }}`.replace('__T__', encodeURIComponent(this.selectedTable)).replace('__I__', rowId), {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrf },
                    body: JSON.stringify({ column, value: this.editingValue, connection: this.activeConnection })
                });
                const data = await res.json();
                if (data.success) {
                    this.showToast('Tersimpan');
                    // Update local data
                    const rowIdx = this.browseData.findIndex(r => r.id === rowId);
                    if (rowIdx >= 0) this.browseData[rowIdx][column] = this.editingValue;
                } else {
                    this.showToast(data.error || 'Galat menyimpan', 'error');
                }
            } catch(e) {
                console.error('saveCell error:', e);
                this.showToast('Galat koneksi', 'error');
            }
            this.savingCell = false;
            this.editingCell = null;
            this.editingValue = '';
        },

        cancelEdit() {
            this.editingCell = null;
            this.editingValue = '';
        },

        // Add row
        async submitAddRow() {
            if (!this.selectedTable) return;
            this.addRowErrors = {};

            // Validate required fields
            for (const col of this.addRowColumns) {
                if (!col.nullable && (this.newRow[col.name] === undefined || this.newRow[col.name] === '' || this.newRow[col.name] === null)) {
                    this.addRowErrors[col.name] = 'Wajib diisi';
                }
            }
            if (Object.keys(this.addRowErrors).length > 0) return;

            try {
                const res = await fetch(`{{ route('panel.api.store-row', ['table' => '__T__']) }}`.replace('__T__', encodeURIComponent(this.selectedTable)), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrf },
                    body: JSON.stringify({ ...this.newRow, connection: this.activeConnection })
                });
                const data = await res.json();
                if (data.success) {
                    this.showToast('Baris ditambahkan');
                    this.showAddRow = false;
                    this.newRow = {};
                    this.browsePage = 1;
                    this.loadTableData();
                } else {
                    this.showToast(data.error || 'Galat menyimpan', 'error');
                }
            } catch(e) { this.showToast('Galat koneksi', 'error'); }
        },

        cancelAddRow() {
            this.showAddRow = false;
            this.newRow = {};
            this.addRowErrors = {};
        },

        // Delete
        deleteRow(table, id, label, force = false) {
            this.deleteId = id;
            this.deleteLabel = label;
            this.deleteForce = force;
            this.showDeleteModal = true;
        },

        async confirmDelete() {
            if (!this.deleteId) return;
            const table = this.selectedTable;
            const id = this.deleteId;
            const force = this.deleteForce;
            this.showDeleteModal = false;

            try {
                let res;
                if (force) {
                    res = await fetch(`{{ route('panel.api.force-delete-row', ['table' => '__T__', 'id' => '__I__']) }}`.replace('__T__', encodeURIComponent(table)).replace('__I__', id), {
                        method: 'DELETE',
                        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrf }
                    });
                } else {
                    res = await fetch(`{{ route('panel.api.delete-row', ['table' => '__T__', 'id' => '__I__']) }}`.replace('__T__', encodeURIComponent(table)).replace('__I__', id), {
                        method: 'DELETE',
                        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrf }
                    });
                }
                const data = await res.json();
                if (data.success) {
                    this.showToast(force ? 'Dihapus permanen' : 'Dihapus — bisa restore dari Trash');
                    if (force) this.loadTrash();
                    else this.loadTableData();
                } else {
                    this.showToast(data.error || 'Gagal', 'error');
                }
            } catch(e) { this.showToast('Gagal', 'error'); }
            this.deleteId = null;
        },

        // Trash
        async loadTrash() {
            if (!this.selectedTable) return;
            try {
                const params = new URLSearchParams({
                    connection: this.activeConnection,
                    page: this.trashPage,
                    per_page: 25
                });
                const res = await fetch(`{{ route('panel.api.table-trash', ['table' => '__T__']) }}`.replace('__T__', encodeURIComponent(this.selectedTable)) + '?' + params, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                const data = await res.json();
                this.trashData = data.rows || [];
                this.trashTotal = data.total || 0;
                this.trashLastPage = data.last_page || 1;
                this.trashColumns = data.columns || (this.trashData.length > 0 ? Object.keys(this.trashData[0]) : []);
            } catch(e) { this.showToast('Gagal memuat sampah', 'error'); }
        },

        async restoreRow(table, id) {
            try {
                const res = await fetch(`{{ route('panel.api.restore-row', ['table' => '__T__', 'id' => '__I__']) }}`.replace('__T__', encodeURIComponent(table)).replace('__I__', id), {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrf }
                });
                const data = await res.json();
                if (data.success) {
                    this.showToast('Dipulihkan');
                    this.loadTrash();
                    this.loadTableData();
                } else {
                    this.showToast(data.error || 'Gagal', 'error');
                }
            } catch(e) { this.showToast('Gagal', 'error'); }
        },

        async confirmEmptyTrash() {
            if (!this.selectedTable) return;
            this.showEmptyTrashModal = false;
            try {
                const res = await fetch(`{{ route('panel.api.empty-trash', ['table' => '__T__']) }}`.replace('__T__', encodeURIComponent(this.selectedTable)), {
                    method: 'DELETE',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrf }
                });
                const data = await res.json();
                if (data.success) {
                    this.showToast(`Sampah dikosongkan (${data.count} baris dihapus)`);
                    this.loadTrash();
                } else {
                    this.showToast(data.error || 'Gagal', 'error');
                }
            } catch(e) { this.showToast('Gagal', 'error'); }
        },

        formatDate(val) {
            if (!val) return '—';
            try {
                return new Date(val).toLocaleString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
            } catch(e) { return val; }
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
                if (this.queryResult.type === 'error') this.showToast('Kueri galat', 'error');
            } catch(e) { this.showToast('Kueri gagal', 'error'); }
            this.running = false;
        }
    };
}
</script>
@endpush
