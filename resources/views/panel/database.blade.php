@extends('panel.layout')

@section('title', 'Database Manager')

@section('content')
<div class="topbar">
    <div class="page-title">Database Manager</div>
    @if($currentProject)
        <span class="project-badge">{{ $currentProject['name'] }}</span>
    @endif
</div>

<!-- SQL Query -->
<div class="card">
    <div class="card-title" style="margin-bottom: 12px;">Run SQL Query</div>
    <div style="display: flex; gap: 12px; margin-bottom: 16px;">
        <textarea id="sql-input" class="input textarea" placeholder="SELECT * FROM users LIMIT 20;&#10;UPDATE users SET ...;&#10;INSERT INTO ...;" style="flex: 1; min-height: 80px;"></textarea>
        <div style="display: flex; flex-direction: column; gap: 8px;">
            <button class="btn btn-blue" onclick="runQuery()">
                <i class="fas fa-play"></i> Execute
            </button>
            <button class="btn btn-gray" onclick="clearQuery()">
                <i class="fas fa-eraser"></i> Clear
            </button>
        </div>
    </div>
    
    <div id="query-result"></div>
</div>

<!-- Tables List -->
<div class="card">
    <div class="card-title" style="margin-bottom: 16px;">Tables ({{ count($tables) }})</div>
    
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th style="width: 40px;">#</th>
                    <th>Table Name</th>
                    <th style="width: 120px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($tables as $i => $table)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>
                        <span style="color: #38bdf8; cursor: pointer;" onclick="loadTable('{{ $table }}')">
                            <i class="fas fa-table"></i> {{ $table }}
                        </span>
                    </td>
                    <td>
                        <div style="display: flex; gap: 8px;">
                            <button class="btn btn-sm btn-blue" onclick="loadTable('{{ $table }}')">
                                <i class="fas fa-eye"></i> Browse
                            </button>
                            <button class="btn btn-sm btn-gray" onclick="exportTable('{{ $table }}')">
                                <i class="fas fa-download"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="3" style="text-align: center; color: #64748b; padding: 40px;">
                        <i class="fas fa-database" style="font-size: 32px; margin-bottom: 12px; display: block;"></i>
                        No tables found or database not connected
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<!-- Table Data Modal -->
<div id="table-modal" class="modal-overlay" style="display: none;" x-data="{ show: false }">
    <div class="modal" style="max-width: 1000px;">
        <div class="modal-header">
            <div class="modal-title">
                <i class="fas fa-table" style="color: #38bdf8; margin-right: 8px;"></i>
                <span id="modal-table-name">Table</span>
            </div>
            <button class="modal-close" onclick="closeTableModal()">&times;</button>
        </div>
        
        <!-- Table Controls -->
        <div style="display: flex; gap: 12px; margin-bottom: 16px; flex-wrap: wrap;">
            <input type="text" id="table-search" class="input" placeholder="Search..." style="width: 200px;" oninput="debounceSearch()">
            <select id="table-sort" class="select" onchange="loadTableData()">
                <option value="id">Sort by: ID</option>
            </select>
            <select id="table-order" class="select" onchange="loadTableData()">
                <option value="desc">Desc</option>
                <option value="asc">Asc</option>
            </select>
            <span id="table-count" style="color: #64748b; font-size: 13px; align-self: center;"></span>
        </div>
        
        <!-- Table Data -->
        <div class="table-container" style="max-height: 400px; overflow-y: auto;">
            <table>
                <thead id="table-header"></thead>
                <tbody id="table-body"></tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <div class="pagination" id="table-pagination"></div>
    </div>
</div>
@endsection

@section('scripts')
<script>
let currentTable = '';
let currentPage = 1;
let searchTimeout = null;

