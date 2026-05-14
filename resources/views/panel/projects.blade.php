@extends('panel.layout')

@section('title', 'Projects')
@section('section-label', 'Modul · N° 005')
@section('breadcrumb', 'Projects')

@section('content')
<div x-data="projectsApp()">

    <!-- Editorial Header -->
    <div class="mb-12 animate-fade-up">
        <div class="grid lg:grid-cols-[1fr_auto] gap-8 items-end pb-8 border-b border-[color:var(--rule)]">
            <div>
                <div class="section-label mb-6">Direktori Proyek</div>
                <h1 class="title-editorial">
                    Semua proyek<br>
                    <span class="italic">terdaftar</span>.
                </h1>
                <p class="font-serif text-base text-paper-soft leading-relaxed max-w-lg mt-6">
                    Kumpulan proyek Laravel yang ditemukan otomatis di
                    <span class="italic text-copper">{{ config('panel.projects_dir', 'Project') }}/</span>
                    plus yang ditambahkan manual.
                </p>
            </div>
            <button @click="showAddForm = true" class="btn-copper">
                <span>Tambah Proyek</span>
                <span class="font-serif italic text-base leading-none">+</span>
            </button>
        </div>
    </div>

    <!-- Project Grid -->
    @if(count($allProjects) > 0)
    <section class="animate-fade-up-1 mb-16">
        <div class="flex items-center justify-between mb-6">
            <span class="section-label">Aktif</span>
            <span class="font-mono text-[9px] tracking-[0.22em] uppercase text-paper-dim">/ {{ count($allProjects) }} proyek</span>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-px bg-[color:var(--rule)] border border-[color:var(--rule)]">
            @foreach ($allProjects as $name => $project)
            @php
                $i = $loop->iteration;
                $isActive = session('active_project') === $name;
            @endphp
            <article class="bg-ink p-7 hover:bg-ink-soft transition-colors flex flex-col group relative {{ $isActive ? 'ring-1 ring-copper -m-px' : '' }}">

                @if($isActive)
                <span class="absolute top-0 left-0 right-0 h-[3px] bg-copper"></span>
                <span class="absolute top-3 right-3 font-mono text-[8px] tracking-[0.25em] uppercase bg-copper text-ink px-2 py-0.5">Aktif</span>
                @endif

                <!-- Header -->
                <div class="flex items-start justify-between mb-6 pb-5 border-b border-[color:var(--rule)]">
                    <div class="flex-1 min-w-0">
                        <div class="font-mono text-[9px] tracking-[0.22em] uppercase text-paper-dim mb-2">N° {{ str_pad($i, 3, '0', STR_PAD_LEFT) }}</div>
                        <h3 class="font-serif text-2xl text-paper leading-tight truncate" style="font-variation-settings: 'opsz' 60, 'wght' 500, 'WONK' 1;">
                            {{ $project['display_name'] ?? $name }}
                        </h3>
                        <div class="font-mono text-[10px] tracking-wider uppercase text-paper-dim mt-1.5 truncate">{{ $name }}</div>
                    </div>
                    @if($project['manual'] ?? false)
                    <span class="font-mono text-[8px] tracking-[0.22em] uppercase border border-[color:var(--rule-strong)] px-2 py-1 text-paper-soft shrink-0 ml-2">Manual</span>
                    @else
                    <span class="font-mono text-[8px] tracking-[0.22em] uppercase border border-[color:var(--copper)]/50 text-copper px-2 py-1 shrink-0 ml-2">{{ strtoupper($project['type']) }}</span>
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

                <!-- Actions -->
                <div class="mt-6 pt-5 border-t border-[color:var(--rule)] flex items-center gap-2">
                    <button @click="switchProject('{{ $name }}')"
                            class="flex-1 font-mono text-[10px] tracking-[0.22em] uppercase text-paper-dim hover:text-copper transition-colors flex items-center justify-between group/btn py-1">
                        <span>Buka</span>
                        <span class="font-serif italic text-base leading-none transition-transform group-hover/btn:translate-x-1">↗</span>
                    </button>
                    <button @click="hideProject('{{ $name }}')" title="Sembunyikan"
                            class="font-mono text-[9px] tracking-wider uppercase text-paper-dim hover:text-paper transition-colors px-2 py-1 border-l border-[color:var(--rule)]">
                        Sembunyi
                    </button>
                    <button @click="confirmDelete('{{ $name }}')" title="Hapus permanen"
                            class="font-serif italic text-lg leading-none text-paper-dim hover:text-[color:var(--rust)] transition-colors px-2 py-0.5 border-l border-[color:var(--rule)]">
                        ✕
                    </button>
                </div>
            </article>
            @endforeach
        </div>
    </section>
    @else
    <div class="text-center py-24 border border-[color:var(--rule)] mb-16 animate-fade-up-1">
        <div class="glyph text-6xl mb-6 opacity-50">∅</div>
        <p class="font-serif italic text-xl text-paper-soft mb-2">Belum ada proyek terdaftar.</p>
        <p class="font-mono text-[10px] tracking-[0.22em] uppercase text-paper-dim">
            Letakkan proyek di <code class="text-copper not-italic">{{ config('panel.projects_dir', 'Project') }}/</code> atau tambah manual.
        </p>
    </div>
    @endif

    <!-- Hidden Projects -->
    @if(!empty($hiddenProjects))
    <section class="animate-fade-up-2">
        <div class="flex items-center justify-between mb-6">
            <span class="section-label">Tersembunyi</span>
            <span class="font-mono text-[9px] tracking-[0.22em] uppercase text-paper-dim">/ {{ count($hiddenProjects) }} item</span>
        </div>
        <div class="border border-[color:var(--rule)]">
            @foreach(array_keys($hiddenProjects) as $hiddenName)
            <div class="flex items-center justify-between px-6 py-4 border-b border-[color:var(--rule)] last:border-0">
                <div class="flex items-center gap-4">
                    <span class="font-mono text-[10px] tracking-[0.22em] uppercase text-paper-dim">N° {{ str_pad($loop->iteration, 3, '0', STR_PAD_LEFT) }}</span>
                    <span class="font-serif italic text-paper-soft">{{ $hiddenName }}</span>
                </div>
                <button @click="unhideProject('{{ $hiddenName }}')"
                        class="font-mono text-[10px] tracking-[0.22em] uppercase text-paper-dim hover:text-copper transition-colors flex items-center gap-2">
                    Tampilkan <span class="font-serif italic text-base leading-none">↗</span>
                </button>
            </div>
            @endforeach
        </div>
    </section>
    @endif

    <!-- Add Project Modal -->
    <div x-show="showAddForm" x-cloak class="modal-overlay" @click.self="showAddForm = false">
        <div class="modal-card">
            <div class="modal-header">
                <h3 class="modal-title">Tambah <span class="italic">manual</span></h3>
                <button @click="showAddForm = false" class="text-paper-dim hover:text-copper text-xl leading-none">×</button>
            </div>
            <div class="modal-body space-y-5">
                <p class="font-serif text-sm text-paper-soft leading-relaxed">
                    Tambahkan proyek dari path absolut. Berguna kalau proyek berada di luar direktori
                    <span class="italic text-copper">{{ config('panel.projects_dir', 'Project') }}/</span>.
                </p>
                <div>
                    <label class="label-mono">Nama Proyek</label>
                    <input type="text" x-model="newName" placeholder="my-project" class="input-editorial">
                </div>
                <div>
                    <label class="label-mono">Path Absolut</label>
                    <input type="text" x-model="newPath" placeholder="/home/user/project" class="input-editorial">
                </div>
                <div class="flex gap-3 pt-4 border-t border-[color:var(--rule)]">
                    <button @click="showAddForm = false" class="btn-ghost flex-1 justify-center">Batal</button>
                    <button @click="addProject()" :disabled="!newName || !newPath || adding" class="btn-copper flex-1 justify-center" :class="{ 'disabled': !newName || !newPath || adding }">
                        <span x-text="adding ? 'Menambahkan…' : 'Tambah'"></span>
                        <span class="font-serif italic" x-show="!adding">↗</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div x-show="showDeleteModal" x-cloak class="modal-overlay" @click.self="showDeleteModal = false; deleteConfirm = ''">
        <div class="modal-card" style="border-color: var(--rust); box-shadow: 8px 8px 0 var(--rust);">
            <div class="modal-header" style="border-color: rgba(184, 92, 68, 0.3);">
                <h3 class="modal-title" style="color: var(--rust);">Hapus <span class="italic">permanen</span></h3>
                <button @click="showDeleteModal = false; deleteConfirm = ''" class="text-paper-dim hover:text-[color:var(--rust)] text-xl leading-none">×</button>
            </div>
            <div class="modal-body space-y-5">
                <div class="border border-[color:var(--rust)]/40 bg-[color:var(--rust)]/10 p-4">
                    <p class="font-serif text-sm text-paper leading-relaxed">
                        Tindakan ini akan menghapus <span class="italic font-semibold" x-text="deleteName"></span> dan <strong>seluruh berkasnya</strong> dari disk. Tidak bisa diurungkan.
                    </p>
                </div>
                <div>
                    <label class="label-mono">Ketik <span class="text-[color:var(--rust)] not-italic font-mono" x-text="deleteName"></span> untuk konfirmasi</label>
                    <input type="text" x-model="deleteConfirm" class="input-editorial" style="border-color: var(--rule-strong);">
                </div>
                <div class="flex gap-3 pt-4 border-t border-[color:var(--rule)]">
                    <button @click="showDeleteModal = false; deleteConfirm = ''" class="btn-ghost flex-1 justify-center">Batal</button>
                    <button @click="deleteProject()" :disabled="deleteConfirm !== deleteName" class="btn-danger flex-1 justify-center" :class="{ 'opacity-40 cursor-not-allowed': deleteConfirm !== deleteName }">
                        Hapus Selamanya
                    </button>
                </div>
            </div>
        </div>
    </div>

