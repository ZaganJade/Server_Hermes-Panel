@extends('panel.layout')

@section('title', 'File Manager')

@section('content')
<div class="topbar">
    <div class="page-title">File Manager</div>
    @if($currentProject)
        <span class="project-badge">{{ $currentProject['name'] }}</span>
    @endif
</div>

<!-- Breadcrumbs & Actions -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
    <div class="breadcrumbs">
        @foreach($breadcrumbs as $crumb)
            <a href="{{ route('panel.files') }}?path={{ $crumb['path'] }}">{{ $crumb['name'] }}</a>
            @if(!$loop->last)<span>/</span>@endif
        @endforeach
    </div>
    <div style="display: flex; gap: 8px;">
        <button class="btn btn-blue btn-sm" onclick="showCreateModal('file')">
            <i class="fas fa-plus"></i> New File
        </button>
        <button class="btn btn-blue btn-sm" onclick="showCreateModal('directory')">
            <i class="fas fa-folder-plus"></i> New Folder
        </button>
        <label class="btn btn-gray btn-sm" style="cursor: pointer;">
            <i class="fas fa-upload"></i> Upload
            <input type="file" id="file-upload" style="display: none;" onchange="uploadFile(this)">
        </label>
    </div>
</div>

<!-- Parent Directory Link -->
@if($parentPath !== '/' && $parentPath !== '')
<div class="file-item" style="background: #0f172a; margin-bottom: 8px;" onclick="navigateTo('{{ $parentPath }}')">
    <div class="file-icon folder"><i class="fas fa-folder-open"></i></div>
    <div class="file-info">
        <div class="file-name" style="color: #38bdf8;">..</div>
        <div class="file-meta">Go to parent directory</div>
    </div>
</div>
@endif

<!-- Directories -->
@forelse($directories as $dir)
<div class="file-item" onclick="navigateTo('{{ $dir['path'] }}')">
    <div class="file-icon folder"><i class="fas fa-folder"></i></div>
    <div class="file-info">
        <div class="file-name">{{ $dir['name'] }}</div>
        <div class="file-meta">{{ $dir['modified'] }}</div>
    </div>
    <button class="btn btn-sm btn-red" onclick="event.stopPropagation(); deleteItem('{{ $dir['path'] }}', 'directory')">
        <i class="fas fa-trash"></i>
    </button>
</div>
@empty
@endforelse

<!-- Files -->
@forelse($files as $file)
@php
$iconClass = match(true) {
    in_array($file['extension'], ['php']) => 'php',
    in_array($file['extension'], ['js', 'jsx', 'ts', 'tsx']) => 'js',
    in_array($file['extension'], ['css', 'scss', 'sass']) => 'css',
    default => 'file'
};
$icon = match($file['extension']) {
    'php' => 'fab fa-php',
    'js' => 'fab fa-js',
    'css' => 'fab fa-css3-alt',
    'blade.php' => 'fab fa-laravel',
    'json' => 'fas fa-braces',
    'env' => 'fas fa-file-code',
    'md' => 'fab fa-markdown',
    'html' => 'fab fa-html5',
    'txt' => 'fas fa-file-alt',
    default => 'fas fa-file'
};
@endphp
<div class="file-item" onclick="viewFile('{{ $file['path'] }}')">
    <div class="file-icon {{ $iconClass }}"><i class="{{ $icon }}"></i></div>
    <div class="file-info">
        <div class="file-name">{{ $file['name'] }}</div>
        <div class="file-meta">{{ $file['size'] }} • {{ $file['modified'] }}</div>
    </div>
    <div style="display: flex; gap: 4px;">
        <button class="btn btn-sm btn-blue" onclick="event.stopPropagation(); viewFile('{{ $file['path'] }}')">
            <i class="fas fa-eye"></i>
        </button>
        <button class="btn btn-sm btn-red" onclick="event.stopPropagation(); deleteItem('{{ $file['path'] }}', 'file')">
            <i class="fas fa-trash"></i>
        </button>
    </div>
</div>
@empty
@if(count($directories) === 0)
<div style="text-align: center; padding: 60px; color: #64748b;">
    <i class="fas fa-folder-open" style="font-size: 48px; margin-bottom: 16px; display: block;"></i>
    <p>This directory is empty</p>
</div>
@endif
@endforelse

<!-- Create Modal -->
<div id="create-modal" class="modal-overlay" style="display: none;">
    <div class="modal" style="max-width: 400px;">
        <div class="modal-header">
            <div class="modal-title" id="create-modal-title">Create New</div>
            <button class="modal-close" onclick="closeCreateModal()">&times;</button>
        </div>
        <div style="display: flex; flex-direction: column; gap: 16px;">
            <input type="text" id="create-name" class="input" placeholder="File/Folder name">
            <div style="display: flex; gap: 12px; justify-content: flex-end;">
                <button class="btn btn-gray" onclick="closeCreateModal()">Cancel</button>
                <button class="btn btn-blue" onclick="createItem()"><i class="fas fa-check"></i> Create</button>
            </div>
        </div>
    </div>
</div>

