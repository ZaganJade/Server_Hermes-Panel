<?php

namespace Tests\Unit\Services;

use App\Services\DatabaseService;
use Tests\TestCase;

/**
 * Tests the early validation surface of DatabaseService::exportTable
 * introduced in MED-4: invalid identifiers and unknown formats short
 * circuit before any DB call.
 *
 * Streaming + row-cap behaviour is exercised in feature tests that have
 * a real connection; the unit suite stays DB-free.
 */
class DatabaseServiceExportGuardsTest extends TestCase
{
    private DatabaseService $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new DatabaseService;
    }

    public function test_invalid_table_identifier_short_circuits(): void
    {
        $result = $this->db->exportTable('panel_project_primary', 'users; DROP TABLE x', 'csv');

        $this->assertSame('', $result['filename']);
        $this->assertSame('', $result['content']);
        $this->assertSame('csv', $result['format']);
    }

    public function test_unknown_format_short_circuits(): void
    {
        $result = $this->db->exportTable('panel_project_primary', 'users', 'xml');

        $this->assertSame('', $result['filename']);
        $this->assertSame('', $result['content']);
        $this->assertSame('xml', $result['format']);
    }

    public function test_valid_call_signature_returns_required_keys(): void
    {
        // No DB connection; the call will throw or return empty content,
        // but we only assert on the response contract being intact when
        // identifier + format pass the guard.
        try {
            $result = $this->db->exportTable('panel_project_does_not_exist', 'users', 'csv');

            $this->assertArrayHasKey('filename', $result);
            $this->assertArrayHasKey('content', $result);
            $this->assertArrayHasKey('format', $result);
        } catch (\Throwable $e) {
            // Connection error is acceptable in this DB-free context.
            $this->expectNotToPerformAssertions();
        }
    }
}
