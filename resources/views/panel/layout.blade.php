<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ config('panel.name') }} - @yield('title', 'Dashboard')</title>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #0f172a; color: #e2e8f0; min-height: 100vh; }
        
        /* Layout */
        .flex { display: flex; }
        .sidebar { width: 240px; background: #1e293b; min-height: 100vh; position: fixed; left: 0; top: 0; }
        .main { ml-240 ml-[240px] flex-1 ml-0 md:ml-[240px] p-6; }
        
        /* Sidebar */
        .sidebar-header { padding: 20px; border-bottom: 1px solid #334155; }
        .sidebar-logo { font-size: 20px; font-weight: 700; color: #38bdf8; }
        .sidebar-subtitle { font-size: 11px; color: #64748b; margin-top: 4px; }
        
        .nav-item { display: flex; align-items: center; gap: 12px; padding: 14px 20px; color: #94a3b8; text-decoration: none; transition: all 0.2s; border-left: 3px solid transparent; }
        .nav-item:hover, .nav-item.active { background: #334155; color: #38bdf8; border-left-color: #38bdf8; }
        .nav-item i { width: 20px; text-align: center; }
        
        /* Top Bar */
        .topbar { background: #1e293b; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; margin: -24px -24px 24px -24px; }
        .page-title { font-size: 18px; font-weight: 600; }
        .project-badge { background: #38bdf8; color: #0f172a; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        
        /* Cards */
        .card { background: #1e293b; border-radius: 12px; padding: 24px; margin-bottom: 20px; }
        .card-title { font-size: 14px; color: #64748b; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
        .card-value { font-size: 28px; font-weight: 700; color: #f1f5f9; }
        .card-sub { font-size: 12px; color: #64748b; margin-top: 4px; }
        
        /* Stats Grid */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .stat-card { background: #1e293b; border-radius: 12px; padding: 20px; display: flex; align-items: center; gap: 16px; }
        .stat-icon { width: 48px; height: 48px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; }
        .stat-icon.blue { background: #1e3a5f; color: #38bdf8; }
        .stat-icon.green { background: #1a3a2a; color: #22c55e; }
        .stat-icon.purple { background: #2a1a3a; color: #a855f7; }
        .stat-icon.orange { background: #3a2a1a; color: #f97316; }
        
        /* Tables */
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 12px 16px; font-size: 11px; text-transform: uppercase; color: #64748b; border-bottom: 1px solid #334155; }
        td { padding: 12px 16px; font-size: 14px; border-bottom: 1px solid #1e293b; }
        tr:hover { background: #334155; }
        
        /* Buttons */
        .btn { padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 500; cursor: pointer; border: none; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s; }
        .btn-blue { background: #38bdf8; color: #0f172a; }
        .btn-blue:hover { background: #0ea5e9; }
        .btn-red { background: #ef4444; color: white; }
        .btn-red:hover { background: #dc2626; }
        .btn-gray { background: #334155; color: #e2e8f0; }
        .btn-gray:hover { background: #475569; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        
        /* Form Elements */
        .input { background: #0f172a; border: 1px solid #334155; color: #e2e8f0; padding: 10px 14px; border-radius: 8px; font-size: 14px; width: 100%; outline: none; }
        .input:focus { border-color: #38bdf8; }
        .textarea { min-height: 120px; resize: vertical; font-family: 'Fira Code', monospace; font-size: 13px; }
        .select { background: #0f172a; border: 1px solid #334155; color: #e2e8f0; padding: 10px 14px; border-radius: 8px; font-size: 14px; }
        
        /* Tabs */
        .tabs { display: flex; gap: 4px; background: #0f172a; padding: 4px; border-radius: 10px; margin-bottom: 20px; }
        .tab { padding: 10px 20px; border-radius: 8px; color: #64748b; cursor: pointer; font-size: 13px; font-weight: 500; transition: all 0.2s; border: none; background: none; }
        .tab:hover { color: #e2e8f0; }
        .tab.active { background: #38bdf8; color: #0f172a; }
        
        /* Modal */
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.7); display: flex; align-items: center; justify-content: center; z-index: 100; }
        .modal { background: #1e293b; border-radius: 16px; padding: 24px; width: 90%; max-width: 600px; max-height: 90vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .modal-title { font-size: 18px; font-weight: 600; }
        .modal-close { background: none; border: none; color: #64748b; font-size: 20px; cursor: pointer; }
        .modal-close:hover { color: #e2e8f0; }
        
        /* Code Editor */
        .code-editor { background: #0f172a; border: 1px solid #334155; color: #e2e8f0; font-family: 'Fira Code', 'Consolas', monospace; font-size: 13px; padding: 16px; border-radius: 8px; width: 100%; min-height: 400px; resize: vertical; outline: none; }
        .code-editor:focus { border-color: #38bdf8; }
        
        /* Code Display */
        .code-display { background: #0f172a; padding: 16px; border-radius: 8px; font-family: 'Fira Code', monospace; font-size: 12px; overflow-x: auto; white-space: pre; max-height: 400px; overflow-y: auto; }
        
        /* Alert */
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
        .alert-success { background: #1a3a2a; color: #22c55e; border-left: 4px solid #22c55e; }
        .alert-error { background: #3a1a1a; color: #ef4444; border-left: 4px solid #ef4444; }
        .alert-warning { background: #3a2a1a; color: #f97316; border-left: 4px solid #f97316; }
        
        /* File Manager */
        .file-item { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: 8px; transition: all 0.2s; cursor: pointer; }
        .file-item:hover { background: #334155; }
        .file-icon { width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 16px; }
        .file-icon.folder { background: #1e3a5f; color: #38bdf8; }
        .file-icon.file { background: #334155; color: #94a3b8; }
        .file-icon.php { background: #2a1a3a; color: #a855f7; }
        .file-icon.js { background: #2a2a1a; color: #eab308; }
        .file-icon.css { background: #1a2a3a; color: #38bdf8; }
        .file-info { flex: 1; }
        .file-name { font-size: 14px; color: #e2e8f0; }
        .file-meta { font-size: 11px; color: #64748b; }
        
        /* Breadcrumbs */
        .breadcrumbs { display: flex; align-items: center; gap: 8px; margin-bottom: 16px; font-size: 13px; color: #64748b; }
        .breadcrumbs a { color: #38bdf8; text-decoration: none; }
        .breadcrumbs a:hover { text-decoration: underline; }
        .breadcrumbs span { color: #475569; }
        
        /* Loading */
        .loading { display: flex; align-items: center; justify-content: center; padding: 40px; color: #64748b; }
        .loading i { animation: spin 1s linear infinite; margin-right: 8px; }
        @keyframes spin { to { transform: rotate(360deg); } }
        
        /* Pagination */
        .pagination { display: flex; align-items: center; gap: 8px; justify-content: center; margin-top: 20px; }
        .page-btn { padding: 8px 12px; background: #334155; border: none; border-radius: 6px; color: #e2e8f0; cursor: pointer; font-size: 13px; }
        .page-btn:hover { background: #475569; }
        .page-btn.active { background: #38bdf8; color: #0f172a; }
        .page-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        
        /* Scrollbar */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #1e293b; }
        ::-webkit-scrollbar-thumb { background: #334155; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #475569; }
        
        /* Responsive */
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); z-index: 50; }
            .sidebar.open { transform: translateX(0); }
            .main { margin-left: 0; padding: 16px; }
        }
    </style>
</head>
<body>
    <div class="flex">
        <!-- Sidebar -->
        <aside class="sidebar" x-data="{ open: true }">
            <div class="sidebar-header">
                <div class="sidebar-logo"><i class="fas fa-grip"></i> {{ config('panel.name') }}</div>
                <div class="sidebar-subtitle">Server Admin Panel</div>
            </div>
            
            <nav>
                <a href="{{ route('panel.index') }}" class="nav-item {{ Route::is('panel.index') ? 'active' : '' }}">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                <a href="{{ route('panel.database') }}" class="nav-item {{ Route::is('panel.database') ? 'active' : '' }}">
                    <i class="fas fa-database"></i> Database
                </a>
                <a href="{{ route('panel.files') }}" class="nav-item {{ Route::is('panel.files') ? 'active' : '' }}">
                    <i class="fas fa-folder-open"></i> File Manager
                </a>
                <a href="{{ route('panel.tools') }}" class="nav-item {{ Route::is('panel.tools') ? 'active' : '' }}">
                    <i class="fas fa-terminal"></i> Laravel Tools
                </a>
                <a href="{{ route('panel.projects') }}" class="nav-item {{ Route::is('panel.projects') ? 'active' : '' }}">
                    <i class="fas fa-layer-group"></i> Projects
                </a>
            </nav>
            
            <div style="position: absolute; bottom: 0; width: 100%; padding: 20px; border-top: 1px solid #334155;">
                <div style="font-size: 11px; color: #64748b; text-align: center;">
                    <i class="fas fa-server"></i> Azure VM<br>
                    PHP {{ PHP_VERSION }}
                </div>
            </div>
        </aside>
        
        <!-- Main Content -->
        <main class="main" style="margin-left: 240px;">
            @yield('content')
        </main>
    </div>
    
    @yield('scripts')
</body>
</html>