async function runQuery() {
    const sql = document.getElementById('sql-input').value.trim();
    if (!sql) return showAlert('query-result', 'error', 'Please enter a SQL query');
    
    showAlert('query-result', 'info', '<i class="fas fa-spinner fa-spin"></i> Running query...');
    
    try {
        const response = await fetch('{{ route("panel.api.query") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ sql })
        });
        const result = await response.json();
        
        if (result.error) {
            showAlert('query-result', 'error', result.error);
        } else if (result.data && result.data.length > 0) {
            let html = `<div class="alert alert-success" style="margin-bottom: 12px;">
                <i class="fas fa-check-circle"></i> ${result.rows_affected} row(s) returned
            </div>`;
            html += '<div class="table-container"><table><thead><tr>';
            
            // Headers
            Object.keys(result.data[0]).forEach(key => {
                html += `<th>${key}</th>`;
            });
            html += '</tr></thead><tbody>';
            
            // Rows
            result.data.forEach(row => {
                html += '<tr>';
                Object.values(row).forEach(val => {
                    const display = val === null ? '<span style="color: #64748b;">NULL</span>' : String(val).substring(0, 100);
                    html += `<td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${display}</td>`;
                });
                html += '</tr>';
            });
            html += '</tbody></table></div>';
            
            document.getElementById('query-result').innerHTML = html;
        } else {
            showAlert('query-result', 'success', result.message || 'Query executed successfully. ' + result.rows_affected + ' row(s) affected.');
        }
    } catch (e) {
        showAlert('query-result', 'error', e.message);
    }
}

function clearQuery() {
    document.getElementById('sql-input').value = '';
    document.getElementById('query-result').innerHTML = '';
}

function showAlert(containerId, type, message) {
    const icons = { success: 'check-circle', error: 'times-circle', warning: 'exclamation-triangle', info: 'info-circle' };
    const classes = { success: 'alert-success', error: 'alert-error', warning: 'alert-warning', info: 'alert-info' };
    document.getElementById(containerId).innerHTML = `<div class="alert ${classes[type]}"><i class="fas fa-${icons[type]}"></i> ${message}</div>`;
}

async function loadTable(tableName) {
    currentTable = tableName;
    currentPage = 1;
    document.getElementById('modal-table-name').textContent = tableName;
    document.getElementById('table-modal').style.display = 'flex';
    await loadTableData();
}

async function loadTableData() {
    const search = document.getElementById('table-search').value;
    const sort = document.getElementById('table-sort').value;
    const order = document.getElementById('table-order').value;
    
    try {
        const params = new URLSearchParams({
            page: currentPage,
            per_page: 20,
            sort,
            order,
            search
        });
        
        const response = await fetch('{{ route("panel.api.table.data", ["table" => ":table"]) }}'.replace(':table', currentTable) + '?' + params);
        const result = await response.json();
        
        if (result.error) {
            showAlert('query-result', 'error', result.error);
            return;
        }
        
        // Update sort dropdown with columns
        const sortSelect = document.getElementById('table-sort');
        if (result.columns) {
            sortSelect.innerHTML = result.columns.map(col => `<option value="${col}">${col}</option>`).join('');
        }
        
        // Render table
        const header = document.getElementById('table-header');
        const body = document.getElementById('table-body');
        
        header.innerHTML = '<tr>' + result.columns.map(col => `<th>${col}</th>`).join('') + '</tr>';
        body.innerHTML = result.data.map(row => '<tr>' + result.columns.map(col => {
            const val = row[col];
            const display = val === null ? '<span style="color: #64748b;">NULL</span>' : String(val).substring(0, 100);
            return `<td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${display}</td>`;
        }).join('') + '</tr>').join('');
        
        // Update count and pagination
        document.getElementById('table-count').textContent = `${result.total} total rows`;
        
        const pagination = document.getElementById('table-pagination');
        if (result.last_page > 1) {
            let pages = '';
            for (let i = 1; i <= Math.min(result.last_page, 5); i++) {
                pages += `<button class="page-btn ${i === currentPage ? 'active' : ''}" onclick="goToPage(${i})">${i}</button>`;
            }
            pagination.innerHTML = pages;
        } else {
            pagination.innerHTML = '';
        }
    } catch (e) {
        console.error(e);
    }
}

function goToPage(page) {
    currentPage = page;
    loadTableData();
}

function debounceSearch() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        currentPage = 1;
        loadTableData();
    }, 300);
}

function closeTableModal() {
    document.getElementById('table-modal').style.display = 'none';
}

function exportTable(tableName) {
    window.location.href = '{{ route("panel.api.export", ["table" => ":table"]) }}'.replace(':table', tableName);
}

// Close modal on ESC
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeTableModal();
});
</script>
@endsection