</div>
@endsection

@push('scripts')
<script>
function projectsApp() {
    return {
        showAddForm: false,
        showDeleteModal: false,
        newName: '',
        newPath: '',
        adding: false,
        deleteName: '',
        deleteConfirm: '',
        csrf: document.querySelector('meta[name="csrf-token"]')?.content || '',

        addProject() {
            this.adding = true;
            fetch('{{ route("panel.api.project-add") }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrf },
                body: JSON.stringify({ name: this.newName, path: this.newPath })
            }).then(r => r.json()).then(data => {
                this.adding = false;
                if (data.success) { this.showAddForm = false; location.reload(); }
                else showToast(data.error || 'Gagal menambah', 'error');
            }).catch(() => { this.adding = false; showToast('Permintaan gagal', 'error'); });
        },
        switchProject(name) { switchProject(name); },
        hideProject(name) {
            fetch('{{ route("panel.api.project-hide") }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrf },
                body: JSON.stringify({ name })
            }).then(r => r.json()).then(d => { if (d.success) location.reload(); });
        },
        unhideProject(name) {
            fetch('{{ route("panel.api.project-unhide") }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrf },
                body: JSON.stringify({ name })
            }).then(r => r.json()).then(d => { if (d.success) location.reload(); });
        },
        confirmDelete(name) { this.deleteName = name; this.deleteConfirm = ''; this.showDeleteModal = true; },
        deleteProject() {
            if (this.deleteConfirm !== this.deleteName) return;
            fetch('{{ route("panel.api.project-delete") }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrf },
                body: JSON.stringify({ name: this.deleteName, confirm_name: this.deleteConfirm })
            }).then(r => r.json()).then(d => {
                if (d.success) { this.showDeleteModal = false; location.reload(); }
                else showToast(d.error || 'Gagal menghapus', 'error');
            });
        }
    };
}
</script>
@endpush
