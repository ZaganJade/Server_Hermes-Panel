# Hermes Panel — Mobile UI Optimization Plan

**Version:** 1.0  
**Date:** 2026-05-16  
**Author:** Hermes Agent (subagent)  
**Status:** Ready for User Review  
**Reference Design:** Linear.app (dark admin UI) + Hermes ink/paper/copper system

---

## 1. Executive Summary

Hermes Panel sudah punya fondasi mobile (hamburger menu, responsive grid) tapi belum optimal. Analisis 7 halaman menemukan: **3 halaman kritis** perlu refactor, **3 halaman quick win**, dan **1 halaman sudah baik**. Target: membuat panel usable di layar < 768px tanpa merusak pengalaman desktop.

**Referensi desain:** Linear.app — ultra-minimal dark-mode, precision typography, semi-transparent borders. Kami adopsi pola: near-black bg → elevated surfaces via luminance stepping, copper accent sebagai satu-satunya warna chromatic, semi-transparent borders (`rgba(244,237,225,0.08)`) instead of solid lines.

---

## 2. Prioritized Optimization Order

| Priority | Halaman | Reason | Effort |
|----------|---------|--------|--------|
| **P1 - Critical** | `files.blade.php` | 6-column grid tidak muat di mobile, toolbar crowded | High |
| **P2 - Critical** | `database.blade.php` | Tables dengan banyak kolom, SQL editor textarea tall | High |
| **P3 - Critical** | `layout.blade.php` | Sidebar hamburger perlu polish, content padding cramped | High |
| **P4 - Medium** | `tools.blade.php` | Artisan form grid, queue table overflow | Medium |
| **P5 - Low** | `dashboard.blade.php` | Stat grid already responsive, modal mobile test needed | Low |
| **P6 - Low** | `projects.blade.php` | Card grid already responsive | Low |
| **P7 - Already Good** | `login.blade.php` | Two-panel split already hides left on mobile | None |

---

## 3. Current State Analysis

### 3.1 layout.blade.php — Sidebar & Header

**Findings:**
- Hamburger menu exists (`mobileMenu` Alpine state) — GOOD
- Sidebar: `hidden md:flex fixed left-0 top-0 bottom-0` — correct pattern
- Mobile header: `fixed top-0 left-0 right-0 z-50` — exists
- Mobile drawer: `x-show="mobileMenu"` with backdrop — exists
- Main content: `md:ml-[280px]` offset — correct
- Content padding: `px-8 py-10` — **TOO WIDE for mobile**

**Mobile Issues:**
1. Content area `px-8 py-10` terlalu lapang di mobile (32px+ padding)
2. Header sticky `z-20` mungkin bentrok dengan mobile menu drawer
3. Breadcrumb text `font-mono text-[10px]` cukup readable tapi area tappable kecil

**Linear Reference — Navigation Pattern:**
```
Background: #0f1011 (panel dark)
Border: 1px solid rgba(255,255,255,0.05)
Links: Inter 13px weight 510, color #d0d6e0
Active: text #f7f8f8, left accent bar (copper)
Mobile: hamburger collapse at 768px, full-height drawer
```

### 3.2 dashboard.blade.php — Beranda

**Findings:**
- Stat grid: `grid grid-cols-2 lg:grid-cols-4` — **already mobile-friendly** (2 cols on mobile)
- Title: `class="title-editorial"` (clamp-based) — already responsive
- Quick actions: `flex flex-wrap gap-3` — **already wrapping**
- Project cards: `grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3` — **already responsive**
- Modal logs: `max-width: 800px` — readable on mobile
- Session time display: `hidden lg:block` — correct (hide on mobile)

**Quick Wins:**
- Stat numbers: `font-serif text-[44px]` large but readable on mobile
- Empty state: centered, good spacing

### 3.3 files.blade.php — File Manager

**Findings (Critical):**
- Action toolbar: `flex flex-wrap items-center gap-2 justify-end` — wrapping, GOOD
- **File listing grid: 6 columns — TOO MANY for mobile**
  ```
  grid-cols-[1fr_120px_140px_100px_60px_60px]
  → Columns: Name | Size | Modified | Permissions | Type | Actions
  ```
  At mobile (375px), this is ~58px per column — text truncate, unreadable
