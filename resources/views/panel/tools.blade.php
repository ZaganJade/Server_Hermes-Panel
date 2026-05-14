@extends('panel.layout')

@section('title', 'Laravel Tools')

@section('content')
<div class="topbar">
    <div class="page-title">Laravel Tools</div>
    @if($currentProject)
        <span class="project-badge">{{ $currentProject['name'] }}</span>
    @endif
</div>

<!-- Command Categories -->
<div class="card">
    <div class="card-title" style="margin-bottom: 16px;">Quick Commands</div>
    
    <div class="tabs" style="margin-bottom: 20px;">
        <button class="tab active" onclick="showCategory('cache')">Cache & Config</button>
        <button class="tab" onclick="showCategory('migrate')">Database</button>
        <button class="tab" onclick="showCategory('queue')">Queue</button>
        <button class="tab" onclick="showCategory('logs')">Logs</button>
        <button class="tab" onclick="showCategory('custom')">Custom</button>
    </div>
    
    <!-- Cache Commands -->
    <div id="cat-cache" class="command-category">
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px;">
            <button class="btn btn-gray" onclick="runArtisan('cache:clear')">
                <i class="fas fa-broom"></i> cache:clear
            </button>
            <button class="btn btn-gray" onclick="runArtisan('config:clear')">
                <i class="fas fa-cog"></i> config:clear
            </button>
            <button class="btn btn-gray" onclick="runArtisan('view:clear')">
                <i class="fas fa-eye"></i> view:clear
            </button>
            <button class="btn btn-gray" onclick="runArtisan('route:clear')">
                <i class="fas fa-route"></i> route:clear
            </button>
            <button class="btn btn-gray" onclick="runArtisan('event:clear')">
                <i class="fas fa-bolt"></i> event:clear
            </button>
            <button class="btn btn-gray" onclick="runArtisan('optimize:clear')">
                <i class="fas fa-trash"></i> optimize:clear
            </button>
            <button class="btn btn-gray" onclick="runArtisan('config:cache')">
                <i class="fas fa-database"></i> config:cache
            </button>
            <button class="btn btn-gray" onclick="runArtisan('route:cache')">
                <i class="fas fa-save"></i> route:cache
            </button>
        </div>
    </div>
    
    <!-- Migrate Commands -->
    <div id="cat-migrate" class="command-category" style="display: none;">
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px;">
            <button class="btn btn-blue" onclick="runArtisan('migrate')">
                <i class="fas fa-play"></i> migrate
            </button>
            <button class="btn btn-red" onclick="runArtisan('migrate:fresh')">
                <i class="fas fa-refresh"></i> migrate:fresh
            </button>
            <button class="btn btn-gray" onclick="runArtisan('migrate:rollback')">
                <i class="fas fa-undo"></i> migrate:rollback
            </button>
            <button class="btn btn-gray" onclick="runArtisan('migrate:status')">
                <i class="fas fa-list"></i> migrate:status
            </button>
            <button class="btn btn-gray" onclick="runArtisan('db:seed')">
                <i class="fas fa-database"></i> db:seed
            </button>
        </div>
    </div>
    
    <!-- Queue Commands -->
    <div id="cat-queue" class="command-category" style="display: none;">
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px;">
            <button class="btn btn-gray" onclick="runArtisan('queue:restart')">
                <i class="fas fa-sync"></i> queue:restart
            </button>
            <button class="btn btn-gray" onclick="runArtisan('queue:flush')">
                <i class="fas fa-trash"></i> queue:flush
            </button>
            <button class="btn btn-gray" onclick="runArtisan('queue:prune-batches')">
                <i class="fas fa-clock"></i> queue:prune-batches
            </button>
            <button class="btn btn-gray" onclick="runArtisan('queue:work --once')">
                <i class="fas fa-play-circle"></i> queue:work --once
            </button>
        </div>
    </div>
    
    <!-- Logs -->
    <div id="cat-logs" class="command-category" style="display: none;">
        <div style="display: flex; gap: 12px; margin-bottom: 16px;">
            <select id="log-lines" class="select" style="width: 150px;">
                <option value="50">Last 50 lines</option>
                <option value="100" selected>Last 100 lines</option>
                <option value="200">Last 200 lines</option>
                <option value="500">Last 500 lines</option>
            </select>
            <button class="btn btn-blue" onclick="loadLogs()">
                <i class="fas fa-sync"></i> Refresh Logs
            </button>
        </div>
        <div id="log-content">
            <div class="loading"><i class="fas fa-spinner"></i> Loading logs...</div>
        </div>
    </div>
    
    <!-- Custom Command -->
    <div id="cat-custom" class="command-category" style="display: none;">
        <div style="display: flex; gap: 12px; margin-bottom: 16px;">
            <input type="text" id="custom-command" class="input" placeholder="php artisan make:controller UserController" style="flex: 1;">
            <button class="btn btn-blue" onclick="runCustomArtisan()">
                <i class="fas fa-terminal"></i> Run
            </button>
        </div>
        <div class="alert alert-warning">
            <i class="fas fa-info-circle"></i> Only whitelisted commands are allowed for security.
        </div>
    </div>
