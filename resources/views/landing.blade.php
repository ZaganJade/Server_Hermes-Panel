<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ config('panel.name') }} — Server Administration</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght,SOFT,WONK@0,9..144,300..900,0..100,0..1;1,9..144,300..900,0..100,0..1&family=JetBrains+Mono:ital,wght@0,300..800;1,300..800&display=swap" rel="stylesheet">

    <style>
        :root {
            --ink: #0e0d0a;
            --ink-soft: #15130f;
            --paper: #f4ede1;
            --paper-soft: #ddd2bd;
            --paper-dim: #8a8275;
            --copper: #d4a45c;
            --copper-deep: #a87a3c;
            --verdigris: #5a7a5a;
            --rule: rgba(244, 237, 225, 0.12);
            --rule-strong: rgba(244, 237, 225, 0.28);
            --serif: 'Fraunces', 'Apple Garamond', serif;
            --mono: 'JetBrains Mono', ui-monospace, monospace;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        ::selection { background: var(--copper); color: var(--ink); }

        html { scroll-behavior: smooth; }

        body {
            background: var(--ink);
            color: var(--paper);
            font-family: var(--serif);
            font-variation-settings: 'opsz' 14, 'SOFT' 30;
            -webkit-font-smoothing: antialiased;
            overflow-x: hidden;
            position: relative;
        }

        /* Grain overlay */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 100;
            opacity: 0.06;
            mix-blend-mode: overlay;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='200' height='200'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='2' stitchTiles='stitch'/></filter><rect width='100%25' height='100%25' filter='url(%23n)' opacity='1'/></svg>");
        }

        /* Vignette */
        body::after {
            content: '';
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 99;
            background: radial-gradient(ellipse at center, transparent 30%, rgba(0,0,0,0.5) 100%);
        }

        /* Side rulers */
        .rule-left, .rule-right {
            position: fixed;
            top: 0;
            bottom: 0;
            width: 60px;
            border-color: var(--rule);
            z-index: 10;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: space-between;
            padding: 32px 0;
            font-family: var(--mono);
            font-size: 9px;
            letter-spacing: 0.15em;
            color: var(--paper-dim);
            text-transform: uppercase;
            pointer-events: none;
        }

        .rule-left { left: 0; border-right: 1px solid var(--rule); }
        .rule-right { right: 0; border-left: 1px solid var(--rule); }

        .rule-left .text, .rule-right .text {
            writing-mode: vertical-rl;
            transform: rotate(180deg);
        }

        .rule-right .text { transform: rotate(0deg); writing-mode: vertical-rl; }

        @media (max-width: 768px) {
            .rule-left, .rule-right { display: none; }
        }

        /* Tick marks */
        .ticks {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            justify-content: space-around;
            padding: 80px 0;
        }
        .tick {
            width: 8px;
            height: 1px;
            background: var(--rule-strong);
        }

        /* Top bar */
        nav {
            position: fixed;
            top: 0;
            left: 60px;
            right: 60px;
            z-index: 50;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 22px 40px;
            border-bottom: 1px solid var(--rule);
            background: rgba(14, 13, 10, 0.85);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }

        @media (max-width: 768px) {
            nav { left: 0; right: 0; padding: 16px 20px; }
        }

        .logo {
            display: flex;
            align-items: baseline;
            gap: 12px;
        }

        .logo-mark {
            font-family: var(--serif);
            font-variation-settings: 'opsz' 144, 'wght' 700, 'WONK' 1;
            font-style: italic;
            font-size: 28px;
            letter-spacing: -0.02em;
            color: var(--paper);
            line-height: 1;
        }

        .logo-tag {
            font-family: var(--mono);
            font-size: 9px;
            letter-spacing: 0.25em;
            color: var(--copper);
            text-transform: uppercase;
        }

        .nav-link {
            font-family: var(--mono);
            font-size: 11px;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: var(--paper);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 14px;
            padding: 10px 18px;
            border: 1px solid var(--paper);
            background: transparent;
            transition: all 0.4s cubic-bezier(0.65, 0, 0.35, 1);
            position: relative;
            overflow: hidden;
        }

        .nav-link::before {
            content: '';
            position: absolute;
            inset: 0;
            background: var(--paper);
            transform: translateY(101%);
            transition: transform 0.4s cubic-bezier(0.65, 0, 0.35, 1);
            z-index: -1;
        }

        .nav-link:hover { color: var(--ink); }
        .nav-link:hover::before { transform: translateY(0); }
        .nav-link .arrow { font-family: var(--serif); font-style: italic; font-size: 14px; }

        /* Hero */
        .hero {
            min-height: 100vh;
            padding: 140px 100px 80px;
            position: relative;
            display: grid;
            grid-template-columns: 1fr;
            align-items: end;
        }

        @media (max-width: 768px) {
            .hero { padding: 110px 24px 60px; }
        }

        .hero-meta {
            position: absolute;
            top: 130px;
            left: 100px;
            font-family: var(--mono);
            font-size: 10px;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            color: var(--paper-dim);
            display: flex;
            gap: 24px;
            align-items: center;
        }

        @media (max-width: 768px) { .hero-meta { left: 24px; flex-wrap: wrap; gap: 12px; } }

        .hero-meta .dot {
            width: 6px;
            height: 6px;
            background: var(--copper);
            border-radius: 50%;
            animation: pulse 2.4s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(0.7); }
        }

        .hero-eyebrow {
            font-family: var(--mono);
            font-size: 11px;
            letter-spacing: 0.3em;
            text-transform: uppercase;
            color: var(--copper);
            margin-bottom: 32px;
            display: flex;
            align-items: center;
            gap: 14px;
            opacity: 0;
            animation: fade-up 1s 0.2s forwards;
        }

        .hero-eyebrow::before {
            content: '';
            width: 48px;
            height: 1px;
            background: var(--copper);
        }

        h1.hero-title {
            font-family: var(--serif);
            font-variation-settings: 'opsz' 144, 'wght' 400, 'SOFT' 0, 'WONK' 1;
            font-size: clamp(56px, 11vw, 180px);
            line-height: 0.92;
            letter-spacing: -0.04em;
            color: var(--paper);
            margin-bottom: 48px;
            opacity: 0;
            animation: fade-up 1.2s 0.4s forwards;
        }

        .hero-title .italic {
            font-style: italic;
            font-variation-settings: 'opsz' 144, 'wght' 300, 'SOFT' 100, 'WONK' 1;
            color: var(--copper);
        }

        .hero-title .small {
            display: block;
            font-size: 0.4em;
            font-style: italic;
            font-variation-settings: 'opsz' 72, 'wght' 300;
            color: var(--paper-dim);
            margin-top: 0.2em;
            letter-spacing: 0;
        }

        .hero-bottom {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 80px;
            align-items: end;
            border-top: 1px solid var(--rule);
            padding-top: 32px;
        }

        @media (max-width: 768px) {
            .hero-bottom { grid-template-columns: 1fr; gap: 32px; }
        }

        .hero-desc {
            max-width: 520px;
            font-size: 17px;
            line-height: 1.55;
            color: var(--paper-soft);
            font-variation-settings: 'opsz' 14, 'wght' 400;
            opacity: 0;
            animation: fade-up 1s 0.7s forwards;
        }

        .hero-desc .lead {
            font-style: italic;
            color: var(--copper);
        }

        .hero-cta-group {
            display: flex;
            flex-direction: column;
            gap: 16px;
            align-items: flex-end;
            opacity: 0;
            animation: fade-up 1s 0.9s forwards;
        }

        @media (max-width: 768px) { .hero-cta-group { align-items: stretch; } }

        .cta-primary {
            font-family: var(--mono);
            font-size: 12px;
            letter-spacing: 0.22em;
            text-transform: uppercase;
            color: var(--ink);
            background: var(--copper);
            text-decoration: none;
            padding: 22px 36px;
            display: inline-flex;
            align-items: center;
            gap: 18px;
            border: 1px solid var(--copper);
            transition: all 0.4s cubic-bezier(0.65, 0, 0.35, 1);
            position: relative;
        }

        .cta-primary:hover {
            background: var(--paper);
            border-color: var(--paper);
            transform: translate(-3px, -3px);
            box-shadow: 6px 6px 0 var(--copper-deep);
        }

        .cta-primary .arrow {
            font-family: var(--serif);
            font-style: italic;
            font-size: 18px;
            transition: transform 0.4s;
        }

        .cta-primary:hover .arrow { transform: translateX(6px); }

        .cta-meta {
            font-family: var(--mono);
            font-size: 10px;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: var(--paper-dim);
            text-align: right;
        }

        @media (max-width: 768px) { .cta-meta { text-align: left; } }

        @keyframes fade-up {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Feature section */
        .features {
            padding: 120px 100px;
            border-top: 1px solid var(--rule);
            position: relative;
        }

        @media (max-width: 768px) { .features { padding: 80px 24px; } }

        .section-label {
            font-family: var(--mono);
            font-size: 11px;
            letter-spacing: 0.3em;
            text-transform: uppercase;
            color: var(--copper);
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .section-label::before {
            content: '/ /';
            color: var(--paper-dim);
            font-size: 10px;
        }

        .section-title {
            font-family: var(--serif);
            font-variation-settings: 'opsz' 144, 'wght' 400, 'WONK' 1;
            font-size: clamp(40px, 6vw, 88px);
            line-height: 1;
            letter-spacing: -0.03em;
            margin-bottom: 80px;
            max-width: 900px;
        }

        .section-title .italic {
            font-style: italic;
            color: var(--copper);
        }

        .feature-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1px;
            background: var(--rule);
            border: 1px solid var(--rule);
        }

        @media (max-width: 900px) {
            .feature-grid { grid-template-columns: 1fr; }
        }

        .feature {
            background: var(--ink);
            padding: 48px 36px;
            position: relative;
            transition: background 0.5s ease;
            grid-column: span 4;
            min-height: 320px;
            display: flex;
            flex-direction: column;
        }

        @media (max-width: 900px) { .feature { grid-column: 1 !important; } }

        .feature:hover { background: var(--ink-soft); }

        .feature-num {
            font-family: var(--mono);
            font-size: 11px;
            letter-spacing: 0.18em;
            color: var(--paper-dim);
            margin-bottom: 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .feature-num .glyph {
            font-family: var(--serif);
            font-style: italic;
            font-size: 26px;
            color: var(--copper);
            font-variation-settings: 'opsz' 72, 'wght' 300, 'WONK' 1;
        }

        .feature-title {
            font-family: var(--serif);
            font-variation-settings: 'opsz' 60, 'wght' 500, 'WONK' 1;
            font-size: 30px;
            line-height: 1.05;
            letter-spacing: -0.02em;
            margin-bottom: 16px;
            color: var(--paper);
        }

        .feature-title .italic { font-style: italic; color: var(--copper); }

        .feature-desc {
            font-family: var(--serif);
            font-size: 14.5px;
            line-height: 1.55;
            color: var(--paper-soft);
            font-variation-settings: 'opsz' 14, 'wght' 400;
            margin-bottom: auto;
        }

        .feature-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 32px;
            font-family: var(--mono);
            font-size: 9px;
            letter-spacing: 0.15em;
            text-transform: uppercase;
        }

        .feature-tag {
            padding: 5px 10px;
            border: 1px solid var(--rule-strong);
            color: var(--paper-dim);
        }

        /* Stats strip */
        .stats {
            border-top: 1px solid var(--rule);
            border-bottom: 1px solid var(--rule);
            padding: 60px 100px;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1px;
            background: var(--rule);
        }

        @media (max-width: 768px) {
            .stats { padding: 40px 24px; grid-template-columns: repeat(2, 1fr); }
        }

        .stat {
            background: var(--ink);
            padding: 20px;
            text-align: left;
        }

        .stat-num {
            font-family: var(--serif);
            font-variation-settings: 'opsz' 144, 'wght' 300, 'WONK' 1;
            font-style: italic;
            font-size: clamp(48px, 6vw, 80px);
            line-height: 1;
            color: var(--copper);
            margin-bottom: 8px;
            letter-spacing: -0.04em;
        }

        .stat-label {
            font-family: var(--mono);
            font-size: 10px;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            color: var(--paper-dim);
        }

        /* Quote / Manifesto */
        .manifesto {
            padding: 140px 100px;
            text-align: center;
            position: relative;
        }

        @media (max-width: 768px) { .manifesto { padding: 80px 24px; } }

        .manifesto blockquote {
            font-family: var(--serif);
            font-variation-settings: 'opsz' 144, 'wght' 300, 'SOFT' 50, 'WONK' 1;
            font-style: italic;
            font-size: clamp(28px, 4.5vw, 56px);
            line-height: 1.15;
            color: var(--paper);
            max-width: 1000px;
            margin: 0 auto;
            letter-spacing: -0.02em;
        }

        .manifesto blockquote .accent { color: var(--copper); }

        .manifesto-attr {
            font-family: var(--mono);
            font-size: 10px;
            letter-spacing: 0.25em;
            text-transform: uppercase;
            color: var(--paper-dim);
            margin-top: 48px;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 14px;
        }

        .manifesto-attr::before, .manifesto-attr::after {
            content: '';
            width: 32px;
            height: 1px;
            background: var(--copper);
        }

        /* CTA Bottom */
        .cta-block {
            padding: 100px 100px 80px;
            border-top: 1px solid var(--rule);
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            gap: 80px;
            align-items: end;
        }

        @media (max-width: 900px) {
            .cta-block { grid-template-columns: 1fr; padding: 70px 24px; gap: 40px; }
        }

        .cta-headline {
            font-family: var(--serif);
            font-variation-settings: 'opsz' 144, 'wght' 400, 'WONK' 1;
            font-size: clamp(40px, 6vw, 80px);
            line-height: 0.95;
            letter-spacing: -0.03em;
        }

        .cta-headline .italic { font-style: italic; color: var(--copper); }

        .cta-side {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        .cta-side p {
            font-family: var(--serif);
            font-size: 16px;
            line-height: 1.55;
            color: var(--paper-soft);
        }

        /* Footer */
        footer {
            padding: 32px 100px;
            border-top: 1px solid var(--rule);
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            gap: 24px;
            align-items: center;
            font-family: var(--mono);
            font-size: 10px;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: var(--paper-dim);
        }

        @media (max-width: 768px) {
            footer { padding: 24px; grid-template-columns: 1fr; text-align: center; }
        }

        footer .center { text-align: center; }
        footer .right { text-align: right; }
        @media (max-width: 768px) { footer .right { text-align: center; } }

        footer span.accent { color: var(--copper); }

        /* CRT scanline (subtle) */
        @keyframes scan {
            0% { transform: translateY(-100%); }
            100% { transform: translateY(100vh); }
        }

        .scanline {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 100vh;
            pointer-events: none;
            z-index: 98;
            opacity: 0.025;
            background: linear-gradient(180deg, transparent 0%, var(--copper) 50%, transparent 100%);
            mix-blend-mode: screen;
            animation: scan 8s linear infinite;
        }
    </style>
</head>
<body>

    <div class="scanline"></div>

    <!-- Side Rules -->
    <div class="rule-left">
        <span class="text">Hermes / 2026</span>
        <span class="text">N° 001</span>
    </div>
    <div class="rule-right">
        <span class="text">Server / Admin</span>
        <span class="text">Edisi Pertama</span>
    </div>

    <!-- Nav -->
    <nav>
        <div class="logo">
            <span class="logo-mark">Hermes</span>
            <span class="logo-tag">— Panel</span>
        </div>
        <a href="{{ route('panel.login') }}" class="nav-link">
            Masuk
            <span class="arrow">→</span>
        </a>
    </nav>

    <!-- Hero -->
    <section class="hero">
        <div class="hero-meta">
            <span class="dot"></span>
            <span>Sistem Aktif</span>
            <span>·</span>
            <span>v2.0 / Édition Reissue</span>
            <span>·</span>
            <span>{{ date('d.m.Y') }}</span>
        </div>

        <div>
            <div class="hero-eyebrow">Pesan dari para dewa server</div>

            <h1 class="hero-title">
                Kelola<br>
                <span class="italic">server</span>nya,<br>
                bukan <span class="italic">kepalanya</span>.
                <span class="small">— sebuah panel administrasi untuk Laravel.</span>
            </h1>

            <div class="hero-bottom">
                <div class="hero-desc">
                    <span class="lead">Hermes adalah utusan</span> antara kamu dan VPS. Database, file, queue, terminal, log — diatur dari satu jendela. Tanpa SSH yang menggigit jari, tanpa ritual yang tak perlu.
                </div>
                <div class="hero-cta-group">
                    <a href="{{ route('panel.login') }}" class="cta-primary">
                        Buka Panel
                        <span class="arrow">↗</span>
                    </a>
                    <div class="cta-meta">
                        Akses untuk pemilik /<br>
                        sesi terenkripsi
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features -->
    <section class="features" id="features">
        <div class="section-label">Indeks Kemampuan</div>
        <h2 class="section-title">
            Enam <span class="italic">utusan</span>,<br>
            satu <span class="italic">panel</span>.
        </h2>

        <div class="feature-grid">

            <article class="feature">
                <div class="feature-num">
                    <span>N° 001 / Database</span>
                    <span class="glyph">α</span>
                </div>
                <h3 class="feature-title">Manajer <span class="italic">basis data</span></h3>
                <p class="feature-desc">Jelajahi tabel. Edit baris langsung. Tulis SQL bebas. Ekspor ke JSON atau CSV. Dukungan multi-koneksi MySQL & PostgreSQL — pindah-pindah tanpa restart.</p>
                <div class="feature-tags">
                    <span class="feature-tag">MySQL</span>
                    <span class="feature-tag">Postgres</span>
                    <span class="feature-tag">SQL Editor</span>
                </div>
            </article>

            <article class="feature">
                <div class="feature-num">
                    <span>N° 002 / Files</span>
                    <span class="glyph">β</span>
                </div>
                <h3 class="feature-title">Manajer <span class="italic">berkas</span></h3>
                <p class="feature-desc">Telusuri direktori, sunting kode, unggah lewat drag-and-drop, unduh sebagai zip. Ganti hak akses. Cari di subfolder. Terminal bawaan satu klik.</p>
                <div class="feature-tags">
                    <span class="feature-tag">Editor</span>
                    <span class="feature-tag">Upload</span>
                    <span class="feature-tag">chmod</span>
                </div>
            </article>

            <article class="feature">
                <div class="feature-num">
                    <span>N° 003 / Tools</span>
                    <span class="glyph">γ</span>
                </div>
                <h3 class="feature-title">Peralatan <span class="italic">Laravel</span></h3>
                <p class="feature-desc">Artisan, Composer, NPM dari antarmuka. Pantau log dengan filter level. Kelola failed jobs. Restart queue tanpa membuka terminal.</p>
                <div class="feature-tags">
                    <span class="feature-tag">Artisan</span>
                    <span class="feature-tag">Queue</span>
                    <span class="feature-tag">Log</span>
                </div>
            </article>

            <article class="feature">
                <div class="feature-num">
                    <span>N° 004 / Multi-Project</span>
                    <span class="glyph">δ</span>
                </div>
                <h3 class="feature-title">Banyak <span class="italic">proyek</span>, satu rumah</h3>
                <p class="feature-desc">Penemuan otomatis semua proyek Laravel di direktori. Tambah manual via path. Sembunyikan atau hapus permanen. Pindah konteks instan.</p>
                <div class="feature-tags">
                    <span class="feature-tag">Auto-discover</span>
                    <span class="feature-tag">Switching</span>
                </div>
            </article>

            <article class="feature">
                <div class="feature-num">
                    <span>N° 005 / Terminal</span>
                    <span class="glyph">ε</span>
                </div>
                <h3 class="feature-title">Terminal di <span class="italic">peramban</span></h3>
                <p class="feature-desc">xterm.js + Reverb WebSocket. Sepenuhnya seperti SSH — tanpa pembatasan command. Cwd otomatis ke proyek aktif. Untuk saat-saat manual itu masih dibutuhkan.</p>
                <div class="feature-tags">
                    <span class="feature-tag">xterm.js</span>
                    <span class="feature-tag">WebSocket</span>
                </div>
            </article>

            <article class="feature">
                <div class="feature-num">
                    <span>N° 006 / Security</span>
                    <span class="glyph">ζ</span>
                </div>
                <h3 class="feature-title">Aman, <span class="italic">tunggal</span>, sederhana</h3>
                <p class="feature-desc">Panel pemilik tunggal — tanpa ruwetnya manajemen pengguna. Login password, bypass nomor WhatsApp opsional. Proteksi path traversal di semua I/O berkas.</p>
                <div class="feature-tags">
                    <span class="feature-tag">Single-Owner</span>
                    <span class="feature-tag">Encrypted</span>
                </div>
            </article>

        </div>
    </section>

    <!-- Stats -->
    <section class="stats">
        <div class="stat">
            <div class="stat-num">06</div>
            <div class="stat-label">Modul Inti</div>
        </div>
        <div class="stat">
            <div class="stat-num">∞</div>
            <div class="stat-label">Proyek Laravel</div>
        </div>
        <div class="stat">
            <div class="stat-num">0</div>
            <div class="stat-label">Biaya Berlangganan</div>
        </div>
        <div class="stat">
            <div class="stat-num">1</div>
            <div class="stat-label">Pemilik, Tunggal</div>
        </div>
    </section>

    <!-- Manifesto -->
    <section class="manifesto">
        <blockquote>
            "Bukan setiap perintah <span class="accent">layak ditulis dua kali.</span> Bukan setiap server <span class="accent">layak diingat namanya.</span> Hermes mengingat, supaya kamu tidak harus."
        </blockquote>
        <div class="manifesto-attr">Filosofi Panel — N° 001</div>
    </section>

    <!-- CTA Block -->
    <section class="cta-block">
        <div>
            <div class="section-label">Mulai sekarang</div>
            <h2 class="cta-headline">
                Tinggal <span class="italic">satu</span><br>
                tombol <span class="italic">jauhnya</span>.
            </h2>
        </div>
        <div class="cta-side">
            <p>Tidak ada onboarding. Tidak ada wizard. Tidak ada panggilan penjualan. Buka panelnya, masuk, mulai mengelola.</p>
            <a href="{{ route('panel.login') }}" class="cta-primary" style="align-self: flex-start;">
                Akses Panel
                <span class="arrow">↗</span>
            </a>
        </div>
    </section>

    <!-- Footer -->
    <footer>
        <div>Hermes Panel <span class="accent">— ©{{ date('Y') }}</span></div>
        <div class="center">{{ str_pad('001', 3, '0', STR_PAD_LEFT) }} / {{ date('Y.m.d') }} / Build Laravel {{ app()->version() }}</div>
        <div class="right"><span class="accent">Pemilik tunggal</span> · privat</div>
    </footer>

</body>
</html>
