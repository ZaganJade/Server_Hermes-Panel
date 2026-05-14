<?php

use App\Http\Controllers\Panel\PanelController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Panel Routes
|--------------------------------------------------------------------------
*/

Route::prefix('panel')->name('panel.')->group(function () {
    // Main panel (protected by OwnerAccess middleware)
    Route::get('/', [PanelController::class, 'index'])->name('index');
    Route::get('/database', [PanelController::class, 'database'])->name('database');
    Route::get('/files', [PanelController::class, 'files'])->name('files');
    Route::get('/tools', [PanelController::class, 'tools'])->name('tools');
    Route::get('/projects', [PanelController::class, 'projects'])->name('projects');

    // API endpoints (AJAX)
    Route::prefix('api')->name('api.')->group(function () {
        // Database
        Route::get('/tables/{table}/data', [PanelController::class, 'tableData'])->name('table.data');
        Route::post('/query', [PanelController::class, 'runQuery'])->name('query');
        Route::get('/tables/{table}/export', [PanelController::class, 'exportTable'])->name('export');

        // File Manager
        Route::post('/files/content', [PanelController::class, 'fileContent'])->name('file.content');
        Route::post('/files/save', [PanelController::class, 'saveFile'])->name('file.save');
        Route::post('/files/create', [PanelController::class, 'createFile'])->name('file.create');
        Route::post('/files/delete', [PanelController::class, 'deleteFile'])->name('file.delete');
        Route::post('/files/upload', [PanelController::class, 'uploadFile'])->name('file.upload');

        // Laravel Tools
        Route::post('/artisan', [PanelController::class, 'runArtisan'])->name('artisan');
        Route::get('/logs', [PanelController::class, 'getLogs'])->name('logs');
        Route::get('/status', [PanelController::class, 'getProjectStatus'])->name('status');

        // Projects
        Route::post('/projects/add', [PanelController::class, 'addProject'])->name('projects.add');
        Route::post('/projects/remove', [PanelController::class, 'removeProject'])->name('projects.remove');
    });
});