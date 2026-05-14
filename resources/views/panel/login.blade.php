<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ config('panel.name') }} — Akses</title>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    @vite(['resources/css/app.css'])
    <style>
        @keyframes typewriter {
            from { width: 0; }
            to { width: 100%; }
        }
        .typewriter-line {
            overflow: hidden;
            white-space: nowrap;
            border-right: 2px solid var(--copper);
            animation: typewriter 1.6s steps(40, end) 0.3s forwards, blink 1s 1.9s 3 forwards;
            width: 0;
        }
        @keyframes blink {
            from, to { border-color: transparent; }
            50% { border-color: var(--copper); }
        }

        @keyframes fade-in {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body class="min-h-screen flex items-stretch overflow-hidden relative">

    <div class="grain-overlay"></div>

    <!-- Side Rules -->
    <aside class="rule-side left">
        <span class="text">Hermes / Akses</span>
        <span class="text">N° 000</span>
    </aside>
    <aside class="rule-side right">
        <span class="text">Sesi Privat</span>
        <span class="text">{{ date('Y.m.d') }}</span>
    </aside>

    <!-- Left Panel: Editorial -->
    <div class="hidden lg:flex flex-1 flex-col justify-between p-16 border-r border-[color:var(--rule)] relative">
        <div class="font-mono text-[10px] tracking-[0.25em] uppercase text-paper-dim flex items-center gap-3" style="animation: fade-in 0.6s 0.1s backwards;">
            <span class="pulse-dot"></span>
            <span>Sistem siap menerima</span>
        </div>

        <div>
            <div class="font-mono text-[11px] tracking-[0.28em] uppercase text-copper mb-8 flex items-center gap-3"
                 style="animation: fade-in 0.6s 0.2s backwards;">
                <span class="w-12 h-px bg-copper"></span>
                Halaman Akses · N° 000
            </div>
            <h1 class="font-serif text-[clamp(48px,7vw,120px)] leading-[0.92] tracking-[-0.04em] mb-8"
                style="font-variation-settings: 'opsz' 144, 'wght' 400, 'WONK' 1; animation: fade-in 0.8s 0.3s backwards;">
                Selamat<br>
                <span class="italic text-copper" style="font-variation-settings: 'opsz' 144, 'wght' 300, 'SOFT' 80, 'WONK' 1;">datang</span>,<br>
                pemilik.
            </h1>
            <p class="font-serif text-lg text-paper-soft max-w-md leading-relaxed"
               style="animation: fade-in 0.6s 0.6s backwards;">
                <span class="italic text-copper">Hermes mengenal kamu</span> dengan kata sandi atau dengan pesan dari nomor yang sudah dikenal. Pilih jalan yang paling nyaman.
            </p>
        </div>

        <!-- Bottom matter -->
        <div class="border-t border-[color:var(--rule)] pt-6 grid grid-cols-3 gap-8" style="animation: fade-in 0.6s 0.8s backwards;">
            <div>
                <div class="font-mono text-[9px] tracking-[0.22em] uppercase text-paper-dim mb-2">// Sesi</div>
                <div class="font-serif italic text-2xl text-copper leading-none" style="font-variation-settings: 'opsz' 72, 'wght' 300, 'WONK' 1;">{{ config('panel.session_lifetime', 120) }}m</div>
            </div>
            <div>
                <div class="font-mono text-[9px] tracking-[0.22em] uppercase text-paper-dim mb-2">// Pemilik</div>
                <div class="font-serif italic text-2xl text-copper leading-none" style="font-variation-settings: 'opsz' 72, 'wght' 300, 'WONK' 1;">Tunggal</div>
            </div>
            <div>
                <div class="font-mono text-[9px] tracking-[0.22em] uppercase text-paper-dim mb-2">// Versi</div>
                <div class="font-serif italic text-2xl text-copper leading-none" style="font-variation-settings: 'opsz' 72, 'wght' 300, 'WONK' 1;">2.0</div>
            </div>
        </div>
    </div>

    <!-- Right Panel: Form -->
    <div class="flex-1 flex items-center justify-center p-8 lg:p-16 relative">
        <div class="w-full max-w-md" x-data="{ form: { username: '', password: '' }, loading: false }">

            <!-- Logo (mobile) -->
            <div class="lg:hidden text-center mb-12">
                <span class="font-serif italic text-5xl text-paper" style="font-variation-settings: 'opsz' 144, 'wght' 700, 'WONK' 1;">Hermes</span>
                <div class="font-mono text-[10px] tracking-[0.25em] uppercase text-copper mt-2">— Panel Akses</div>
            </div>

            <!-- Section Label -->
            <div class="mb-10" style="animation: fade-in 0.6s 0.2s backwards;">
                <div class="font-mono text-[11px] tracking-[0.28em] uppercase text-copper mb-3 flex items-center gap-3">
                    <span class="w-10 h-px bg-copper"></span>
                    Verifikasi
                </div>
                <h2 class="font-serif text-4xl text-paper leading-none tracking-tight" style="font-variation-settings: 'opsz' 144, 'wght' 500, 'WONK' 1;">
                    Masukkan <span class="italic text-copper" style="font-variation-settings: 'opsz' 144, 'wght' 300, 'SOFT' 60, 'WONK' 1;">kunci</span>.
                </h2>
            </div>

            <form method="POST" action="{{ route('panel.authenticate') }}"
                  @submit="loading = true"
                  class="space-y-6"
                  style="animation: fade-in 0.6s 0.4s backwards;">
                @csrf

                @if ($errors->any() || session('error'))
                <div class="border border-[color:var(--rust)] bg-[color:var(--rust)]/10 px-4 py-3 font-mono text-[11px] tracking-wider uppercase text-[color:var(--rust)]">
                    <div class="flex items-start gap-3">
                        <span class="font-serif italic text-base leading-none">!</span>
                        <span>{{ $errors->first('username') ?: $errors->first('password') ?: session('error') }}</span>
                    </div>
                </div>
                @endif

                <div>
                    <label for="username" class="label-mono flex items-center justify-between">
                        <span>Pengguna</span>
                        <span class="font-serif italic text-base text-copper leading-none" style="font-variation-settings: 'opsz' 72, 'wght' 300, 'WONK' 1;">α</span>
                    </label>
                    <input type="text" id="username" name="username"
                           x-model="form.username"
                           required
                           autocomplete="username"
                           autofocus
                           class="input-editorial"
                           placeholder="admin">
                </div>

                <div>
                    <label for="password" class="label-mono flex items-center justify-between">
                        <span>Kata sandi</span>
                        <span class="font-serif italic text-base text-copper leading-none" style="font-variation-settings: 'opsz' 72, 'wght' 300, 'WONK' 1;">β</span>
                    </label>
                    <input type="password" id="password" name="password"
                           x-model="form.password"
                           required
                           autocomplete="current-password"
                           class="input-editorial"
                           placeholder="••••••••">
                </div>

                <button type="submit"
                        :disabled="loading || !form.username || !form.password"
                        class="btn-copper w-full justify-center mt-8"
                        :class="{ 'disabled': loading || !form.username || !form.password }">
                    <span x-text="loading ? 'Memverifikasi…' : 'Buka Panel'"></span>
                    <span class="font-serif italic text-base" x-show="!loading">↗</span>
                    <svg x-show="loading" x-cloak class="animate-spin h-3.5 w-3.5" viewBox="0 0 24 24" fill="none">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                    </svg>
                </button>
            </form>

            <!-- Bottom Note -->
            <div class="mt-12 pt-6 border-t border-[color:var(--rule)] flex items-center justify-between font-mono text-[9px] tracking-[0.22em] uppercase text-paper-dim"
                 style="animation: fade-in 0.6s 0.6s backwards;">
                <a href="{{ route('landing') }}" class="hover:text-copper transition-colors flex items-center gap-2">
                    <span class="font-serif italic text-base leading-none">←</span> Kembali
                </a>
                <span>Sesi <span class="text-copper">terenkripsi</span></span>
            </div>

        </div>
    </div>

</body>
</html>
