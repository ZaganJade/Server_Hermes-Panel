@extends('panel.layout')

@section('title', 'Projects')

@section('content')
<div class="topbar">
    <div class="page-title">Projects</div>
    @if($currentProject)
        <span class="project-badge">{{ $currentProject['name'] }}</span>
    @endif
</div>

<!-- Add Project -->
<div class="card">
    <div class="card-title" style="margin-bottom: 16px;">Add New Project</div>
    <div style="display: grid; grid-template-columns: 1fr 1fr auto; gap: 12px; align-items: end;">
        <div>
            <label style="display: block; font-size: 12px; color: #64748b; margin-bottom: 6px;">Project Name</label>
            <input type="text" id="project-name" class="input" placeholder="my-project">
        </div>
        <div>
            <label style="display: block; font-size: 12px; color: #64748b; margin-bottom: 6px;">Absolute Path</label>
            <input type="text" id="project-path" class="input" placeholder="/home/ZaganJade1/Project/my-project">
        </div>
        <button class="btn btn-blue" onclick="addProject()">
            <i class="fas fa-plus"></i> Add
        </button>
    </div>
</div>

<!-- Project List -->
<div class="card">
    <div class="card-title" style="margin-bottom: 16px;">Configured Projects ({{ count($projects) }})</div>
    
    @forelse($projects as $name => $path)
    <div style="display: flex; align-items: center; gap: 16px; padding: 16px; background: #0f172a; border-radius: 10px; margin-bottom: 12px;">
        <div style="width: 48px; height: 48px; background: #1e3a5f; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
            <i class="fas fa-folder" style="color: #38bdf8; font-size: 20px;"></i>
        </div>
        <div style="flex: 1;">
            <div style="font-size: 16px; font-weight: 600; color: #e2e8f0;">{{ $name }}</div>
            <div style="font-size: 12px; color: #64748b; margin-top: 4px;">{{ $path }}</div>
        </div>
        <div style="display: flex; gap: 8px;">
            @if($currentProject && $currentProject['name'] === $name)
            <span class="project-badge" style="background: #22c55e; color: #0f172a;">
                <i class="fas fa-check"></i> Active
            </span>
            @else
            <button class="btn btn-sm btn-blue" onclick="switchProject('{{ $name }}')">
                <i class="fas fa-sign-in-alt"></i> Switch
            </button>
            @endif
            <button class="btn btn-sm btn-red" onclick="removeProject('{{ $name }}')">
                <i class="fas fa-trash"></i>
            </button>
        </div>
    </div>
    @empty
    <div style="text-align: center; padding: 40px; color: #64748b;">
        <i class="fas fa-folder-open" style="font-size: 32px; margin-bottom: 12px; display: block;"></i>
        <p>No projects configured yet</p>
    </div>
    @endforelse
</div>

<!-- Info -->
<div class="alert alert-warning" style="display: flex; gap: 12px; align-items: flex-start;">
    <i class="fas fa-info-circle" style="margin-top: 2px;"></i>
    <div>
        <strong>How projects work:</strong><br>
        Each project is a Laravel installation on this server. The panel reads the project's <code>.env</code> file to connect to its database. 
        You can switch between projects from the dashboard. Add new projects by specifying the absolute path to the project directory.
    </div>
</div>
@endsection

@section('scripts')
<script>
async function addProject() {
    const name = document.getElementById('project-name').value.trim();
    const path = document.getElementById('project-path').value.trim();
    
    if (!name || !path) {
        alert('Please fill in both name and path');
        return;
    }
    
    try {
        const response = await fetch('{{ route("panel.api.projects.add") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ name, path })
        });
        const result = await response.json();
        
        if (result.success) {
            location.reload();
        } else {
            alert(result.error || 'Failed to add project');
        }
    } catch (e) {
        alert(e.message);
    }
}

async function removeProject(name) {
    if (!confirm(`Remove project "${name}" from panel? This won't delete the actual project folder.`)) return;
    
    try {
        const response = await fetch('{{ route("panel.api.projects.remove") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ name })
        });
        const result = await response.json();
        
        if (result.success) {
            location.reload();
        } else {
            alert(result.error || 'Failed to remove project');
        }
    } catch (e) {
        alert(e.message);
    }
}

function switchProject(name) {
    // For now, just alert - in a full implementation, this would update the default project
    alert('To switch projects, update PANEL_DEFAULT_PROJECT in the panel .env file and restart PM2');
}
</script>
@endsection