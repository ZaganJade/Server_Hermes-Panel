<?php

namespace Tests\Unit\Services\Monitoring\Readers;

use App\Services\Monitoring\ProcResolver;
use App\Services\Monitoring\Readers\PortReader;
use Tests\TestCase;

class PortReaderTest extends TestCase
{
    public function test_parses_ss_output_with_users_field(): void
    {
        $ssOutput = <<<'TXT'
State    Recv-Q   Send-Q   Local Address:Port      Peer Address:Port    Process
LISTEN   0        128      0.0.0.0:80              0.0.0.0:*           users:(("nginx",pid=1234,fd=6))
LISTEN   0        128      127.0.0.1:3306          0.0.0.0:*           users:(("mysqld",pid=5678,fd=22))
LISTEN   0        128      [::]:443                [::]:*              users:(("nginx",pid=1234,fd=8))
TXT;

        $reader = new FakeSsReader(['ss.tcp' => $ssOutput, 'ss.udp' => '']);
        $rows = $reader->read(new ProcResolver(sys_get_temp_dir(), '/sys'));

        $this->assertCount(3, $rows);

        $port80 = collect($rows)->firstWhere('port', 80);
        $this->assertSame('tcp', $port80['proto']);
        $this->assertSame(1234, $port80['pid']);
        $this->assertSame('nginx', $port80['process_name']);

        $port3306 = collect($rows)->firstWhere('port', 3306);
        $this->assertSame(5678, $port3306['pid']);
        $this->assertSame('mysqld', $port3306['process_name']);
    }

    public function test_falls_back_to_netstat_when_ss_returns_null(): void
    {
        $netstatOutput = <<<'TXT'
Active Internet connections (only servers)
Proto Recv-Q Send-Q Local Address           Foreign Address         State       PID/Program name
tcp        0      0 0.0.0.0:80              0.0.0.0:*               LISTEN      1234/nginx
tcp        0      0 127.0.0.1:3306          0.0.0.0:*               LISTEN      5678/mysqld
TXT;

        $reader = new FakeNetstatReader([
            'ss.tcp' => null,
            'ss.udp' => null,
            'netstat.tcp' => $netstatOutput,
            'netstat.udp' => '',
        ]);
        $rows = $reader->read(new ProcResolver(sys_get_temp_dir(), '/sys'));

        $port80 = collect($rows)->firstWhere('port', 80);
        $this->assertSame(1234, $port80['pid']);
        $this->assertSame('nginx', $port80['process_name']);
    }

    public function test_returns_empty_when_neither_command_available(): void
    {
        $reader = new FakeSsReader(['ss.tcp' => null, 'ss.udp' => null, 'netstat.tcp' => null, 'netstat.udp' => null]);
        $rows = $reader->read(new ProcResolver(sys_get_temp_dir(), '/sys'));

        $this->assertSame([], $rows);
    }

    public function test_skips_lines_without_address_port_match(): void
    {
        $ssOutput = "Header line that doesn't match\n0.0.0.0:8080 some line\n";
        $reader = new FakeSsReader(['ss.tcp' => $ssOutput, 'ss.udp' => '']);
        $rows = $reader->read(new ProcResolver(sys_get_temp_dir(), '/sys'));

        $this->assertCount(1, $rows);
        $this->assertSame(8080, $rows[0]['port']);
    }
}

class FakeSsReader extends PortReader
{
    public function __construct(private array $stubs) {}

    protected function runSs(string $proto): ?string
    {
        return $this->stubs['ss.'.$proto] ?? null;
    }

    protected function runNetstat(string $proto): ?string
    {
        return $this->stubs['netstat.'.$proto] ?? null;
    }
}

class FakeNetstatReader extends FakeSsReader
{
    // Same shape — both share the constructor — naming for clarity in tests.
}