- Search popup: `absolute right-0 top-full mt-2 w-80` — **overflow on mobile**
- Terminal: `height: 320px` fixed — okay but takes significant screen
- Editor modal: `max-width: 480px` — okay
- Breadcrumb: already wrapping — GOOD

**Fixes Needed:**
1. File listing → card view on mobile, table on desktop
2. Search dropdown → full-width modal on mobile
3. Action toolbar → collapsible menu or stacked buttons

### 3.4 database.blade.php — Database Manager

**Findings:**
- Connection selector: `flex flex-wrap items-center gap-6` — wrapping, GOOD
- Tabs: `tabs-editorial` — horizontal, **might need scroll or stacked on mobile**
- Tables: `overflow-x-auto` on container — horizontal scroll, GOOD
- Browse data: same pattern, GOOD
- **SQL editor textarea: `rows="8"` — too tall on mobile** (should be 4-5)
- Export buttons: `flex gap-2` — small but readable
- Pagination: `flex items-center justify-between` — might compress

**Fixes Needed:**
1. SQL textarea → `rows="4"` on mobile
2. Tabs → consider vertical or horizontal scroll on mobile
3. Pagination buttons → slightly larger touch targets

### 3.5 tools.blade.php — Laravel Tools

**Findings:**
- Artisan form: `grid grid-cols-1 lg:grid-cols-[1fr_240px_auto]` — **already responsive** (stacked on mobile)
- Logs filter: `grid grid-cols-1 sm:grid-cols-[180px_1fr_auto_auto]` — stacked on mobile, GOOD
- Queue table: `overflow-x-auto` — horizontal scroll, GOOD
- Composer/NPM cards: `grid grid-cols-1 md:grid-cols-2` — stacked on mobile, GOOD
- Buttons: `btn-mini` with `px-3 py-1.5` — **touch targets borderline** (44px ideal, this is ~36px)

**Quick Wins:**
1. Button touch targets: increase padding slightly on mobile
2. Output pre blocks: `max-h-[480px]` — might need reduction on mobile

### 3.6 projects.blade.php — Project Management

**Findings:**
- Project cards: `grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3` — **already responsive**
- Card content: status badges, metadata table, action buttons — all readable
- Hidden projects: vertical list — GOOD
- Modals: `modal-card` class — should be responsive

**Status:** Already well-optimized. Minor check: modal delete confirmation text field readable on mobile.

### 3.7 login.blade.php — Authentication

**Findings:**
- Left panel: `hidden lg:flex flex-1` — **already hides on mobile**
- Right panel: `flex-1 flex items-center justify-center p-8 lg:p-16` — **already responsive**
- Form: standard inputs, good spacing
- Logo mobile: `lg:hidden text-center mb-12` — shows on mobile, GOOD

**Status:** Already well-optimized for mobile.

---

## 4. Specific Mobile Improvements

### 4.1 layout.blade.php — Sidebar & Content Shell

**Quick Wins:**
```html
<!-- 1. Reduce content padding on mobile -->
BEFORE: class="flex-1 px-8 py-10"
AFTER:  class="flex-1 px-4 md:px-8 py-6 md:py-10"
```

**Big Refactors:**
```html
<!-- 2. Improve mobile menu z-index & backdrop -->
<!-- Current: z-40 backdrop, z-50 drawer — good, but add blur -->
<div x-show="mobileMenu" 
     x-transition.opacity 
     x-cloak 
     class="md:hidden fixed inset-0 z-40 bg-black/80 backdrop-blur-md"
     @click="mobileMenu = false">
```

```html
<!-- 3. Add bottom nav for quick page switching on mobile (optional enhancement) -->
<!-- Place below main content on mobile only -->
<div class="md:hidden fixed bottom-0 left-0 right-0 z-30 bg-ink-soft border-t border-[color:var(--rule)] flex">
  <a href="..." class="flex-1 py-3 text-center text-paper-dim hover:text-copper">α</a>
  <a href="..." class="flex-1 py-3 text-center text-paper-dim hover:text-copper">β</a>
  <a href="..." class="flex-1 py-3 text-center text-paper-dim hover:text-copper">γ</a>
  <a href="..." class="flex-1 py-3 text-center text-paper-dim hover:text-copper">δ</a>
</div>
```

