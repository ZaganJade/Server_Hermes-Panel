@extends('panel.layout')

@section('title', 'Dashboard')

@section('content')
<!-- Top Bar -->
<div class="topbar">
    <div class="page-title">Dashboard</div>
    @if($currentProject)
        <span class="project-badge">{{ $currentProject['name'] }}</span>
    @endif
</div>

<!-- Stats -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-table"></i></div>
        <div>
            <div class="card-title">Database Tables</div>
            <div class="card-value">{{ $stats['tables'] }}</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-file-code"></i></div>
        <div>
            <div class="card-title">Project Files</div>
            <div class="card-value">{{ number_format($stats['files']) }}</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple"><i class="fas fa-hard-drive"></i></div>
        <div>
            <div class="card-title">Storage Used</div>
            <div class="card-value">{{ $stats['storage_used'] }}</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orange"><i class="fas fa-clock"></i></div>
        <div>
            <div class="card-title">Last Activity</div>
            <div class="card-value" style="font-size: 16px;">{{ $stats['last_activity'] }}</div>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="card">
    <div class="card-title" style="margin-bottom: 16px;">Quick Actions</div>
    <div style="display: flex; gap: 12px; flex-wrap: wrap;">
        <a href="{{ route('panel.database') }}" class="btn btn-blue">
            <i class="fas fa-database"></i> Browse Database
        </a>
        <a href="{{ route('panel.files') }}" class="btn btn-blue">
            <i class="fas fa-folder-open"></i> File Manager
        </a>
        <a href="{{ route('panel.tools') }}" class="btn btn-blue">
            <i class="fas fa-terminal"></i> Laravel Tools
        </a>
        <button class="btn btn-gray" onclick="runQuickAction('cache:clear')">
            <i class="fas fa-broom"></i> Clear Cache
        </button>
        <button class="btn btn-gray" onclick="runQuickAction('route:list')">
            <i class="fas fa-route"></i> Route List
        </button>
    </div>
</div>

<!-- Project Info -->
@if($currentProject)
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
    <div class="card">
        <div class="card-title">Project Details</div>
        <div style="display: grid; gap: 12px;">
            <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #334155;">
                <span style="color: #64748b;">Name</span>
                <span style="color: #e2e8f0; font-weight: 500;">{{ $currentProject['name'] }}</span>
            </div>
            <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #334155;">
                <span style="color: #64748b;">Path</span>
                <span style="color: #94a3b8; font-size: 12px; max-width: 200px; overflow: hidden; text-overflow: ellipsis;">{{ $currentProject['path'] }}</span>
            </div>
            <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #334155;">
                <span style="color: #64748b;">DB Database</span>
                <span style="color: #e2e8f0;">{{ $currentProject['env']['DB_DATABASE'] ?? '-' }}</span>
            </div>
            <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #334155;">
                <span style="color: #64748b;">DB Host</span>
                <span style="color: #e2e8f0;">{{ $currentProject['env']['DB_HOST'] ?? '127.0.0.1' }}</span>
            </div>
            <div style="display: flex; justify-content: space-between; padding: 8px 0;">
                <span style="color: #64748b;">PHP Version</span>
                <span style="color: #e2e8f0;">{{ PHP_VERSION }}</span>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-title">Project Status</div>
        <div id="status-check">
            <div class="loading"><i class="fas fa-spinner"></i> Checking project status...</div>
        </div>
    </div>
</div>
@else
<div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle"></i> No project configured. Go to <a href="{{ route('panel.projects') }}" style="color: #f97316;">Projects</a> to add a project.
</div>
@endif

<!-- Terminal Output -->
<div class="card" id="terminal-card" style="display: none;">
    <div class="card-title" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
        <span>Terminal Output</span>
        <button class="btn btn-sm btn-gray" onclick="clearTerminal()"><i class="fas fa-trash"></i> Clear</button>
    </div>
    <pre id="terminal-output" class="code-display" style="max-height: 200px;"></pre>
</div>
@endsection

@section('scripts')
<script>
async function runQuickAction(command) {
    const terminal = document.getElementById('terminal-card');
    const output = document.getElementById('terminal-output');
    terminal.style.display = 'block';
    output.textContent = `> php artisan ${command}\n\n`;
    output.textContent += '⏳ Running...\n';
    
    try {
        const response = await fetch('{{ route("panel.api.artisan") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ command })
        });
        const result = await response.json();
        
        output.textContent += '\n' + (result.output || result.error || 'Command completed');
        if (!result.success && result.error) {
            output.textContent += '\n❌ ' + result.error;
        } else if (result.success) {
            output.textContent += '\n✅ Done';
        }
    } catch (e) {
        output.textContent += '\n❌ Error: ' + e.message;
    }
}

function clearTerminal() {
    document.getElementById('terminal-output').textContent = '';
    document.getElementById('terminal-card').style.display = 'none';
}

// Check project status on load
document.addEventListener('DOMContentLoaded', async () => {
    try {
        const response = await fetch('{{ route("panel.api.status") }}');
        const status = await response.json();
        
        let html = '<div style="display: grid; gap: 8px;">';
        html += statusCard('Project Directory', status.project_exists, '{{ $currentProject["path"] ?? "" }}');
        html += statusCard('Artisan Exists', status.artisan_exists);
        html += statusCard('.env Exists', status.env_exists);
        html += statusCard('Storage Directory', status.storage_exists);
        html += statusCard('Vendor Directory', status.vendor_exists);
        html += '</div>';
        
        document.getElementById('status-check').innerHTML = html;
    } catch (e) {
        document.getElementById('status-check').innerHTML = '<div class="alert alert-error">Error loading status</div>';
    }
});

function statusCard(label, exists, path = '') {
    const icon = exists ? '<i class="fas fa-check-circle" style="color: #22c55e;"></i>' : '<i class="fas fa-times-circle" style="color: #ef4444;"></i>';
    const detail = path ? `<span style="font-size: 11px; color: #64748b; margin-left: 8px;">${path}</span>` : '';
    return `<div style="display: flex; align-items: center; gap: 8px; padding: 8px; background: #0f172a; border-radius: 6px;">${icon}<span style="flex: 1;">${label}</span>${detail}</div>`;
}
</script>
@endsection