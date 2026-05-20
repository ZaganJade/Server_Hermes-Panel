<?php

namespace Tests\Unit\Services;

use App\Services\FileService;
use App\Services\ProjectService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Covers the upload / create / rename hardening introduced in HIGH-2 and
 * HIGH-4: name-segment validation and the executable-extension blocklist.
 */
class FileServiceUploadGuardsTest extends TestCase
{
    private FileService $files;

    private string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = sys_get_temp_dir().'/hermes-fs-'.uniqid();
        mkdir($this->sandbox, 0755, true);

        // Stub ProjectService so getBasePath() returns our sandbox.
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

    #[DataProvider('unsafeNames')]
    public function test_assert_safe_name_rejects_dangerous_inputs(string $name): void
    {
        $this->assertNotNull(
            $this->files->assertSafeName($name),
            "Expected '{$name}' to be rejected.",
        );
    }

    public static function unsafeNames(): array
    {
        return [
            'empty' => [''],
            'whitespace' => ['   '],
            'traversal' => ['..'],
            'traversal-prefix' => ['../etc/passwd'],
            'forward-slash' => ['foo/bar.txt'],
            'back-slash' => ['foo\\bar.txt'],
            'leading-dot' => ['.htaccess'],
            'leading-dot-other' => ['.env'],
            'shell-meta-pipe' => ['file|name'],
            'shell-meta-semicolon' => ['file;name'],
            'shell-meta-redirect' => ['file>name'],
            'shell-meta-dollar' => ['file$name'],
            'shell-meta-backtick' => ['file`name'],
            'control-char' => ["file\x01name"],
            'too-long' => [str_repeat('a', 256)],
        ];
    }

    #[DataProvider('safeNames')]
    public function test_assert_safe_name_accepts_normal_inputs(string $name): void
    {
        $this->assertNull(
            $this->files->assertSafeName($name),
            "Expected '{$name}' to be accepted.",
        );
    }

    public static function safeNames(): array
    {
        return [
            'plain' => ['report.txt'],
            'with-space' => ['my file.txt'],
            'with-dash' => ['my-file.txt'],
            'with-underscore' => ['my_file.txt'],
            'multi-extension' => ['archive.tar.gz'],
            'no-extension' => ['LICENSE'],
        ];
    }

    #[DataProvider('blockedExtensions')]
    public function test_is_allowed_filename_blocks_executable_extensions(string $name): void
    {
        $this->assertFalse(
            $this->files->isAllowedFilename($name),
            "Expected '{$name}' to be blocked.",
        );
    }

    public static function blockedExtensions(): array
    {
        return [
            'php' => ['shell.php'],
            'phar' => ['payload.phar'],
            'phtml' => ['shell.phtml'],
            'php8' => ['shell.php8'],
            'pl' => ['cmd.pl'],
            'cgi' => ['cmd.cgi'],
            'jsp' => ['shell.jsp'],
            'asp' => ['shell.asp'],
            'aspx' => ['shell.aspx'],
            'sh' => ['cmd.sh'],
            'bash' => ['cmd.bash'],
            'exe' => ['payload.exe'],
            'bat' => ['cmd.bat'],
            'msi' => ['installer.msi'],
            'dll' => ['library.dll'],
            'vbs' => ['cmd.vbs'],
            'apache-htaccess' => ['.htaccess'],
            'apache-htpasswd' => ['.htpasswd'],
            'iis-config' => ['web.config'],
            'php-userini' => ['.user.ini'],
        ];
    }

    #[DataProvider('allowedExtensions')]
    public function test_is_allowed_filename_accepts_normal_files(string $name): void
    {
        $this->assertTrue(
            $this->files->isAllowedFilename($name),
            "Expected '{$name}' to be allowed.",
        );
    }

    public static function allowedExtensions(): array
    {
        return [
            'txt' => ['readme.txt'],
            'md' => ['README.md'],
            'json' => ['composer.json'],
            'yaml' => ['config.yaml'],
            'png' => ['logo.png'],
            'jpg' => ['photo.jpg'],
            'pdf' => ['report.pdf'],
            'css' => ['app.css'],
            'js' => ['app.js'],
            'no-extension' => ['LICENSE'],
        ];
    }

    public function test_create_rejects_traversal_name(): void
    {
        $result = $this->files->create('/', '../../escape.txt', 'file');

        $this->assertFalse($result['success']);
        $this->assertFileDoesNotExist($this->sandbox.'/../escape.txt');
    }

    public function test_create_rejects_php_extension_for_files(): void
    {
        $result = $this->files->create('/', 'shell.php', 'file');

        $this->assertFalse($result['success']);
        $this->assertFileDoesNotExist($this->sandbox.'/shell.php');
    }

    public function test_create_allows_safe_filename(): void
    {
        $result = $this->files->create('/', 'report.txt', 'file');

        $this->assertTrue($result['success']);
        $this->assertFileExists($this->sandbox.'/report.txt');
    }

    public function test_create_allows_directory(): void
    {
        $result = $this->files->create('/', 'images', 'directory');

        $this->assertTrue($result['success']);
        $this->assertDirectoryExists($this->sandbox.'/images');
    }
}
