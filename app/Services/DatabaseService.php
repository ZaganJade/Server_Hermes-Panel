<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;

class DatabaseService
{
    /**
     * Configure a database connection for a project.
     */
    public function configureConnection(string $name, array $env): void
    {
        $driver = $env['DB_CONNECTION'] ?? 'mysql';

        Config::set("database.connections.panel_project_{$name}", [
            'driver' => $driver,
            'host' => $env['DB_HOST'] ?? '127.0.0.1',
            'port' => $env['DB_PORT'] ?? ($driver === 'pgsql' ? 5432 : 3306),
            'database' => $env['DB_DATABASE'] ?? '',
            'username' => $env['DB_USERNAME'] ?? 'root',
            'password' => $env['DB_PASSWORD'] ?? '',
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
     * Get table data with pagination and sorting.
     */
    public function getTableData(string $connectionName, string $table, int $page = 1, int $perPage = 25, string $sortBy = null, string $sortDir = 'asc'): array
    {
        $query = DB::connection($connectionName)->table($table);

        if ($sortBy) {
            $query->orderBy($sortBy, $sortDir);
        }

        $total = $query->count();
        $data = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        return [
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => ceil($total / $perPage),
        ];
    }

    /**
     * Run a raw SQL query.
     */
    public function runQuery(string $connectionName, string $sql): array
    {
        try {
            $isSelect = preg_match('/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)/i', $sql);
            $isDdl = preg_match('/^\s*(CREATE|ALTER|DROP|TRUNCATE|RENAME)/i', $sql);

            if ($isSelect) {
                $results = DB::connection($connectionName)->select($sql);
                return [
                    'type' => 'select',
                    'data' => $results,
                    'count' => count($results),
                ];
            }

            $affected = DB::connection($connectionName)->affectingStatement($sql);

            return [
                'type' => $isDdl ? 'ddl' : 'modify',
                'affected' => $affected,
                'message' => $isDdl
                    ? 'Query executed successfully.'
                    : "{$affected} row(s) affected.",
            ];
        } catch (\Throwable $e) {
            return [
                'type' => 'error',
                'error' => $e->getMessage(),
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
}