</div>

<!-- Terminal Output -->
<div class="card">
    <div class="card-title" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
        <span>Terminal Output</span>
        <div style="display: flex; gap: 8px;">
            <button class="btn btn-sm btn-gray" onclick="copyOutput()">
                <i class="fas fa-copy"></i> Copy
            </button>
            <button class="btn btn-sm btn-gray" onclick="clearOutput()">
                <i class="fas fa-trash"></i> Clear
            </button>
        </div>
    </div>
    <pre id="terminal-output" class="code-display" style="max-height: 400px; min-height: 150px;">$ Ready for commands...</pre>
</div>
@endsection

@section('scripts')
<script>
function showCategory(cat) {
    document.querySelectorAll('.command-category').forEach(el => el.style.display = 'none');
    document.querySelectorAll('.tab').forEach(el => el.classList.remove('active'));
    document.getElementById('cat-' + cat).style.display = 'block';
    event.target.classList.add('active');
    
    if (cat === 'logs') loadLogs();
}

async function runArtisan(command) {
    const output = document.getElementById('terminal-output');
    output.textContent = `$ php artisan ${command}\n\n`;
    output.textContent += '⏳ Running...\n\n';
    
    try {
        const response = await fetch('{{ route("panel.api.artisan") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ command })
        });
        const result = await response.json();
        
        if (result.output) {
            output.textContent += result.output + '\n';
        }
        if (result.error) {
            output.textContent += '\n❌ ERROR:\n' + result.error + '\n';
        }
        if (result.success) {
            output.textContent += '\n✅ Command completed successfully (exit code: ' + result.exit_code + ')';
        } else {
            output.textContent += '\n⚠️ Command finished with exit code: ' + result.exit_code;
        }
    } catch (e) {
        output.textContent += '\n❌ Request failed: ' + e.message;
    }
    
    output.scrollTop = output.scrollHeight;
}

async function runCustomArtisan() {
    const command = document.getElementById('custom-command').value.trim();
    if (!command) return alert('Please enter a command');
    runArtisan(command);
}

async function loadLogs() {
    const lines = document.getElementById('log-lines').value;
    const output = document.getElementById('log-content');
    
    output.innerHTML = '<div class="loading"><i class="fas fa-spinner"></i> Loading logs...</div>';
    
    try {
        const response = await fetch('{{ route("panel.api.logs") }}?lines=' + lines);
        const result = await response.json();
        
        if (result.error) {
            output.innerHTML = `<div class="alert alert-error">${result.error}</div>`;
            return;
        }
        
        if (result.logs && result.logs.length > 0) {
            let html = '<div class="code-display" style="max-height: 400px; overflow-y: auto;">';
            result.logs.forEach(line => {
                // Color code by level
                if (line.includes('ERROR') || line.includes('emergency')) {
                    html += `<span style="color: #ef4444;">${escapeHtml(line)}</span>\n`;
                } else if (line.includes('WARNING') || line.includes('alert')) {
                    html += `<span style="color: #f97316;">${escapeHtml(line)}</span>\n`;
                } else if (line.includes('INFO') || line.includes('notice')) {
                    html += `<span style="color: #38bdf8;">${escapeHtml(line)}</span>\n`;
                } else {
                    html += escapeHtml(line) + '\n';
                }
            });
            html += '</div>';
            output.innerHTML = html;
        } else {
            output.innerHTML = '<div class="alert alert-warning">No log entries found</div>';
        }
    } catch (e) {
        output.innerHTML = `<div class="alert alert-error">Failed to load logs: ${e.message}</div>`;
    }
}

function copyOutput() {
    const text = document.getElementById('terminal-output').textContent;
    navigator.clipboard.writeText(text).then(() => {
        showNotification('Copied to clipboard!', 'success');
    });
}

function clearOutput() {
    document.getElementById('terminal-output').textContent = '$ Ready for commands...';
}

function showNotification(message, type) {
    const status = document.createElement('div');
    status.style.cssText = 'position: fixed; bottom: 20px; right: 20px; background: #1e293b; border-left: 4px solid ' + 
        (type === 'success' ? '#22c55e' : type === 'error' ? '#ef4444' : '#38bdf8') + 
        '; padding: 12px 16px; border-radius: 8px; color: #e2e8f0; font-size: 13px; z-index: 1000;';
    status.innerHTML = message;
    document.body.appendChild(status);
    setTimeout(() => status.remove(), 3000);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Load logs on page load if tab is active
document.addEventListener('DOMContentLoaded', () => {
    // Pre-load logs
});

// Enter key for custom command
document.getElementById('custom-command').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') runCustomArtisan();
});
</script>
@endsection