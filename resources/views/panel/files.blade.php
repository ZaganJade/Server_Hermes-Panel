@extends('panel.layout')

@section('title', 'Files')
@section('section-label', 'Modul · N° 003')
@section('breadcrumb', 'Files')
@section('content-class', '!p-0')

@section('content')
<?php $pageActiveProject = $activeProject ?? null; ?>
<div x-data="fileApp('{{ $initialPath }}')" class="min-h-[calc(100vh-180px)] flex flex-col">

    <!-- Editorial Header -->
    <div class="px-8 pt-10 pb-8 border-b border-[color:var(--rule)] animate-fade-up">
        <div class="grid lg:grid-cols-[1fr_auto] gap-6 items-end">
            <div>
                <div class="section-label mb-5">Manajer Berkas</div>
                <h1 class="font-serif text-4xl text-paper" style="font-variation-settings: 'opsz' 144, 'wght' 500, 'WONK' 1; letter-spacing: -0.02em;">
                    Direktori <span class="italic text-copper" style="font-variation-settings: 'opsz' 144, 'wght' 300, 'WONK' 1;">proyek</span>.
                </h1>
            </div>

            <!-- Action toolbar -->
            <div class="flex flex-wrap items-center gap-2 justify-end">
                <div class="relative" x-data="{ openSearch: false }">
                    <button @click="openSearch = !openSearch" class="btn-mini" title="Cari">
                        <span class="font-serif italic text-base leading-none text-copper">⌕</span> Cari
                    </button>
                    <div x-show="openSearch" x-cloak @click.away="openSearch = false" class="absolute right-0 top-full mt-2 bg-ink-soft border border-[color:var(--rule-strong)] p-4 w-80 z-30 shadow-xl">
                        <input type="text" x-model="searchQuery" @input="searchFiles()" placeholder="filename..." class="input-editorial mb-2" style="padding: 8px 12px; font-size: 12px;">
                        <label class="font-mono text-[10px] tracking-wider uppercase text-paper-dim flex items-center gap-2 mb-2">
                            <input type="checkbox" x-model="searchRecursive" @change="searchFiles()" style="accent-color: var(--copper);"> Termasuk subdirektori
                        </label>
                        <div x-show="searchResults.length > 0" class="space-y-1 max-h-56 overflow-y-auto mt-3 pt-2 border-t border-[color:var(--rule)]">
                            <template x-for="r in searchResults" :key="r.path">
                                <div @click="navigate(r.path); searchResults = []; openSearch = false" class="px-2 py-1.5 font-mono text-[11px] text-paper-soft hover:text-copper hover:bg-ink cursor-pointer truncate">
                                    <span class="text-paper-dim mr-2" x-text="r.is_directory ? '▢' : '▤'"></span>
                                    <span x-text="r.path"></span>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
                <button @click="showNewFile = true" class="btn-mini" title="Berkas baru">+ Berkas</button>
                <button @click="showNewFolder = true" class="btn-mini" title="Folder baru">+ Folder</button>
                <label class="btn-mini cursor-pointer" title="Unggah">
                    ↑ Unggah
                    <input type="file" class="hidden" multiple @change="uploadFiles($event)">
                </label>
                <button x-show="selectedItem" @click="downloadCurrent" class="btn-mini" title="Unduh">↓ Unduh</button>
                <button @click="toggleTerminal" class="btn-mini" :class="showTerminal ? 'border-copper text-copper' : ''" title="Terminal">
                    <span class="font-serif italic text-base leading-none">_</span> Terminal
                </button>
            </div>
        </div>

        <!-- Breadcrumb -->
        <div class="mt-6 flex items-center gap-2 font-mono text-[11px] tracking-wider flex-wrap">
            <span class="text-paper-dim text-[10px] uppercase tracking-[0.22em]">Path:</span>
            <template x-for="(crumb, i) in breadcrumbs" :key="i">
                <div class="flex items-center gap-2">
                    <button @click="navigate(crumb.path)"
                            class="hover:text-copper transition-colors truncate max-w-[200px]"
                            :class="i === breadcrumbs.length - 1 ? 'text-copper' : 'text-paper-soft'"
                            x-text="crumb.name"></button>
                    <span x-show="i < breadcrumbs.length - 1" class="text-paper-dim">/</span>
                </div>
            </template>
        </div>
    </div>

    <!-- File Listing -->
    <div class="flex-1 overflow-y-auto animate-fade-up-1" @click.self="selectedItem = null">
        <!-- Header -->
        <div class="grid grid-cols-[1fr_120px_140px_100px_60px_60px] gap-3 px-8 py-3 font-mono text-[9px] tracking-[0.22em] uppercase text-paper-dim border-b border-[color:var(--rule)] sticky top-0 bg-ink z-10">
            <span>Nama</span>
            <span>Ukuran</span>
            <span>Diubah</span>
            <span>Hak Akses</span>
            <span class="text-right">Tipe</span>
            <span></span>
        </div>

        <!-- Loading -->
        <div x-show="loading" class="px-8 py-12 text-center font-serif italic text-paper-dim">Memuat direktori...</div>

        <!-- Empty -->
        <div x-show="!loading && directories.length === 0 && files.length === 0" class="px-8 py-16 text-center">
            <div class="glyph text-5xl mb-4 opacity-50">∅</div>
            <p class="font-serif italic text-paper-dim">Direktori kosong.</p>
        </div>

        <!-- Directories -->
        <template x-for="dir in directories" :key="dir.path">
            <div @click="selectedItem = dir.path"
                 @dblclick="navigate(dir.path)"
                 :class="selectedItem === dir.path ? 'bg-ink-soft' : 'hover:bg-ink-soft'">
                <!-- Desktop Row -->
                <div class="hidden md:grid grid-cols-[1fr_120px_140px_100px_60px_60px] gap-3 px-8 py-2.5 cursor-pointer transition-colors text-sm border-b border-[color:var(--rule)]">
                    <span class="text-paper truncate flex items-center gap-3">
                        <span class="glyph text-base leading-none">▢</span>
                        <span class="font-mono text-[12px]" x-text="dir.name"></span>
                    </span>
                    <span class="text-paper-dim font-mono text-[11px]">—</span>
                    <span class="text-paper-dim font-mono text-[10px] tracking-wide" x-text="dir.modified"></span>
                    <span class="text-paper-dim font-mono text-[10px]" x-text="dir.permissions"></span>
                    <span class="text-paper-dim font-mono text-[9px] tracking-[0.22em] uppercase text-right">DIR</span>
                    <span class="text-right">
                        <button @click.stop="deleteItem(dir)" class="font-serif italic text-base leading-none text-paper-dim hover:text-[color:var(--rust)] transition-colors">✕</button>
                    </span>
                </div>
                <!-- Mobile Card -->
                <div class="md:hidden grid grid-cols-1 gap-0 px-5 py-4 cursor-pointer transition-colors text-sm border-b border-[color:var(--rule)]">
                    <div class="flex items-center justify-between gap-3 mb-2">
                        <div class="flex items-center gap-3">
                            <span class="glyph text-base leading-none text-copper">▢</span>
                            <span class="font-mono text-[13px] text-paper truncate" x-text="dir.name"></span>
                        </div>
                        <button @click.stop="deleteItem(dir)" class="font-serif italic text-base leading-none text-paper-dim hover:text-[color:var(--rust)] transition-colors">✕</button>
                    </div>
                    <div class="grid grid-cols-2 gap-x-4 gap-y-1 pl-7">
                        <div class="text-paper-dim font-mono text-[10px] tracking-wide" x-text="dir.modified"></div>
                        <div class="text-paper-dim font-mono text-[10px] text-right" x-text="dir.permissions"></div>
                        <div class="col-span-2 mt-1">
                            <span class="text-copper font-mono text-[9px] tracking-[0.22em] uppercase">DIR</span>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <!-- Files -->
        <template x-for="file in files" :key="file.path">
            <div @click="selectedItem = file.path"
                 @dblclick="openFile(file)"
                 :class="selectedItem === file.path ? 'bg-ink-soft' : 'hover:bg-ink-soft'">
                <!-- Desktop Row -->
                <div class="hidden md:grid grid-cols-[1fr_120px_140px_100px_60px_60px] gap-3 px-8 py-2.5 cursor-pointer transition-colors text-sm border-b border-[color:var(--rule)]">
                    <span class="text-paper-soft truncate flex items-center gap-3">
                        <span class="font-serif italic text-paper-dim text-sm leading-none w-4">▤</span>
                        <span class="font-mono text-[12px]" x-text="file.name"></span>
                    </span>
                    <span class="text-paper-dim font-mono text-[10px]" x-text="file.size"></span>
                    <span class="text-paper-dim font-mono text-[10px] tracking-wide" x-text="file.modified"></span>
                    <span class="text-paper-dim font-mono text-[10px]" x-text="file.permissions"></span>
                    <span class="text-paper-dim font-mono text-[9px] tracking-[0.22em] uppercase text-right" x-text="file.extension || '—'"></span>
                    <span class="text-right">
                        <button @click.stop="deleteItem(file)" class="font-serif italic text-base leading-none text-paper-dim hover:text-[color:var(--rust)] transition-colors">✕</button>
                    </span>
                </div>
                <!-- Mobile Card -->
                <div class="md:hidden grid grid-cols-1 gap-0 px-5 py-4 cursor-pointer transition-colors text-sm border-b border-[color:var(--rule)]">
                    <div class="flex items-center justify-between gap-3 mb-2">
                        <div class="flex items-center gap-3">
                            <span class="font-serif italic text-paper-dim text-sm leading-none w-4">▤</span>
                            <span class="font-mono text-[13px] text-paper-soft truncate" x-text="file.name"></span>
                        </div>
                        <button @click.stop="deleteItem(file)" class="font-serif italic text-base leading-none text-paper-dim hover:text-[color:var(--rust)] transition-colors">✕</button>
                    </div>
                    <div class="grid grid-cols-3 gap-x-4 gap-y-1 pl-7">
                        <div class="text-paper-dim font-mono text-[10px]" x-text="file.size"></div>
                        <div class="text-paper-dim font-mono text-[10px] tracking-wide" x-text="file.modified"></div>
                        <div class="text-paper-dim font-mono text-[10px] text-right" x-text="file.permissions"></div>
                    </div>
                    <div class="mt-1 pl-7">
                        <span class="text-rust font-mono text-[9px] tracking-[0.22em] uppercase" x-text="file.extension || '—'"></span>
                    </div>
                </div>
            </div>
        </template>
    </div>

    <!-- Terminal -->
    <div x-show="showTerminal" x-cloak class="border-t border-[color:var(--rule-strong)] bg-ink" style="height: 320px;">
        <!-- Header -->
        <div class="flex items-center justify-between px-6 py-2.5 border-b border-[color:var(--rule)] bg-ink-soft">
            <span class="font-mono text-[10px] tracking-[0.22em] uppercase text-copper flex items-center gap-2">
                <span class="pulse-dot"></span>
                <span>Terminal</span>
                <span class="text-paper-dim" x-text="'· ' + termDisplay"></span>
            </span>
            <div class="flex items-center gap-3">
                <button @click="resetTerminal()" class="font-mono text-[9px] tracking-[0.22em] uppercase text-paper-dim hover:text-copper transition-colors" title="Reset cwd">⟲ Reset</button>
                <button @click="clearTerminal()" class="font-mono text-[9px] tracking-[0.22em] uppercase text-paper-dim hover:text-copper transition-colors" title="Bersihkan layar">␡ Bersihkan</button>
                <button @click="showTerminal = false" class="font-serif italic text-base leading-none text-paper-dim hover:text-[color:var(--rust)]">✕</button>
            </div>
        </div>

        <!-- Output -->
        <div x-ref="termOutput" class="font-mono text-[12px] text-paper-soft px-6 py-3 overflow-y-auto leading-relaxed" style="height: calc(320px - 86px);">
            <!-- Welcome -->
            <div x-show="termHistory.length === 0" class="text-paper-dim italic font-serif">
                <span class="text-copper not-italic font-mono text-[10px] tracking-[0.22em] uppercase">// Hermes Terminal</span><br>
                Ketik perintah dan tekan Enter. Gunakan <code class="text-copper not-italic">cd</code> untuk pindah direktori,
                <code class="text-copper not-italic">clear</code> untuk membersihkan layar.
            </div>

            <template x-for="(entry, i) in termHistory" :key="i">
                <div class="mb-2">
                    <!-- Prompt + command -->
                    <div class="flex items-baseline gap-2">
                        <span class="text-copper select-none">
                            <span class="font-serif italic">ψ</span>
                            <span x-text="entry.cwd" class="text-paper-dim ml-1"></span>
                            <span class="text-copper ml-1">›</span>
                        </span>
                        <span class="text-paper" x-text="entry.command"></span>
                    </div>
                    <!-- Output -->
                    <pre x-show="entry.output" class="whitespace-pre-wrap text-paper-soft pl-4" x-text="entry.output"></pre>
                    <pre x-show="entry.error" class="whitespace-pre-wrap text-[color:var(--rust)] pl-4" x-text="entry.error"></pre>
                    <div x-show="entry.exitCode !== 0 && !entry.error" class="text-[color:var(--rust)] pl-4 text-[10px] tracking-wider uppercase">
                        → keluar dengan kode <span x-text="entry.exitCode"></span>
                    </div>
                </div>
            </template>

            <!-- Running -->
            <div x-show="termRunning" class="flex items-baseline gap-2 text-paper-dim italic">
                <span class="text-copper">
                    <span class="font-serif italic">ψ</span>
                    <span x-text="termDisplay" class="text-paper-dim ml-1"></span>
                    <span class="text-copper ml-1">›</span>
                </span>
                <span x-text="termCurrentCommand"></span>
                <span class="animate-pulse text-copper">█</span>
            </div>
        </div>

        <!-- Input -->
        <div class="flex items-baseline gap-2 px-6 py-2.5 border-t border-[color:var(--rule)] bg-ink-soft font-mono text-[12px]">
            <span class="text-copper select-none shrink-0">
                <span class="font-serif italic">ψ</span>
                <span x-text="termDisplay" class="text-paper-dim ml-1"></span>
                <span class="text-copper ml-1">›</span>
            </span>
            <input type="text"
                   x-model="termInput"
                   x-ref="termInput"
                   @keydown.enter="runTerminalCommand()"
                   @keydown.up.prevent="recallHistory(-1)"
                   @keydown.down.prevent="recallHistory(1)"
                   @keydown.tab.prevent=""
                   :disabled="termRunning"
                   placeholder="ketik perintah..."
                   autocomplete="off"
                   spellcheck="false"
                   class="flex-1 bg-transparent border-none focus:outline-none text-paper placeholder:text-paper-dim/50 placeholder:italic font-mono">
        </div>
    </div>

    <!-- New File Modal -->
    <div x-show="showNewFile" x-cloak class="modal-overlay" @click.self="showNewFile = false; newItemName = ''">
        <div class="modal-card" style="max-width: 480px;">
            <div class="modal-header">
                <h3 class="modal-title">Berkas <span class="italic">baru</span></h3>
                <button @click="showNewFile = false; newItemName = ''" class="text-paper-dim hover:text-copper text-xl leading-none">×</button>
            </div>
            <div class="modal-body space-y-4">
                <div>
                    <label class="label-mono">Nama Berkas</label>
                    <input type="text" x-model="newItemName" @keydown.enter="createItem('file')" placeholder="filename.php" class="input-editorial" autofocus>
                </div>
                <div class="flex gap-3 pt-3 border-t border-[color:var(--rule)]">
                    <button @click="showNewFile = false; newItemName = ''" class="btn-ghost flex-1 justify-center">Batal</button>
                    <button @click="createItem('file')" :disabled="!newItemName" class="btn-copper flex-1 justify-center" :class="{ 'disabled': !newItemName }">Buat</button>
                </div>
            </div>
        </div>
    </div>

    <!-- New Folder Modal -->
    <div x-show="showNewFolder" x-cloak class="modal-overlay" @click.self="showNewFolder = false; newItemName = ''">
        <div class="modal-card" style="max-width: 480px;">
            <div class="modal-header">
                <h3 class="modal-title">Folder <span class="italic">baru</span></h3>
                <button @click="showNewFolder = false; newItemName = ''" class="text-paper-dim hover:text-copper text-xl leading-none">×</button>
            </div>
            <div class="modal-body space-y-4">
                <div>
                    <label class="label-mono">Nama Folder</label>
                    <input type="text" x-model="newItemName" @keydown.enter="createItem('directory')" placeholder="folder-name" class="input-editorial" autofocus>
                </div>
                <div class="flex gap-3 pt-3 border-t border-[color:var(--rule)]">
                    <button @click="showNewFolder = false; newItemName = ''" class="btn-ghost flex-1 justify-center">Batal</button>
                    <button @click="createItem('directory')" :disabled="!newItemName" class="btn-copper flex-1 justify-center" :class="{ 'disabled': !newItemName }">Buat</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Editor Modal -->
    <div x-show="showEditor" x-cloak class="modal-overlay">
        <div class="bg-ink-soft border border-[color:var(--rule-strong)] w-full max-w-5xl max-h-[92vh] flex flex-col overflow-hidden" style="box-shadow: 8px 8px 0 var(--copper-deep);">
            <div class="flex items-center justify-between px-6 py-4 border-b border-[color:var(--rule)]">
                <div class="flex items-center gap-3 min-w-0">
                    <span class="font-serif italic text-base text-copper leading-none">▤</span>
                    <span class="font-mono text-[12px] text-paper truncate" x-text="editingFile?.path"></span>
                </div>
                <div class="flex items-center gap-3 shrink-0">
                    <span x-show="editingFile && !editingFile.editable" class="font-mono text-[10px] tracking-wider uppercase text-paper-dim italic">Hanya baca</span>
                    <button x-show="editingFile?.editable" @click="saveFile()" :disabled="saving" class="btn-mini" :class="{ 'border-copper text-copper': true }">
                        <span class="font-serif italic" x-show="!saving">⇲</span>
                        <span x-text="saving ? 'Menyimpan…' : 'Simpan'"></span>
                    </button>
                    <button @click="showEditor = false; editingContent = ''; editingFile = null" class="text-paper-dim hover:text-copper text-xl leading-none">×</button>
                </div>
            </div>
            <textarea x-model="editingContent" :readonly="!editingFile?.editable"
                      class="flex-1 min-h-[500px] w-full bg-ink text-paper font-mono text-[12px] p-6 resize-none focus:outline-none leading-relaxed"
                      style="border: none;" spellcheck="false"></textarea>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
