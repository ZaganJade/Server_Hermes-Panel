<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Services\DatabaseService;
use App\Services\ProjectService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DatabaseController extends Controller
{
    public function __construct(
        protected DatabaseService $dbService,
        protected ProjectService $projectService,
    ) {}

    protected function configureActiveProjectDb(string $connectionKey = 'primary'): ?string
    {
        $project = $this->projectService->getActiveProject();
        if (!$project || $project['type'] !== 'laravel') {
            return null;
        }

        $env = $this->projectService->readEnv($project['path']);
        if (empty($env['DB_DATABASE'])) {
            return null;
        }

        $connEnv = $connectionKey === 'primary'
            ? $env
            : $this->dbService->getConnectionEnv($connectionKey, $env);

        $connName = "panel_project_{$connectionKey}";
        $this->dbService->configureConnection($connectionKey, $connEnv);

        return $connName;
    }

    public function index()
    {
        $project = $this->projectService->getActiveProject();
        $connections = [];

        if ($project && $project['type'] === 'laravel') {
            $env = $this->projectService->readEnv($project['path']);
            $connections = $this->dbService->getConnections($env);
        }

        return view('panel.database', [
            'activeProject' => $project,
            'connections' => $connections,
        ]);
    }

    public function tables(Request $request)
    {
        $connectionKey = $request->get('connection', 'primary');
        $connName = $this->configureActiveProjectDb($connectionKey);

        if (!$connName) {
            return response()->json(['error' => 'No database configured', 'tables' => []]);
        }

        return response()->json(['tables' => $this->dbService->getTables($connName)]);
    }

    public function tableData(Request $request, string $table)
    {
        $connectionKey = $request->get('connection', 'primary');
        $connName = $this->configureActiveProjectDb($connectionKey);

        if (!$connName) {
            return response()->json(['error' => 'No database configured']);
        }

        $page = (int) $request->get('page', 1);
        $perPage = (int) $request->get('per_page', 25);
        $sortBy = $request->get('sort_by');
        $sortDir = $request->get('sort_dir', 'asc');

        $result = $this->dbService->getTableData($connName, $table, $page, $perPage, $sortBy, $sortDir);

        return response()->json($result);
    }

    public function getColumns(Request $request, string $table)
    {
        $connectionKey = $request->get('connection', 'primary');
        $connName = $this->configureActiveProjectDb($connectionKey);

        if (!$connName) {
            return response()->json(['error' => 'No database configured', 'columns' => []]);
        }

        $columns = $this->dbService->getTableColumns($connName, $table);

        return response()->json(['columns' => $columns]);
    }

    public function updateCell(Request $request, string $table, $id)
    {
        $connectionKey = $request->get('connection', 'primary');
        $connName = $this->configureActiveProjectDb($connectionKey);

        if (!$connName) {
            return response()->json(['success' => false, 'error' => 'No database configured']);
        }

        try {
            $column = $request->input('column');
            $value = $request->input('value');

            if (!$column) {
                return response()->json(['success' => false, 'error' => 'Column is required']);
            }

            // Validate column name
            if (!$this->dbService->isValidSqlIdentifier($column)) {
                return response()->json(['success' => false, 'error' => 'Invalid column name']);
            }

            $primaryKey = $this->dbService->getPrimaryKey($connName, $table);

            DB::connection($connName)->table($table)
                ->where($primaryKey, $id)
                ->update([$column => $value]);

            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function storeRow(Request $request, string $table)
    {
        $connectionKey = $request->get('connection', 'primary');
        $connName = $this->configureActiveProjectDb($connectionKey);

        if (!$connName) {
            return response()->json(['success' => false, 'error' => 'No database configured']);
        }

        try {
            $data = $request->all();

            // Remove connection key from data if present
            unset($data['connection']);

            // Validate all column names
            foreach (array_keys($data) as $column) {
                if (!$this->dbService->isValidSqlIdentifier($column)) {
                    return response()->json(['success' => false, 'error' => "Invalid column name: {$column}"]);
                }
            }

            $id = DB::connection($connName)->table($table)->insertGetId($data);

            return response()->json(['success' => true, 'id' => $id]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function getTrash(Request $request, string $table)
    {
        $connectionKey = $request->get('connection', 'primary');
        $connName = $this->configureActiveProjectDb($connectionKey);

        if (!$connName) {
            return response()->json(['error' => 'No database configured', 'rows' => []]);
        }

        $page = (int) $request->get('page', 1);
        $perPage = (int) $request->get('per_page', 25);

        $result = $this->dbService->getTrashData($connName, $table, $page, $perPage);

        return response()->json($result);
    }

    public function restoreRow(Request $request, string $table, $id)
    {
        $connectionKey = $request->get('connection', 'primary');
        $connName = $this->configureActiveProjectDb($connectionKey);

        if (!$connName) {
            return response()->json(['success' => false, 'error' => 'No database configured']);
        }

        $success = $this->dbService->restoreRow($connName, $table, (int) $id);

        return response()->json(['success' => $success]);
    }

    public function forceDeleteRow(Request $request, string $table, $id)
    {
        $connectionKey = $request->get('connection', 'primary');
        $connName = $this->configureActiveProjectDb($connectionKey);

        if (!$connName) {
            return response()->json(['success' => false, 'error' => 'No database configured']);
        }

        $success = $this->dbService->forceDeleteRow($connName, $table, $id);

            return response()->json(['success' => $success]);
    }

    public function emptyTrash(Request $request, string $table)
    {
        $connectionKey = $request->get('connection', 'primary');
        $connName = $this->configureActiveProjectDb($connectionKey);

        if (!$connName) {
            return response()->json(['success' => false, 'error' => 'No database configured']);
        }

        try {
            // Count first before deleting (get a fresh count query)
            $count = DB::connection($connName)->table($table)->onlyTrashed()->count();
            // Force delete all soft-deleted rows
            DB::connection($connName)->table($table)->onlyTrashed()->forceDelete();

            return response()->json(['success' => true, 'count' => $count]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function updateRow(Request $request, string $table)
    {
        $connectionKey = $request->get('connection', 'primary');
        $connName = $this->configureActiveProjectDb($connectionKey);

        if (!$connName) {
            return response()->json(['success' => false, 'error' => 'No database configured']);
        }

        try {
            $id = $request->input('id');
            $column = $request->input('column');
            $value = $request->input('value');

            // Get actual primary key for this table
            $primaryKey = $this->dbService->getPrimaryKey($connName, $table);

            DB::connection($connName)->table($table)
                ->where($primaryKey, $id)
                ->update([$column => $value]);

            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function deleteRow(Request $request, string $table, $id)
    {
        $connectionKey = $request->get('connection', 'primary');
        $connName = $this->configureActiveProjectDb($connectionKey);

        if (!$connName) {
            return response()->json(['success' => false, 'error' => 'No database configured']);
        }

        try {
            $primaryKey = $this->dbService->getPrimaryKey($connName, $table);

            // Check if table supports soft deletes
            if ($this->dbService->detectSoftDeletes($connName, $table)) {
                // Soft delete: set deleted_at to current timestamp
                DB::connection($connName)->table($table)
                    ->where($primaryKey, $id)
                    ->update(['deleted_at' => now()]);
            } else {
                // Hard delete for tables without deleted_at column
                DB::connection($connName)->table($table)
                    ->where($primaryKey, $id)
                    ->delete();
            }

            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function runQuery(Request $request)
    {
        $connectionKey = $request->get('connection', 'primary');
        $connName = $this->configureActiveProjectDb($connectionKey);

        if (!$connName) {
            return response()->json(['type' => 'error', 'error' => 'No database configured']);
        }

        $sql = $request->input('query', '');

        if (empty(trim($sql))) {
            return response()->json(['type' => 'error', 'error' => 'Query is empty']);
        }

        $result = $this->dbService->runQuery($connName, $sql);

        // Store in query history
        $history = session('query_history', []);
        array_unshift($history, ['query' => $sql, 'time' => now()->toDateTimeString()]);
        session(['query_history' => array_slice($history, 0, 10)]);

        return response()->json($result);
    }

    public function exportTable(Request $request, string $table, string $format)
    {
        $connectionKey = $request->get('connection', 'primary');
        $connName = $this->configureActiveProjectDb($connectionKey);

        if (!$connName) {
            return response()->json(['error' => 'No database configured']);
        }

        $result = $this->dbService->exportTable($connName, $table, $format);

        $mimeType = $format === 'json' ? 'application/json' : 'text/csv';

        return response()->streamDownload(function () use ($result) {
            echo $result['content'];
        }, $result['filename'], ['Content-Type' => $mimeType]);
    }

    public function connections(Request $request)
    {
        $project = $this->projectService->getActiveProject();
        $connections = [];

        if ($project && $project['type'] === 'laravel') {
            $env = $this->projectService->readEnv($project['path']);
            $connections = $this->dbService->getConnections($env);
        }

        return response()->json(['connections' => $connections]);
    }
}
