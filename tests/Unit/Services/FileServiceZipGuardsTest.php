<?php

namespace Tests\Unit\Services;

use App\Services\FileService;
use App\Services\ProjectService;
use Tests\TestCase;

/**
 * Tests the byte/entry caps on FileService::zipDirectory introduced in
 * HIGH-3. The default Phase-2 caps (1 GB / 50k entries) are too generous
 * for unit tests, so we shrink them via panel.zip_max_bytes /
 * panel.zip_max_entries and observe that an oversized tree returns null
 * (and the partial zip is unlinked).
 */
class FileServiceZipGuardsTest extends TestCase
{
    private FileService $files;

    private string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = sys_get_temp_dir().'/hermes-zip-'.uniqid();
        mkdir($this->sandbox.'/big', 0755, true);

        $project = new class($this->sandbox) extends ProjectService
        {
            public function __construct(public string $sandbox)
            {
                parent::__construct();
            }

            public function getActiveProject(): ?array
            {
                return [
                    'name' => 'sandbox',
                    'folder' => 'sandbox',
                    'path' => $this->sandbox,
                    'type' => 'generic',
                ];
            }
        };

        $this->files = new FileService($project);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->sandbox);
        parent::tearDown();
    }

    private function rmrf(string $path): void
    {
        if (! file_exists($path)) {
            return;
        }
        if (is_file($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->rmrf($path.'/'.$entry);
        }
        @rmdir($path);
    }

    public function test_small_directory_zips_successfully(): void
    {
        file_put_contents($this->sandbox.'/big/a.txt', 'hello');
        file_put_contents($this->sandbox.'/big/b.txt', 'world');

        $zip = $this->files->zipDirectory('/big');

        $this->assertNotNull($zip);
        $this->assertFileExists($zip);
        @unlink($zip);
    }

    public function test_zip_aborts_when_byte_cap_exceeded(): void
    {
        // 5 KB of payload across two files; cap at 4 KB to force abort.
        file_put_contents($this->sandbox.'/big/a.bin', str_repeat('A', 3 * 1024));
        file_put_contents($this->sandbox.'/big/b.bin', str_repeat('B', 2 * 1024));

        config(['panel.zip_max_bytes' => 4 * 1024]);

        $zip = $this->files->zipDirectory('/big');

        $this->assertNull($zip, 'zipDirectory must return null when the byte cap is exceeded.');
    }

    public function test_zip_aborts_when_entry_cap_exceeded(): void
    {
        for ($i = 0; $i < 12; $i++) {
            file_put_contents($this->sandbox.'/big/'.$i.'.txt', 'x');
        }

        config(['panel.zip_max_entries' => 5]);

        $zip = $this->files->zipDirectory('/big');

        $this->assertNull($zip, 'zipDirectory must return null when the entry cap is exceeded.');
    }

    public function test_zip_returns_null_for_unknown_path(): void
    {
        $this->assertNull($this->files->zipDirectory('/does-not-exist'));
    }
}
