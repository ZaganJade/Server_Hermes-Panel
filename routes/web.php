<?php

use App\Http\Controllers\LandingController;
use App\Http\Controllers\Panel\AuthController;
use App\Http\Controllers\Panel\DashboardController;
use App\Http\Controllers\Panel\DatabaseController;
use App\Http\Controllers\Panel\FileController;
use App\Http\Controllers\Panel\ProjectController;
use App\Http\Controllers\Panel\TerminalController;
use App\Http\Controllers\Panel\ToolController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Landing Page
|--------------------------------------------------------------------------
*/

Route::get('/', [LandingController::class, 'index'])->name('landing');

/*
|--------------------------------------------------------------------------
| Public Auth Routes (no middleware)
|--------------------------------------------------------------------------
*/

Route::prefix('panel')->name('panel.')->group(function () {
    Route::get('/login', [AuthController::class, 'login'])->name('login')->withoutMiddleware('owner.access');
    Route::post('/login', [AuthController::class, 'authenticate'])->name('authenticate')->withoutMiddleware('owner.access');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
});

/*
|--------------------------------------------------------------------------
| Protected Panel Routes
|--------------------------------------------------------------------------
*/

Route::prefix('panel')->name('panel.')->middleware('owner.access')->group(function () {
    // Pages
    Route::get('/', fn () => redirect()->route('panel.dashboard'))->name('index');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/database', [DatabaseController::class, 'index'])->name('database');
    Route::get('/files', [FileController::class, 'index'])->name('files');
    Route::get('/tools', [ToolController::class, 'index'])->name('tools');
    Route::get('/projects', [ProjectController::class, 'index'])->name('projects');

    // AJAX: Dashboard
    Route::post('/api/quick/cache-clear', [DashboardController::class, 'cacheClear'])->name('api.cache-clear');
    Route::get('/api/quick/recent-logs', [DashboardController::class, 'recentLogs'])->name('api.recent-logs');

    // AJAX: Database
    Route::get('/api/tables', [DatabaseController::class, 'tables'])->name('api.tables');
    Route::get('/api/tables/{table}/data', [DatabaseController::class, 'tableData'])->name('api.table-data');
    Route::post('/api/tables/{table}/rows', [DatabaseController::class, 'updateRow'])->name('api.update-row');
    Route::delete('/api/tables/{table}/rows/{id}', [DatabaseController::class, 'deleteRow'])->name('api.delete-row');
    Route::post('/api/query', [DatabaseController::class, 'runQuery'])->name('api.query');
    Route::get('/api/tables/{table}/export/{format}', [DatabaseController::class, 'exportTable'])->name('api.export');
    Route::get('/api/connections', [DatabaseController::class, 'connections'])->name('api.connections');

    // AJAX: Files
    Route::get('/api/files', [FileController::class, 'listFiles'])->name('api.files');
    Route::post('/api/files/content', [FileController::class, 'fileContent'])->name('api.file-content');
    Route::post('/api/files/save', [FileController::class, 'saveFile'])->name('api.file-save');
    Route::post('/api/files/create', [FileController::class, 'createFile'])->name('api.file-create');
    Route::post('/api/files/rename', [FileController::class, 'renameFile'])->name('api.file-rename');
    Route::post('/api/files/move', [FileController::class, 'moveFile'])->name('api.file-move');
    Route::post('/api/files/copy', [FileController::class, 'copyFile'])->name('api.file-copy');
    Route::post('/api/files/delete', [FileController::class, 'deleteFile'])->name('api.file-delete');
    Route::post('/api/files/upload', [FileController::class, 'uploadFile'])->name('api.file-upload');
    Route::get('/api/files/download', [FileController::class, 'downloadFile'])->name('api.file-download');
    Route::post('/api/files/permissions', [FileController::class, 'updatePermissions'])->name('api.file-permissions');
    Route::get('/api/files/search', [FileController::class, 'searchFiles'])->name('api.file-search');

    // AJAX: Tools
    Route::post('/api/artisan', [ToolController::class, 'runArtisan'])->name('api.artisan');
    Route::get('/api/logs', [ToolController::class, 'getLogs'])->name('api.logs');
    Route::post('/api/logs/clear', [ToolController::class, 'clearLogs'])->name('api.logs-clear');
    Route::get('/api/queue/status', [ToolController::class, 'queueStatus'])->name('api.queue-status');
    Route::post('/api/queue/retry/{id}', [ToolController::class, 'queueRetry'])->name('api.queue-retry');
    Route::post('/api/queue/restart', [ToolController::class, 'queueRestart'])->name('api.queue-restart');
    Route::post('/api/queue/flush', [ToolController::class, 'queueFlush'])->name('api.queue-flush');
    Route::post('/api/composer', [ToolController::class, 'runComposer'])->name('api.composer');
    Route::post('/api/npm', [ToolController::class, 'runNpm'])->name('api.npm');

    // AJAX: Terminal
    Route::get('/api/terminal/state', [TerminalController::class, 'state'])->name('api.terminal-state');
    Route::post('/api/terminal/execute', [TerminalController::class, 'execute'])->name('api.terminal-execute');
    Route::post('/api/terminal/reset', [TerminalController::class, 'reset'])->name('api.terminal-reset');

    // AJAX: Projects
    Route::post('/api/projects/switch', [ProjectController::class, 'switchProject'])->name('api.project-switch');
    Route::post('/api/projects/add', [ProjectController::class, 'addProject'])->name('api.project-add');
    Route::post('/api/projects/hide', [ProjectController::class, 'hideProject'])->name('api.project-hide');
    Route::post('/api/projects/unhide', [ProjectController::class, 'unhideProject'])->name('api.project-unhide');
    Route::post('/api/projects/delete', [ProjectController::class, 'deleteProject'])->name('api.project-delete');
    Route::get('/api/projects/list', [ProjectController::class, 'listProjects'])->name('api.project-list');
});