var pageActiveProject = <?php echo $pageActiveProject ? json_encode($pageActiveProject) : 'null'; ?>;
console.log('[files] pageActiveProject:', pageActiveProject);

function fileApp(initialPath) {
    return {
        currentPath: initialPath, directories: [], files: [], breadcrumbs: [],
        loading: false, selectedItem: null,
        showNewFile: false, showNewFolder: false, showEditor: false, showTerminal: false,
        newItemName: '', editingFile: null, editingContent: '', saving: false,
        searchQuery: '', searchRecursive: false, searchResults: [],
        // Terminal state
        termInput: '', termCurrentCommand: '', termHistory: [], termCmdHistory: [], termHistoryIndex: -1,
        termCwd: '', termDisplay: '~', termRunning: false,
        csrf: document.querySelector('meta[name="csrf-token"]')?.content || '',

        init() {
            this.loadFiles(initialPath);
            this.loadTerminalState();
        },

        async loadFiles(path) {
            this.loading = true; this.currentPath = path;
            try {
                const projectParam = (typeof pageActiveProject !== 'undefined' && pageActiveProject?.name)
                    ? `&project=${encodeURIComponent(pageActiveProject.name)}` : '';
                const url = `{{ route('panel.api.files') }}?path=${encodeURIComponent(path)}${projectParam}`;
                const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                const data = await res.json();
                this.directories = data.directories || [];
                this.files = data.files || [];
                this.breadcrumbs = data.breadcrumbs || [];
                this.selectedItem = null;
            } catch(e) { showToast('Gagal memuat direktori', 'error'); }
            this.loading = false;
        },

        navigate(path) { this.loadFiles(path); },

        async openFile(file) {
            try {
                const res = await fetch('{{ route("panel.api.file-content") }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrf },
                    body: JSON.stringify({ path: file.path })
                });
                const data = await res.json();
                if (data.error) { showToast(data.error, 'error'); return; }
                this.editingFile = { path: file.path, editable: data.editable };
                this.editingContent = data.content;
                this.showEditor = true;
            } catch(e) { showToast('Gagal membuka berkas', 'error'); }
        },

        async saveFile() {
            this.saving = true;
            try {
                const res = await fetch('{{ route("panel.api.file-save") }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrf },
                    body: JSON.stringify({ path: this.editingFile.path, content: this.editingContent })
                });
                const data = await res.json();
                if (data.success) showToast('Berkas disimpan');
                else showToast(data.error || 'Gagal menyimpan', 'error');
            } catch(e) { showToast('Gagal menyimpan', 'error'); }
            this.saving = false;
        },

        async createItem(type) {
            if (!this.newItemName) return;
            try {
                const res = await fetch('{{ route("panel.api.file-create") }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrf },
                    body: JSON.stringify({ path: this.currentPath, name: this.newItemName, type })
                });
                const data = await res.json();
                if (data.success) { this.showNewFile = false; this.showNewFolder = false; this.newItemName = ''; this.loadFiles(this.currentPath); }
                else showToast(data.error || 'Gagal', 'error');
            } catch(e) { showToast('Gagal', 'error'); }
        },

        deleteItem(item) {
            if (!confirm(`Hapus "${item.name}"? Tidak bisa diurungkan.`)) return;
            fetch('{{ route("panel.api.file-delete") }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrf },
                body: JSON.stringify({ path: item.path })
            }).then(r => r.json()).then(data => {
                if (data.success) { this.selectedItem = null; this.loadFiles(this.currentPath); showToast('Dihapus'); }
                else showToast(data.error || 'Gagal', 'error');
            });
        },

        uploadFiles(event) {
            const files = event.target.files;
            if (!files.length) return;
            Array.from(files).forEach(file => {
                const formData = new FormData();
                formData.append('file', file);
                formData.append('path', this.currentPath);
                fetch('{{ route("panel.api.file-upload") }}', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrf },
                    body: formData
                }).then(r => r.json()).then(data => {
                    if (data.success) this.loadFiles(this.currentPath);
                    else showToast(data.error || 'Unggah gagal', 'error');
                });
            });
            event.target.value = '';
        },

        downloadCurrent() {
            if (!this.selectedItem) return;
            window.open(`{{ route('panel.api.file-download') }}?path=${encodeURIComponent(this.selectedItem)}`, '_blank');
        },

        async searchFiles() {
            if (!this.searchQuery) { this.searchResults = []; return; }
            try {
                const params = new URLSearchParams({ path: this.currentPath, query: this.searchQuery, recursive: this.searchRecursive });
                const res = await fetch(`{{ route('panel.api.file-search') }}?${params}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                const data = await res.json();
                this.searchResults = data.results || [];
            } catch(e) { this.searchResults = []; }
        },

        toggleTerminal() {
            this.showTerminal = !this.showTerminal;
            if (this.showTerminal) {
                this.$nextTick(() => this.$refs.termInput?.focus());
            }
        },

        async loadTerminalState() {
            try {
                const res = await fetch('{{ route("panel.api.terminal-state") }}', {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const data = await res.json();
                this.termCwd = data.cwd;
                this.termDisplay = data.display;
            } catch (e) {}
        },

        async runTerminalCommand() {
            const cmd = this.termInput.trim();
            if (!cmd || this.termRunning) return;

            this.termCurrentCommand = cmd;
            this.termRunning = true;
            this.termInput = '';

            // Track command history (for arrow up/down)
            this.termCmdHistory.push(cmd);
            if (this.termCmdHistory.length > 50) this.termCmdHistory.shift();
            this.termHistoryIndex = this.termCmdHistory.length;

            const promptCwd = this.termDisplay;

            try {
                const res = await fetch('{{ route("panel.api.terminal-execute") }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrf },
                    body: JSON.stringify({ command: cmd })
                });
                const data = await res.json();

                // Special: clear screen
                if (data.clear) {
                    this.termHistory = [];
                } else {
                    this.termHistory.push({
                        command: cmd,
                        cwd: promptCwd,
                        output: data.output || '',
                        error: data.error || '',
                        exitCode: data.exit_code ?? 0,
                    });
                }

                this.termCwd = data.cwd;
                this.termDisplay = data.display;

                // If `cd` succeeded and panel is browsing the project, refresh file listing
                if (cmd.startsWith('cd ') || cmd === 'cd') {
                    // Optionally sync file panel — skip to keep them independent
                }
            } catch (e) {
                this.termHistory.push({
                    command: cmd,
                    cwd: promptCwd,
                    output: '',
                    error: '[hermes] permintaan gagal\n',
                    exitCode: 1,
                });
            }

            this.termRunning = false;
            this.termCurrentCommand = '';

            this.$nextTick(() => {
                const out = this.$refs.termOutput;
                if (out) out.scrollTop = out.scrollHeight;
                this.$refs.termInput?.focus();
            });
        },

        recallHistory(direction) {
            if (this.termCmdHistory.length === 0) return;
            this.termHistoryIndex += direction;
            if (this.termHistoryIndex < 0) this.termHistoryIndex = 0;
            if (this.termHistoryIndex >= this.termCmdHistory.length) {
                this.termHistoryIndex = this.termCmdHistory.length;
                this.termInput = '';
                return;
            }
            this.termInput = this.termCmdHistory[this.termHistoryIndex] || '';
        },

        clearTerminal() {
            this.termHistory = [];
            this.$refs.termInput?.focus();
        },

        async resetTerminal() {
            try {
                const res = await fetch('{{ route("panel.api.terminal-reset") }}', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrf }
                });
                const data = await res.json();
                this.termCwd = data.cwd;
                this.termDisplay = data.display;
                showToast('Cwd direset ke proyek aktif');
            } catch (e) { showToast('Gagal reset', 'error'); }
            this.$refs.termInput?.focus();
        }
    };
}
</script>
@endpush