### 4.2 files.blade.php — File Manager

**Quick Wins:**
```html
<!-- 1. Reduce action toolbar button size on mobile -->
BEFORE: class="btn-mini" (small padding)
AFTER:  class="btn-mini px-3 py-2 text-[10px] md:text-[11px]"
```

**Big Refactors — Card View on Mobile:**
```html
<!-- File listing: responsive grid → cards on mobile, table on desktop -->
<!-- Desktop: 6-column grid | Mobile: stacked cards -->

<!-- Current grid (desktop only): -->
<div class="grid grid-cols-[1fr_120px_140px_100px_60px_60px] gap-3 px-8 py-2.5">

<!-- Proposed: hide headers on mobile, show as cards -->
<div class="md:grid md:grid-cols-[1fr_120px_140px_100px_60px_60px] gap-3 md:px-8 py-2.5
            grid grid-cols-1 gap-2 px-4 py-3">
    <!-- On mobile: Name + Size in one row, rest hidden -->
    <!-- On desktop: full 6-column table -->
```

**Better approach — File list card pattern:**
```html
<!-- Mobile: stacked card with all info visible -->
<div class="md:hidden bg-ink-soft border border-[color:var(--rule)] p-4 mb-2">
    <div class="flex items-center justify-between mb-2">
        <span class="glyph text-lg">▤</span>
        <span class="font-mono text-[12px] text-paper truncate" x-text="file.name"></span>
    </div>
    <div class="flex items-center gap-4 font-mono text-[10px] text-paper-dim">
        <span x-text="file.size"></span>
        <span x-text="file.extension"></span>
    </div>
</div>

<!-- Desktop: full table row -->
<div class="hidden md:grid grid-cols-[1fr_120px_140px_100px_60px_60px] ...">
    <!-- existing table row -->
</div>
```

**Search dropdown → Modal on mobile:**
```html
<!-- Change absolute dropdown to modal on mobile -->
<div x-show="openSearch" 
     x-cloak 
     class="absolute right-0 top-full mt-2 bg-ink-soft border border-[color:var(--rule-strong)] p-4 w-80 z-30 shadow-xl
            md:hidden">
    <!-- On mobile, make this full-width or larger -->
</div>
```

### 4.3 database.blade.php — Database Manager

**Quick Wins:**
```html
<!-- 1. SQL textarea rows reduction on mobile -->
<textarea x-model="query" rows="8" class="textarea-editorial"
          rows="4 md:rows-8"></textarea>

<!-- 2. Tabs: ensure horizontal scroll on mobile if needed -->
<div class="tabs-editorial overflow-x-auto">
    <!-- tabs content -->
</div>

<!-- 3. Pagination buttons: slightly larger -->
<button class="btn-mini px-4 py-2.5">←</button>
```

**Big Refactors:**
```html
<!-- Tables: ensure horizontal scroll with sticky first column on mobile -->
<div class="border border-[color:var(--rule)] overflow-x-auto">
    <table class="table-editorial min-w-[600px]"><!-- min-width ensures scroll -->
```

### 4.4 tools.blade.php — Laravel Tools

**Quick Wins:**
```html
<!-- 1. Increase button touch targets on mobile -->
<button class="btn-mini px-4 py-2.5 md:px-3 md:py-1.5">
    <!-- Mobile: larger, desktop: original size -->
</button>

<!-- 2. Output area: reduce max-height on mobile -->
<pre class="max-h-[320px] md:max-h-[480px] ...">
```

### 4.5 dashboard.blade.php & projects.blade.php

**Status:** Already responsive. Minor verification:
```html
<!-- Check stat grid text doesn't overflow on very small screens -->
<div class="font-serif text-[36px] md:text-[44px] ...">
    <!-- clamp() already handles this -->
```

---

