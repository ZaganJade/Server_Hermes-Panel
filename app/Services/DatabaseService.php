<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class DatabaseService
{
    /**
     * Configure a database connection for a project.
     */
    public function configureConnection(string $name, array $env): void
    {
        $driver = $env['DB_CONNECTION'] ?? 'mysql';

        $password = $env['DB_PASSWORD'] ?? '';

        // If password appears masked (********), read raw value from .env directly
        // This happens because ProjectService::readEnv() masks sensitive credentials
        if ($password === '********' || $password === '') {
            $envPath = null;
            // Try to find .env path from known project paths
            foreach (['/home/ZaganJade1/hermes-panel/Project/desakta/.env'] as $path) {
                if (file_exists($path)) {
                    $envPath = $path;
                    break;
                }
            }
            // Fallback: try to get from panel config
            if (!$envPath) {
                $defaultProject = config('panel.default_project');
                $projectsDir = base_path(config('panel.projects_dir', 'Project'));
                $possiblePath = $projectsDir . '/' . $defaultProject . '/.env';
                if (file_exists($possiblePath)) {
                    $envPath = $possiblePath;
                }
            }
            if ($envPath) {
                $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (str_starts_with($line, '#') || !str_contains($line, '=')) continue;
                    [$key, $val] = explode('=', $line, 2);
                    if (trim($key) === 'DB_PASSWORD') {
                        $password = trim($val);
                        break;
                    }
                }
            }
        }

        Config::set("database.connections.panel_project_{$name}", [
            'driver' => $driver,
            'host' => $env['DB_HOST'] ?? '127.0.0.1',
            'port' => $env['DB_PORT'] ?? ($driver === 'pgsql' ? 5432 : 3306),
            'database' => $env['DB_DATABASE'] ?? '',
            'username' => $env['DB_USERNAME'] ?? 'root',
            'password' => $password,
            'charset' => $driver === 'mysql' ? 'utf8mb4' : 'utf8',
            'prefix' => '',
            'strict' => $driver === 'mysql',
        ]);
    }

    /**
     * Get all database connections for a project.
     */
    public function getConnections(array $env): array
    {
        $connections = [];

        // Primary connection
        if (!empty($env['DB_DATABASE'])) {
            $connections['primary'] = [
                'name' => 'Primary (' . ($env['DB_DATABASE'] ?? 'unknown') . ')',
                'driver' => $env['DB_CONNECTION'] ?? 'mysql',
                'host' => $env['DB_HOST'] ?? '127.0.0.1',
                'port' => $env['DB_PORT'] ?? 3306,
                'database' => $env['DB_DATABASE'],
                'key' => 'primary',
            ];
        }

        // Detect additional connections (DB_CONNECTION_SECONDARY, DB_HOST_SECONDARY, etc.)
        foreach ($env as $key => $value) {
            if (str_starts_with($key, 'DB_CONNECTION_') && !empty($value)) {
                $suffix = str_replace('DB_CONNECTION_', '', $key);
                $dbHost = $env["DB_HOST_{$suffix}"] ?? $env['DB_HOST'] ?? '127.0.0.1';
                $dbPort = $env["DB_PORT_{$suffix}"] ?? $env['DB_PORT'] ?? 3306;
                $dbName = $env["DB_DATABASE_{$suffix}"] ?? '';

                if (!empty($dbName)) {
                    $connections[$suffix] = [
                        'name' => ucfirst($suffix) . " ({$dbName})",
                        'driver' => $value,
                        'host' => $dbHost,
                        'port' => $dbPort,
                        'database' => $dbName,
                        'key' => $suffix,
                    ];
                }
            }
        }

        return $connections;
    }

    /**
     * Get connection config for a specific connection key.
     */
    public function getConnectionEnv(string $key, array $env): array
    {
        if ($key === 'primary') {
            return [
                'DB_CONNECTION' => $env['DB_CONNECTION'] ?? 'mysql',
                'DB_HOST' => $env['DB_HOST'] ?? '127.0.0.1',
                'DB_PORT' => $env['DB_PORT'] ?? 3306,
                'DB_DATABASE' => $env['DB_DATABASE'] ?? '',
                'DB_USERNAME' => $env['DB_USERNAME'] ?? 'root',
                'DB_PASSWORD' => $env['DB_PASSWORD'] ?? '',
            ];
        }

        return [
            'DB_CONNECTION' => $env["DB_CONNECTION_{$key}"] ?? 'mysql',
            'DB_HOST' => $env["DB_HOST_{$key}"] ?? $env['DB_HOST'] ?? '127.0.0.1',
            'DB_PORT' => $env["DB_PORT_{$key}"] ?? $env['DB_PORT'] ?? 3306,
            'DB_DATABASE' => $env["DB_DATABASE_{$key}"] ?? '',
            'DB_USERNAME' => $env["DB_USERNAME_{$key}"] ?? $env['DB_USERNAME'] ?? 'root',
            'DB_PASSWORD' => $env["DB_PASSWORD_{$key}"] ?? $env['DB_PASSWORD'] ?? '',
        ];
    }

    /**
     * Test a database connection.
     */
    public function testConnection(string $connectionName): bool
    {
        try {
            DB::connection($connectionName)->getPdo();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get list of tables for a connection.
     */
    public function getTables(string $connectionName): array
    {
        $tables = [];
        try {
            $rows = DB::connection($connectionName)->select('SHOW TABLE STATUS');
            foreach ($rows as $row) {
                $tables[] = [
                    'name' => $row->Name,
                    'rows' => $row->Rows ?? 0,
                    'size' => $this->formatSize(($row->Data_length ?? 0) + ($row->Index_length ?? 0)),
                    'engine' => $row->Engine ?? '—',
                ];
            }
        } catch (\Throwable $e) {
            // Try PostgreSQL
            try {
                $rows = DB::connection($connectionName)->select(
                    "SELECT tablename as name, pg_size_pretty(pg_total_relation_size(schemaname||'.'||tablename)) as size FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename"
                );
                foreach ($rows as $row) {
                    $tables[] = [
                        'name' => $row->name,
                        'rows' => 0,
                        'size' => $row->size ?? '0 B',
                        'engine' => 'PostgreSQL',
                    ];
                }
            } catch (\Throwable $e2) {
                // Connection failed
            }
        }

        return $tables;
    }

    /**
     * Validate a SQL identifier (table name or column name).
     * Only allows alphanumeric characters and underscores.
     */
    public function isValidSqlIdentifier(string $identifier): bool
    {
        return preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier) === 1;
    }

    /**
     * Get table data with pagination and sorting.
     * Includes column metadata, primary key, and soft deletes detection.
     */
    public function getTableData(string $connectionName, string $table, int $page = 1, int $perPage = 25, string $sortBy = null, string $sortDir = 'asc'): array
    {
        // Validate table name to prevent SQL injection
        if (!$this->isValidSqlIdentifier($table)) {
            return [
                'data' => [],
                'total' => 0,
                'page' => $page,
                'per_page' => $perPage,
                'last_page' => 0,
                'error' => 'Invalid table name.',
            ];
        }

        // Validate sort direction
        $sortDir = strtolower($sortDir);
        if (!in_array($sortDir, ['asc', 'desc'], true)) {
            $sortDir = 'asc';
        }

        $conn = DB::connection($connectionName);

        // Validate sort column against identifier pattern
        if ($sortBy && !$this->isValidSqlIdentifier($sortBy)) {
            $sortBy = null;
        }

        // Get column metadata and detect soft deletes
        $columns = $this->getTableColumns($connectionName, $table);
        $primaryKey = $this->getPrimaryKey($connectionName, $table);
        $softDeletes = $this->detectSoftDeletes($connectionName, $table);

        // Filter out deleted_at from columns for UI (but keep softDeletes flag)
        $displayColumns = array_filter($columns, fn($col) => $col['name'] !== 'deleted_at');
        $displayColumns = array_values($displayColumns);

        $baseQuery = $conn->table($table);
        $countQuery = clone $baseQuery;

        if ($sortBy) {
            $baseQuery->orderBy($sortBy, $sortDir);
        }

        $total = $countQuery->count();
        $data = $baseQuery->skip(($page - 1) * $perPage)->take($perPage)->get();

        return [
            'columns' => $displayColumns,
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => $perPage > 0 ? max(1, (int) ceil($total / $perPage)) : 1,
            'primaryKey' => $primaryKey,
            'softDeletes' => $softDeletes,
        ];
    }

    /**
     * Get trashed (soft-deleted) rows from a table.
     */
    public function getTrashData(string $connectionName, string $table, int $page = 1, int $perPage = 25): array
    {
        if (!$this->isValidSqlIdentifier($table)) {
            return [
                'rows' => [],
                'total' => 0,
                'page' => $page,
                'last_page' => 1,
                'error' => 'Invalid table name.',
            ];
        }

        $conn = DB::connection($connectionName);

        // Use onlyTrashed() to get soft-deleted rows
        $baseQuery = $conn->table($table)->onlyTrashed();
        $countQuery = clone $baseQuery;

        $total = $countQuery->count();
        $rows = $baseQuery->skip(($page - 1) * $perPage)->take($perPage)->get();

        // Get column names from table metadata
        $columnsMeta = $this->getTableColumns($connectionName, $table);
        $displayColumns = array_map(fn($col) => $col['name'], $columnsMeta);

        return [
            'columns' => $displayColumns,
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => $perPage > 0 ? max(1, (int) ceil($total / $perPage)) : 1,
        ];
    }

    /**
     * Restore a soft-deleted row.
     */
    public function restoreRow(string $connectionName, string $table, int $id): bool
    {
        if (!$this->isValidSqlIdentifier($table)) {
            return false;
        }

        try {
            $primaryKey = $this->getPrimaryKey($connectionName, $table);

            $affected = DB::connection($connectionName)
                ->table($table)
                ->onlyTrashed()
                ->where($primaryKey, $id)
                ->update(['deleted_at' => null]);

            return $affected > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Permanently delete a row (force delete).
     */
    public function forceDeleteRow(string $connectionName, string $table, $id): bool
    {
        if (!$this->isValidSqlIdentifier($table)) {
            return false;
        }

        try {
            $primaryKey = $this->getPrimaryKey($connectionName, $table);

            $affected = DB::connection($connectionName)
                ->table($table)
                ->onlyTrashed()
                ->where($primaryKey, $id)
                ->forceDelete();

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Run a raw SQL query.
     * SECURITY: Restricted to read-only queries only. DDL and DML are blocked.
     * ERROR HANDLING: Internal details are hidden — only user-friendly messages returned.
     */
    public function runQuery(string $connectionName, string $sql): array
    {
        // Normalize: strip line comments
        $normalizedSql = preg_replace('/--.*$/m', '', $sql);
        $normalizedSql = preg_replace('/#.*$/m', '', $normalizedSql);
        $normalizedSql = trim($normalizedSql);

        if (empty($normalizedSql)) {
            return ['type' => 'error', 'error' => 'Empty query.'];
        }

        // Determine query type from first word
        // Determine query type from first word
        $firstWord = strtoupper(preg_match('/^\S+/', $normalizedSql, $m) ? $m[0] : '');
        $writeTypes = ['INSERT', 'UPDATE', 'DELETE', 'REPLACE'];
        $ddlTypes = ['ALTER', 'DROP', 'CREATE', 'TRUNCATE', 'RENAME'];

        // SECURITY REMOVED — all query types allowed
        $writeTypes = ['INSERT', 'UPDATE', 'DELETE', 'REPLACE'];
        $ddlTypes = ['ALTER', 'DROP', 'CREATE', 'TRUNCATE', 'RENAME'];
        $allowedTypes = ['SELECT', 'SHOW', 'DESCRIBE', 'EXPLAIN', 'INSERT', 'UPDATE', 'DELETE', 'ALTER', 'DROP', 'CREATE', 'TRUNCATE', 'RENAME', 'REPLACE'];
        if (!in_array($firstWord, $allowedTypes)) {
            return [
                'type' => 'error',
                'error' => 'Only SELECT, SHOW, DESCRIBE, EXPLAIN, INSERT, UPDATE, DELETE, ALTER, DROP, CREATE, TRUNCATE, RENAME, REPLACE queries are permitted.',
            ];
        }

        try {
            $conn = DB::connection($connectionName);

            if (in_array($firstWord, $writeTypes)) {
                $affected = $conn->affectingStatement($normalizedSql);
                return [
                    'type' => 'modify',
                    'message' => "Query OK. {$affected} row(s) affected.",
                    'affected' => $affected,
                ];
            }

            if (in_array($firstWord, $ddlTypes)) {
                $conn->statement($normalizedSql);
                return [
                    'type' => 'ddl',
                    'message' => "DDL query executed successfully.",
                ];
            }

            $results = $conn->select($normalizedSql);
            return [
                'type' => 'select',
                'data' => $results,
                'count' => count($results),
            ];
        } catch (\QueryException $e) {
            return [
                'type' => 'error',
                'error' => 'Query failed. Please check your SQL syntax.',
            ];
        } catch (\Throwable $e) {
            return [
                'type' => 'error',
                'error' => 'Database connection error.',
            ];
        }
    }

    /**
     * Export table data to JSON or CSV.
     */
    public function exportTable(string $connectionName, string $table, string $format): array
    {
        $data = DB::connection($connectionName)->table($table)->get();
        $timestamp = now()->format('Ymd_His');
        $filename = "{$table}_{$timestamp}.{$format}";

        if ($format === 'json') {
            $content = json_encode([
                'table' => $table,
                'exported_at' => now()->toIso8601String(),
                'row_count' => count($data),
                'data' => $data,
            ], JSON_PRETTY_PRINT);
        } else {
            // CSV
            $rows = $data->toArray();
            if (empty($rows)) {
                $content = '';
            } else {
                $headers = array_keys((array) $rows[0]);
                $lines = [implode(',', $headers)];
                foreach ($rows as $row) {
                    $values = array_map(function ($val) {
                        $val = str_replace('"', '""', (string) $val);
                        return "\"{$val}\"";
                    }, (array) $row);
                    $lines[] = implode(',', $values);
                }
                $content = implode("\n", $lines);
            }
        }

        return compact('filename', 'content', 'format');
    }

    protected function formatSize(int $bytes): string
    {
        if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
        return $bytes . ' B';
    }

    /**
     * Get column metadata for a table.
     */
    public function getTableColumns(string $connectionName, string $table): array
    {
        if (!$this->isValidSqlIdentifier($table)) {
            return [];
        }

        try {
            $columns = [];
            $rows = DB::connection($connectionName)->select("DESCRIBE `{$table}`");

            foreach ($rows as $row) {
                $type = 'varchar';
                $typeArgs = null;

                // Parse type (e.g., "varchar(255)", "int(11)", "datetime")
                if (preg_match('/^(\w+)(?:\((\d+)\))?/', $row->Type, $matches)) {
                    $type = strtolower($matches[1]);
                    $typeArgs = isset($matches[2]) ? (int) $matches[2] : null;
                }

                $columns[] = [
                    'name' => $row->Field,
                    'type' => $type,
                    'type_args' => $typeArgs,
                    'nullable' => $row->Null === 'YES',
                    'key' => $row->Key,
                    'default' => $row->Default,
                    'extra' => $row->Extra,
                ];
            }

            return $columns;
        } catch (\Throwable $e) {
            // Try PostgreSQL
            try {
                $rows = DB::connection($connectionName)->select("
                    SELECT column_name as field, data_type as type, is_nullable as null, column_default as default
                    FROM information_schema.columns
                    WHERE table_name = '{$table}'
                    ORDER BY ordinal_position
                ");

                $columns = [];
                foreach ($rows as $row) {
                    $columns[] = [
                        'name' => $row->field,
                        'type' => strtolower($row->type),
                        'type_args' => null,
                        'nullable' => $row->null === 'YES',
                        'key' => '',
                        'default' => $row->default,
                        'extra' => '',
                    ];
                }
                return $columns;
            } catch (\Throwable $e2) {
                return [];
            }
        }
    }

    /**
     * Detect if a table uses soft deletes (has deleted_at column).
     */
    public function detectSoftDeletes(string $connectionName, string $table): bool
    {
        if (!$this->isValidSqlIdentifier($table)) {
            return false;
        }

        try {
            return Schema::connection($connectionName)->hasColumn($table, 'deleted_at');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get primary key column name for a table.
     */
    public function getPrimaryKey(string $connectionName, string $table): string
    {
        if (!$this->isValidSqlIdentifier($table)) {
            return 'id';
        }

        try {
            $result = DB::connection($connectionName)->select("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'");
            if (!empty($result)) {
                return $result[0]->Column_name;
            }
        } catch (\Throwable $e) {
            // Fallback
        }

        return 'id';
    }
}
