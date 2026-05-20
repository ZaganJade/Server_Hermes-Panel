<?php

namespace Tests\Unit\Services\Monitoring\Readers;

use App\Services\Monitoring\ProcResolver;
use App\Services\Monitoring\Readers\ConnectionReader;
use Tests\TestCase;

class ConnectionReaderTest extends TestCase
{
    private string $procRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->procRoot = sys_get_temp_dir().'/hermes-conn-'.uniqid();
        mkdir($this->procRoot.'/net', 0o755, true);
        copy(__DIR__.'/../../../../Fixtures/Monitoring/proc/net-tcp', $this->procRoot.'/net/tcp');
        copy(__DIR__.'/../../../../Fixtures/Monitoring/proc/net-tcp6', $this->procRoot.'/net/tcp6');
    }

    protected function tearDown(): void
    {
        @unlink($this->procRoot.'/net/tcp');
        @unlink($this->procRoot.'/net/tcp6');
        @rmdir($this->procRoot.'/net');
        @rmdir($this->procRoot);
        parent::tearDown();
    }

    public function test_counts_only_established_connections_across_v4_and_v6(): void
    {
        $reader = new ConnectionReader;

        $result = $reader->read(new ProcResolver($this->procRoot, '/sys'));

        // Fixture: tcp has 2 LISTEN (state 0A), 2 ESTABLISHED (01),
        // 1 TIME_WAIT (06). tcp6 has 1 LISTEN, 1 ESTABLISHED.
        // Total ESTABLISHED = 2 + 1 = 3.
        $this->assertSame(3, $result['tcp_established']);
    }

    public function test_returns_zero_when_files_missing(): void
    {
        @unlink($this->procRoot.'/net/tcp');
        @unlink($this->procRoot.'/net/tcp6');

        $reader = new ConnectionReader;
        $result = $reader->read(new ProcResolver($this->procRoot, '/sys'));

        $this->assertSame(0, $result['tcp_established']);
    }
}