## 5. Responsive Breakpoints Strategy

### Current Breakpoints (Tailwind defaults)
| Breakpoint | Width | Current Behavior |
|------------|-------|-------------------|
| `sm` | 640px | Rarely used |
| `md` | 768px | Sidebar shows, content offset |
| `lg` | 1024px | Dashboard stat grid → 4 cols |
| `xl` | 1280px | Project grid → 3 cols |
| `2xl` | 1536px | Not used |

### Proposed Breakpoint Refinements

```css
/* In app.css or @layer utilities */
@layer utilities {
    /* Mobile-first content padding */
    .content-padding {
        @apply px-4 py-6;
    }
    @screen md {
        .content-padding {
            @apply px-8 py-10;
        }
    }
    
    /* File list: cards on mobile, table on desktop */
    .file-list-item {
        @apply block md:hidden; /* Mobile card */
    }
    .file-list-row {
        @apply hidden md:grid; /* Desktop table row */
    }
}
```

### Breakpoint Map
```
Mobile Small  (< 375px): Single column, card view for files, condensed padding
Mobile        (375-640px): Same as above, slightly more breathing room
Tablet        (640-768px): Begin introducing horizontal scroll for tables
Desktop Small (768-1024px): Sidebar visible, content area comfortable
Desktop       (1024-1280px): Full layout, 2-3 column grids active
Desktop Large (> 1280px): Maximum comfort, generous whitespace
```

---

## 6. Quick Wins vs Big Refactors

### Quick Wins ( ≤ 1 hour each)

| # | File | Change | Impact |
|---|------|--------|--------|
| Q1 | `layout.blade.php` | Reduce `px-8 py-10` → `px-4 py-6` on mobile | High |
| Q2 | `database.blade.php` | SQL textarea `rows="8"` → `rows="4"` on mobile | Medium |
| Q3 | `tools.blade.php` | Increase `btn-mini` padding on mobile: `px-4 py-2.5` | Medium |
| Q4 | `files.blade.php` | Search dropdown → `w-full md:w-80` on mobile | Low |
| Q5 | `dashboard.blade.php` | Verify stat grid 2-col works at 320px width | Low |

### Big Refactors ( 2-4 hours each)

| # | File | Change | Impact |
|---|------|--------|--------|
| B1 | `files.blade.php` | Card-based file list on mobile, table on desktop | High |
| B2 | `layout.blade.php` | Polish mobile menu: backdrop blur, bottom nav (optional) | High |
| B3 | `database.blade.php` | Tables with sticky first column, improved horizontal scroll | Medium |

### Effort Estimate
- Quick Wins: ~3 hours total (5 items)
- Big Refactors: ~8-10 hours total (3 items)
- **Total: ~11-13 hours**

---

## 7. Reference Design — Linear.app Dark Admin Pattern

### Key Visual Principles to Apply

**1. Luminance-based Elevation (not shadow-based)**
```
Level 0 (flat):     bg: var(--ink) #0e0d0a
Level 1 (surface):  bg: var(--ink-soft) #15130f
Level 2 (elevated):  bg: var(--ink-card) #1a1812
```
Hermes already has this pattern — continue using it.

**2. Semi-transparent Borders Instead of Solid Lines**
```css
/* Current (okay but could be softer) */
border: 1px solid rgba(244, 237, 225, 0.10);

/* Linear-inspired refinement */
border: 1px solid rgba(244, 237, 225, 0.06); /* even more subtle */
border: 1px solid rgba(244, 237, 225, 0.12); /* for cards/elevated */
```

**3. Touch Targets — Minimum 44px**
```html
<!-- Buttons on mobile: at least 44px height -->
<button class="h-11 px-4 ..."><!-- 44px = h-11 in Tailwind -->
```

**4. Spacing Scale — 8px Base**
```
xs:  4px  (gap-1)
sm:  8px  (gap-2)
md:  16px (gap-4)
lg:  24px (gap-6)
xl:  32px (gap-8)
2xl: 48px (gap-12)
```

