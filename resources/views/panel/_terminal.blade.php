<div x-data="hermesTerminal()"
     x-init="init()"
     @hermes-terminal-toggle.window="toggle()"
     @hermes-project-changed.window="onProjectChange($event.detail?.project ?? null)"
     class="hermes-terminal-root">

    <!-- Floating panel -->
    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 translate-y-4"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 translate-y-4"
         :class="expanded ? 'hermes-terminal-panel-expanded' : (minimized ? 'hermes-terminal-panel-minimized' : 'hermes-terminal-panel')"
         class="fixed z-[60] bg-ink-soft border border-[color:var(--rule-strong)] shadow-[8px_8px_0_var(--copper-deep)] flex flex-col">

        <!-- Header -->
        <div class="flex items-center justify-between gap-3 px-4 py-2.5 border-b border-[color:var(--rule)] bg-ink">
            <div class="flex items-baseline gap-3 min-w-0">
                <span class="font-serif italic text-base text-copper truncate"
                      style="font-variation-settings: 'opsz' 60, 'wght' 400, 'WONK' 1;"
                      x-text="project || '—'"></span>
                <span class="font-mono text-[10px] tracking-[0.22em] uppercase text-paper-dim truncate"
                      x-text="display || cwd || '/'"></span>
            </div>
            <div class="flex items-center gap-1">
                <span class="font-mono text-[9px] tracking-[0.22em] uppercase mr-2"
                      :class="status === 'running' ? 'text-copper' : (status === 'done' ? 'text-paper-soft' : 'text-paper-dim')"
                      x-text="statusLabel()"></span>
                <button type="button" @click="reset()"
                        class="font-mono text-[10px] tracking-[0.22em] uppercase text-paper-dim hover:text-copper px-2 py-1 transition-colors"
                        title="Reset cwd & kill session">⟲</button>
                <button type="button" @click="minimized = !minimized; expanded = false"
                        class="font-mono text-[14px] leading-none text-paper-dim hover:text-copper px-2 py-1 transition-colors"
                        :title="minimized ? 'Restore' : 'Minimize'">_</button>
                <button type="button" @click="expanded = !expanded; minimized = false; persistViewState()"
                        class="font-mono text-[14px] leading-none text-paper-dim hover:text-copper px-2 py-1 transition-colors"
                        :title="expanded ? 'Restore size' : 'Expand'">⤢</button>
                <button type="button" @click="close()"
                        class="font-serif italic text-lg leading-none text-paper-dim hover:text-[color:var(--rust)] px-2 py-1 transition-colors"
                        title="Close">×</button>
            </div>
        </div>

        <!-- Body -->
        <div x-show="!minimized" class="flex-1 min-h-0 flex flex-col">
            <!-- Welcome banner -->
            <div x-show="showWelcome" x-cloak
                 class="px-4 py-3 border-b border-[color:var(--rule)] bg-ink/60 font-mono text-[11px] text-paper-soft">
                <div class="text-copper tracking-[0.22em] uppercase text-[10px] mb-1">// Hermes Terminal</div>
                <div class="leading-relaxed">
                    Project <span class="text-copper" x-text="project || 'none'"></span>
                    · cwd <span class="text-copper" x-text="display || cwd"></span><br>
                    Shortcuts: <code class="text-copper not-italic">↑↓</code> history,
                    <code class="text-copper not-italic">Ctrl+L</code> clear,
                    <code class="text-copper not-italic">Ctrl+F</code> search,
                    <code class="text-copper not-italic">Enter</code> run.
                </div>
            </div>

            <!-- xterm canvas -->
            <div x-ref="termContainer" class="flex-1 min-h-0 px-2 py-2 overflow-hidden bg-ink"></div>

            <!-- Input row -->
            <div class="flex items-center gap-2 px-3 py-2 border-t border-[color:var(--rule)] bg-ink-soft">
                <span class="font-mono text-copper text-[12px]">$</span>
                <input type="text"
                       x-ref="termInput"
                       x-model="inputBuffer"
                       @keydown.enter.prevent="run()"
                       @keydown.up.prevent="historyPrev()"
                       @keydown.down.prevent="historyNext()"
                       @keydown.tab.prevent
                       :disabled="status === 'running' || status === 'exiting'"
                       :placeholder="status === 'running' ? 'running…' : 'type a command'"
                       class="flex-1 bg-transparent border-none outline-none font-mono text-[12px] text-paper placeholder:text-paper-dim disabled:opacity-50">
                <button type="button" @click="run()"
                        :disabled="!inputBuffer.trim() || status === 'running' || status === 'exiting'"
                        class="font-mono text-[10px] tracking-[0.22em] uppercase px-3 py-1.5 border border-[color:var(--copper)] text-copper hover:bg-[color:var(--copper)] hover:text-ink transition-colors disabled:opacity-30 disabled:cursor-not-allowed">
                    Run
                </button>
                <button type="button" @click="stop()"
                        x-show="status === 'running' || status === 'exiting'"
                        class="font-mono text-[10px] tracking-[0.22em] uppercase px-3 py-1.5 border border-[color:var(--rust)] text-[color:var(--rust)] hover:bg-[color:var(--rust)] hover:text-paper transition-colors">
                    ⏹ Stop
                </button>
            </div>
        </div>
    </div>
</div>

<style>
    .hermes-terminal-panel {
        right: 1.5rem;
        bottom: 1.5rem;
        width: min(640px, calc(100vw - 3rem));
        height: min(420px, 60vh);
    }
    .hermes-terminal-panel-expanded {
        right: 1.5rem;
        bottom: 1.5rem;
        left: calc(280px + 1.5rem);
        top: 5rem;
        width: auto;
        height: auto;
    }
    .hermes-terminal-panel-minimized {
        right: 1.5rem;
        bottom: 1.5rem;
        width: min(360px, calc(100vw - 3rem));
        height: auto;
    }
    @media (max-width: 768px) {
        .hermes-terminal-panel,
        .hermes-terminal-panel-expanded {
            inset: 60px 0 80px 0;
            width: auto;
            height: auto;
        }
        .hermes-terminal-panel-minimized {
            right: 0.5rem;
            bottom: 80px;
            left: 0.5rem;
            width: auto;
        }
    }
</style>