<!-- File Viewer Modal -->
<div id="file-modal" class="modal-overlay" style="display: none;">
    <div class="modal" style="max-width: 900px; max-height: 90vh;">
        <div class="modal-header">
            <div class="modal-title">
                <i class="fas fa-file-code" style="color: #38bdf8; margin-right: 8px;"></i>
                <span id="file-modal-name">filename.php</span>
            </div>
            <button class="modal-close" onclick="closeFileModal()">&times;</button>
        </div>
        
        <div id="file-content-area" style="margin-bottom: 16px;">
            <div class="loading"><i class="fas fa-spinner"></i> Loading file...</div>
        </div>
        
        <div style="display: flex; justify-content: space-between; align-items: center; padding-top: 16px; border-top: 1px solid #334155;">
            <div id="file-info" style="font-size: 12px; color: #64748b;"></div>
            <div style="display: flex; gap: 8px;">
                <button class="btn btn-gray" onclick="closeFileModal()">Cancel</button>
                <button class="btn btn-blue" id="save-file-btn" onclick="saveFile()" style="display: none;">
                    <i class="fas fa-save"></i> Save
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Upload Progress -->
<div id="upload-status" style="position: fixed; bottom: 20px; right: 20px; display: none;"></div>
@endsection

@section('scripts')
<script>
let currentFilePath = '';
let createType = 'file';

function navigateTo(path) {
    window.location.href = '{{ route("panel.files") }}?path=' + encodeURIComponent(path);
}

function showCreateModal(type) {
    createType = type;
    document.getElementById('create-modal-title').textContent = type === 'directory' ? 'Create New Folder' : 'Create New File';
    document.getElementById('create-modal').style.display = 'flex';
    document.getElementById('create-name').value = '';
    document.getElementById('create-name').focus();
}

function closeCreateModal() {
    document.getElementById('create-modal').style.display = 'none';
}

async function createItem() {
    const name = document.getElementById('create-name').value.trim();
    if (!name) return alert('Name is required');
    
    const currentPath = '{{ $currentPath }}';
    
    try {
        const response = await fetch('{{ route("panel.api.file.create") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ path: currentPath, name, type: createType })
        });
        const result = await response.json();
        
        if (result.success) {
            closeCreateModal();
            location.reload();
        } else {
            alert(result.error || 'Failed to create');
        }
    } catch (e) {
        alert(e.message);
    }
}

async function deleteItem(path, type) {
    const confirmMsg = type === 'directory' 
        ? `Delete folder "${path}"? This cannot be undone.`
        : `Delete file "${path}"? This cannot be undone.`;
    
    if (!confirm(confirmMsg)) return;
    
    try {
        const response = await fetch('{{ route("panel.api.file.delete") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ path })
        });
        const result = await response.json();
        
        if (result.success) {
            location.reload();
        } else {
            alert(result.error || 'Failed to delete');
        }
    } catch (e) {
        alert(e.message);
    }
}

async function viewFile(path) {
    currentFilePath = path;
    document.getElementById('file-modal').style.display = 'flex';
    document.getElementById('file-content-area').innerHTML = '<div class="loading"><i class="fas fa-spinner"></i> Loading...</div>';
    document.getElementById('save-file-btn').style.display = 'none';
    
    try {
        const response = await fetch('{{ route("panel.api.file.content") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ path })
        });
        const result = await response.json();
        
        if (result.error) {
            document.getElementById('file-content-area').innerHTML = `<div class="alert alert-error">${result.error}</div>`;
            return;
        }
        
        document.getElementById('file-modal-name').textContent = result.name;
        document.getElementById('file-info').textContent = `${result.size} • Modified: ${result.modified}`;
        
        if (result.editable) {
            document.getElementById('file-content-area').innerHTML = `
                <textarea id="file-editor" class="code-editor">${escapeHtml(result.content)}</textarea>
            `;
            document.getElementById('save-file-btn').style.display = 'inline-flex';
        } else {
            document.getElementById('file-content-area').innerHTML = `
                <div class="alert alert-warning" style="margin-bottom: 12px;">
                    <i class="fas fa-lock"></i> This file type cannot be edited directly. 
                    Download it to edit locally.
                </div>
                <pre class="code-display">${escapeHtml(result.content)}</pre>
            `;
        }
    } catch (e) {
        document.getElementById('file-content-area').innerHTML = `<div class="alert alert-error">${e.message}</div>`;
    }
}

async function saveFile() {
    const content = document.getElementById('file-editor').value;
    
    try {
        const response = await fetch('{{ route("panel.api.file.save") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ path: currentFilePath, content })
        });
        const result = await response.json();
        
        if (result.success) {
            showNotification('File saved successfully!', 'success');
        } else {
            alert(result.error || 'Failed to save');
        }
    } catch (e) {
        alert(e.message);
    }
}

function closeFileModal() {
    document.getElementById('file-modal').style.display = 'none';
}

async function uploadFile(input) {
    if (!input.files.length) return;
    
    const formData = new FormData();
    formData.append('file', input.files[0]);
    formData.append('path', '{{ $currentPath }}');
    
    showNotification('Uploading...', 'info');
    
    try {
        const response = await fetch('{{ route("panel.api.file.upload") }}', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: formData
        });
        const result = await response.json();
        
        if (result.success) {
            showNotification('File uploaded!', 'success');
            location.reload();
        } else {
            alert(result.error || 'Upload failed');
        }
    } catch (e) {
        alert(e.message);
    }
    
    input.value = '';
}

function showNotification(message, type) {
    const colors = { success: '#22c55e', error: '#ef4444', info: '#38bdf8' };
    const status = document.getElementById('upload-status');
    status.innerHTML = `<div style="background: #1e293b; border-left: 4px solid ${colors[type]}; padding: 12px 16px; border-radius: 8px; color: #e2e8f0; font-size: 13px;">
        <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'times-circle' : 'info-circle'}" style="color: ${colors[type]}; margin-right: 8px;"></i>
        ${message}
    </div>`;
    status.style.display = 'block';
    setTimeout(() => { status.style.display = 'none'; }, 3000);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Close modals on ESC
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        closeCreateModal();
        closeFileModal();
    }
});
</script>
@endsection