**5. Typography — Compress at Scale**
```css
/* Display text: negative letter-spacing */
.title-editorial {
    letter-spacing: -0.03em; /* tighten large text */
}

/* Body text: normal or slight tracking */
.body-text {
    letter-spacing: 0;
}
```

### Component Token Mapping (Linear → Hermes)

| Linear Token | Value | Hermes Equivalent |
|---|---|---|
| Panel Background | `#0f1011` | `--ink-soft` (#15130f) |
| Elevated Surface | `#191a1b` | `--ink-card` (#1a1812) |
| Primary Text | `#f7f8f8` | `--paper` (#f4ede1) |
| Secondary Text | `#d0d6e0` | `--paper-soft` (#ddd2bd) |
| Muted Text | `#8a8f98` | `--paper-dim` (#8a8275) |
| Border Standard | `rgba(255,255,255,0.08)` | `rgba(244,237,225,0.08)` |
| Border Subtle | `rgba(255,255,255,0.05)` | `rgba(244,237,225,0.06)` |
| Accent | `#7170ff` (indigo) | `--copper` (#d4a45c) |
| Success | `#10b981` | `--verdigris` (#5a7a5a) |
| Danger | `#ef4444` | `--rust` (#b85c44) |

---

## 8. Implementation Checklist

### Phase 1: Quick Wins (Before coding session)

- [ ] **Q1:** Update `layout.blade.php` content padding
- [ ] **Q2:** Update `database.blade.php` SQL textarea rows
- [ ] **Q3:** Update `tools.blade.php` button touch targets
- [ ] **Q4:** Update `files.blade.php` search dropdown width
- [ ] **Q5:** Test `dashboard.blade.php` at 320px viewport

### Phase 2: Big Refactors (Coding session)

- [ ] **B1:** Implement card-based file list for `files.blade.php`
- [ ] **B2:** Polish mobile menu with backdrop blur in `layout.blade.php`
- [ ] **B3:** Enhance table horizontal scroll in `database.blade.php`

### Phase 3: Verification (After coding)

- [ ] Test all pages at 320px, 375px, 768px, 1024px, 1280px
- [ ] Verify hamburger menu opens/closes smoothly
- [ ] Check all buttons are tappable (≥ 44px)
- [ ] Confirm tables scroll horizontally without breaking layout
- [ ] Review logs modal on mobile (dashboard)

---

## 9. Files to Modify

| File | Changes | Type |
|------|---------|------|
| `resources/views/panel/layout.blade.php` | Content padding, mobile menu polish | Quick Win + Big Refactor |
| `resources/views/panel/files.blade.php` | Card view on mobile, toolbar adjustments | Big Refactor |
| `resources/views/panel/database.blade.php` | SQL textarea, table scroll, tabs | Quick Win + Big Refactor |
| `resources/views/panel/tools.blade.php` | Button touch targets, output max-height | Quick Win |
| `resources/views/panel/dashboard.blade.php` | Test at small viewport | Quick Win |
| `resources/views/panel/projects.blade.php` | Verify responsiveness | Quick Win |
| `resources/css/app.css` | Add mobile utility classes | Supporting |

---

## 10. User Decision Points

Tuan Udin, sebelum coding dimulai, mohon konfirmasi:

1. **Bottom Navigation Bar** — Apakah perlu ditambahkan bottom nav (α β γ δ ε) untuk quick page switching di mobile? Ini finger-friendly tapi mengubah layout signifikan. (Default: skip, gunakan hamburger only)

2. **File List Card vs Table** — Card view untuk files di mobile lebih readable tapi butuh lebih banyak vertical space. Alternative: tetap table dengan horizontal scroll. (Default: card view)

3. **Sidebar Persistence** — Pada tablet (768px-1024px), sidebar sudah visible. Apakah ingin sidebar auto-hide on tablet juga, atau tetap visible? (Default: tetap visible di tablet, hide only on mobile)

4. **Timeline** — Apakah ini urgent atau bisa dilakukan piece-by-piece? Quick wins bisa langsung, big refactors perlu planning lebih lanjut.

---

*Plan prepared using `sketch` (ideation) + `popular-web-designs` (Linear.app reference). Ready for Tuan Udin's review and approval.*