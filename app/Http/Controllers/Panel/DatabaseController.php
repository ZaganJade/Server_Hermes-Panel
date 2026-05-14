<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Services\DatabaseService;
use App\Services\ProjectService;
use Illuminate\Http\Request;

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

            // Detect primary key column
            $columns = \Schema::connection($connName)->getColumnListing($table);

            DB::connection($connName)->table($table)
                ->where('id', $id)
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
            DB::connection($connName)->table($table)->where('id', $id)->delete();
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
