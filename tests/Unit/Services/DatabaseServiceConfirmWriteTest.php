<?php

namespace Tests\Unit\Services;

use App\Services\DatabaseService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Covers the runQuery confirm_write flow restored in CRIT-2.
 *
 * Read queries (SELECT/SHOW/DESCRIBE/EXPLAIN) execute directly. Write
 * (INSERT/UPDATE/DELETE/REPLACE) and DDL (ALTER/DROP/CREATE/TRUNCATE/
 * RENAME) require an explicit `confirm_write=true` flag. Without it the
 * service returns `type=confirm_required` and never touches the database.
 *
 * We exercise the gate alone (no DB connection needed) so the test
 * stays deterministic on every host.
 */
class DatabaseServiceConfirmWriteTest extends TestCase
{
    private DatabaseService $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new DatabaseService;
    }

    #[DataProvider('mutatingQueries')]
    public function test_mutating_queries_require_confirmation(string $sql): void
    {
        $result = $this->db->runQuery('panel_project_primary', $sql, false);

        $this->assertSame('confirm_required', $result['type'] ?? null);
        $this->assertArrayHasKey('error', $result);
        $this->assertNotEmpty($result['error']);
    }

    public static function mutatingQueries(): array
    {
        return [
            'insert' => ["INSERT INTO users (name) VALUES ('x')"],
            'update' => ["UPDATE users SET name='x' WHERE id = 1"],
            'delete' => ['DELETE FROM users WHERE id = 1'],
            'replace' => ["REPLACE INTO users (id, name) VALUES (1, 'x')"],
            'alter' => ['ALTER TABLE users ADD COLUMN nickname VARCHAR(255)'],
            'drop' => ['DROP TABLE users'],
            'create' => ['CREATE TABLE foo (id INT)'],
            'truncate' => ['TRUNCATE users'],
            'rename' => ['RENAME TABLE users TO members'],
            'lowercase-delete' => ['delete from users'],
            'mixed-case-drop' => ['Drop Table users'],
            'comment-prefixed' => ["-- comment\nDELETE FROM users"],
        ];
    }

    #[DataProvider('unknownQueries')]
    public function test_unknown_query_type_is_rejected(string $sql): void
    {
        $result = $this->db->runQuery('panel_project_primary', $sql, true);

        $this->assertSame('error', $result['type'] ?? null);
    }

    public static function unknownQueries(): array
    {
        return [
            'empty' => [''],
            'whitespace' => ['   '],
            'gibberish' => ['NOTAVERB foo'],
            'pragma' => ['PRAGMA foreign_keys = ON'],
            'set' => ['SET autocommit = 0'],
            'use' => ['USE other_db'],
        ];
    }

    #[DataProvider('readQueries')]
    public function test_read_queries_pass_the_gate_without_confirmation(string $sql): void
    {
        $result = $this->db->runQuery('panel_project_primary_does_not_exist', $sql, false);

        // The gate accepts the query type — we don't have a real DB so
        // execution itself fails with a connection error, but the gate
        // never returns confirm_required for a read.
        $this->assertNotSame('confirm_required', $result['type'] ?? null);
    }

    public static function readQueries(): array
    {
        return [
            'select' => ['SELECT 1'],
            'show-tables' => ['SHOW TABLES'],
            'describe' => ['DESCRIBE users'],
            'desc-shorthand' => ['DESC users'],
            'explain' => ['EXPLAIN SELECT * FROM users'],
        ];
    }

    public function test_confirm_write_true_skips_the_gate(): void
    {
        $result = $this->db->runQuery(
            'panel_project_primary_does_not_exist',
            'DELETE FROM users',
            confirmWrite: true,
        );

        // Connection won't resolve, but the service must NOT short-circuit
        // with confirm_required when the flag is set.
        $this->assertNotSame('confirm_required', $result['type'] ?? null);
    }
}
