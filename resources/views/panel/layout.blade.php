<!DOCTYPE html>
<html lang="id" x-data="panelApp()">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('panel.name') }} — @yield('title', 'Panel')</title>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6/css/all.min.css">
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen relative">

    <div class="grain-overlay"></div>

    <!-- Sidebar (Desktop) -->
    <aside class="hidden md:flex flex-col fixed left-0 top-0 bottom-0 z-40 bg-ink-soft border-r border-[color:var(--rule)]" style="width: 280px;">
        <!-- Logo -->
        <div class="px-7 py-7 border-b border-[color:var(--rule)]">
            <a href="{{ route('landing') }}" class="flex items-baseline gap-3 group">
                <span class="text-[28px] font-serif italic text-paper leading-none tracking-tight"
                      style="font-variation-settings: 'opsz' 144, 'wght' 700, 'WONK' 1;">Hermes</span>
                <span class="font-mono text-[9px] tracking-[0.25em] uppercase text-copper">— Panel</span>
            </a>
            <div class="mt-3 font-mono text-[9px] tracking-[0.2em] uppercase text-paper-dim flex items-center gap-2">
                <span class="pulse-dot"></span>
                <span>Sistem Aktif · v2.0</span>
            </div>
        </div>

        <!-- Section: Modul -->
        <div class="px-7 pt-6 pb-3">
            <div class="font-mono text-[9px] tracking-[0.25em] uppercase text-paper-dim flex items-center gap-2">
                <span>// Modul</span>
            </div>
        </div>

        <!-- Navigation -->
        <nav class="flex-1 px-4 space-y-px overflow-y-auto">
            @foreach ([
                'dashboard' => ['α', 'Dashboard', '001'],
                'database'  => ['β', 'Database',  '002'],
                'files'     => ['γ', 'Files',     '003'],
                'tools'     => ['δ', 'Tools',     '004'],
                'projects'  => ['ε', 'Projects',  '005'],
            ] as $route => [$glyph, $label, $num])
            @php $active = request()->routeIs("panel.$route"); @endphp
            <a href="{{ route("panel.$route") }}"
               class="group flex items-center justify-between px-4 py-3 transition-all relative
                      {{ $active ? 'bg-ink text-paper' : 'text-paper-soft hover:text-paper hover:bg-ink' }}">
                @if($active)
                <span class="absolute left-0 top-0 bottom-0 w-[3px] bg-copper"></span>
                @endif
                <span class="flex items-center gap-4">
                    <span class="glyph text-xl leading-none {{ $active ? '' : 'opacity-50 group-hover:opacity-100 transition-opacity' }}">{{ $glyph }}</span>
                    <span class="font-mono text-[11px] tracking-[0.18em] uppercase">{{ $label }}</span>
                </span>
                <span class="font-mono text-[9px] tracking-[0.2em] text-paper-dim">N°{{ $num }}</span>
            </a>
            @endforeach
        </nav>

        <!-- Section: Konteks -->
        <div class="px-7 pt-6 pb-3 border-t border-[color:var(--rule)]">
            <div class="font-mono text-[9px] tracking-[0.25em] uppercase text-paper-dim mb-3">// Proyek Aktif</div>
            <select id="project-switcher"
                    class="w-full bg-ink border border-[color:var(--rule-strong)] px-3 py-2.5 font-mono text-[11px] tracking-wider uppercase text-paper cursor-pointer focus:outline-none focus:border-copper transition"
                    style="background-image: url(&quot;data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12'%3E%3Cpath d='M3 5l3 3 3-3' stroke='%23d4a45c' stroke-width='1.2' fill='none'/%3E%3C/svg%3E&quot;); background-repeat: no-repeat; background-position: right 12px center; background-size: 10px; appearance: none; padding-right: 32px;"
                    onchange="switchProject(this.value)">
                <option value="">— Tidak Ada —</option>
            </select>
        </div>

        <!-- Bottom Controls -->
        <div class="px-7 py-5 border-t border-[color:var(--rule)] flex items-center justify-between">
            <span class="font-mono text-[9px] tracking-[0.22em] uppercase text-paper-dim">{{ date('Y.m.d') }}</span>
            <form method="POST" action="{{ route('panel.logout') }}" class="inline">
                @csrf
                <button type="submit"
                        class="font-mono text-[9px] tracking-[0.22em] uppercase text-paper-dim hover:text-rust transition-colors flex items-center gap-2">
                    Keluar <span class="font-serif italic text-base leading-none">↗</span>
                </button>
            </form>
        </div>
    </aside>

    <!-- Mobile Header -->
    <div class="md:hidden fixed top-0 left-0 right-0 z-50 bg-ink-soft border-b border-[color:var(--rule)] px-5 py-4 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <button @click="mobileMenu = !mobileMenu" class="text-paper-soft hover:text-copper transition">
                <i class="fas fa-bars text-base"></i>
            </button>
            <span class="font-serif italic text-xl text-paper" style="font-variation-settings: 'opsz' 144, 'wght' 700, 'WONK' 1;">Hermes</span>
        </div>
        <span class="font-mono text-[9px] tracking-[0.22em] uppercase text-copper">@yield('title', 'Panel')</span>
    </div>

    <!-- Mobile Menu -->
    <div x-show="mobileMenu" x-transition.opacity x-cloak class="md:hidden fixed inset-0 z-40 bg-black/80 backdrop-blur-sm" @click="mobileMenu = false"></div>
    <aside x-show="mobileMenu" x-transition x-cloak class="md:hidden fixed left-0 top-0 bottom-0 z-50 bg-ink-soft border-r border-[color:var(--rule)] w-[280px] flex flex-col overflow-y-auto">
        <div class="px-7 py-7 border-b border-[color:var(--rule)] flex items-center justify-between">
            <span class="font-serif italic text-2xl text-paper" style="font-variation-settings: 'opsz' 144, 'wght' 700, 'WONK' 1;">Hermes</span>
            <button @click="mobileMenu = false" class="text-paper-dim hover:text-paper"><i class="fas fa-xmark"></i></button>
        </div>
        <nav class="flex-1 px-4 py-4 space-y-px">
            @foreach ([
                'dashboard' => ['α', 'Dashboard', '001'],
                'database'  => ['β', 'Database',  '002'],
                'files'     => ['γ', 'Files',     '003'],
                'tools'     => ['δ', 'Tools',     '004'],
                'projects'  => ['ε', 'Projects',  '005'],
            ] as $route => [$glyph, $label, $num])
            @php $active = request()->routeIs("panel.$route"); @endphp
            <a href="{{ route("panel.$route") }}" @click="mobileMenu = false"
               class="flex items-center justify-between px-4 py-3 {{ $active ? 'bg-ink text-paper' : 'text-paper-soft' }}">
                <span class="flex items-center gap-4">
                    <span class="glyph text-xl leading-none">{{ $glyph }}</span>
                    <span class="font-mono text-[11px] tracking-[0.18em] uppercase">{{ $label }}</span>
                </span>
                <span class="font-mono text-[9px] tracking-[0.2em] text-paper-dim">N°{{ $num }}</span>
            </a>
            @endforeach
        </nav>
        <div class="px-7 py-5 border-t border-[color:var(--rule)]">
            <form method="POST" action="{{ route('panel.logout') }}">
                @csrf
                <button type="submit" class="font-mono text-[10px] tracking-[0.22em] uppercase text-rust">Keluar →</button>
            </form>
        </div>
    </aside>

    <!-- Main -->
    <main class="md:ml-[280px] mt-[60px] md:mt-0 min-h-screen flex flex-col">
        <!-- Header -->
        <header class="sticky top-0 z-20 bg-ink/90 backdrop-blur border-b border-[color:var(--rule)] px-8 py-5 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <span class="section-label">@yield('section-label', 'Modul')</span>
                <span class="font-mono text-[10px] tracking-[0.2em] text-paper-dim">/</span>
                <span class="font-mono text-[10px] tracking-[0.2em] uppercase text-paper">@yield('breadcrumb', 'Dashboard')</span>
            </div>
            <div class="flex items-center gap-3 font-mono text-[10px] tracking-[0.2em] uppercase">
                <span class="text-paper-dim">Proyek:</span>
                <span id="active-project-name" class="text-copper border border-[color:var(--rule-strong)] px-3 py-1.5">— None —</span>
            </div>
        </header>

        <!-- Content -->
        <div class="flex-1 px-8 py-10 @yield('content-class', '')">
            @yield('content')
        </div>

        <!-- Footer Strip -->
        <footer class="border-t border-[color:var(--rule)] px-8 py-5 flex items-center justify-between font-mono text-[9px] tracking-[0.22em] uppercase text-paper-dim">
            <span>Hermes <span class="text-copper">/</span> ©{{ date('Y') }}</span>
            <span>Build {{ str_pad('001', 3, '0', STR_PAD_LEFT) }} · Laravel {{ app()->version() }}</span>
        </footer>
    </main>

    <!-- Toasts -->
    <div x-data="{ toasts: [], add(message, type = 'success') {
        const id = Date.now();
        this.toasts.push({ id, message, type });
        setTimeout(() => this.toasts = this.toasts.filter(t => t.id !== id), 3500);
    }}"
         x-init="window.showToast = (msg, type) => add(msg, type)"
         class="fixed bottom-8 right-8 z-50 space-y-3">
        <template x-for="toast in toasts" :key="toast.id">
            <div x-transition.opacity
                 class="toast"
                 :class="`toast-${toast.type}`"
                 x-text="toast.message"></div>
        </template>
    </div>

    @stack('scripts')

    <script>
        function panelApp() {
            return {
                mobileMenu: false,
            };
        }

        function switchProject(projectName) {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            fetch('{{ route("panel.api.project-switch") }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({ project: projectName })
            }).then(r => r.json()).then(data => {
                if (data.success) location.reload();
                else if (window.showToast) window.showToast(data.error || 'Switch gagal', 'error');
            });
        }
    </script>
</body>
</html>
