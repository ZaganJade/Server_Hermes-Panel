<?php

namespace Tests\Unit\Controllers;

use App\Http\Controllers\Panel\ToolController;
use App\Services\ProjectService;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * Covers the artisan allowlist / blocklist gate restored in CRIT-3.
 *
 * The gate is `isArtisanCommandAllowed()` plus the `getAllowed…()` and
 * `getBlocked…()` lists. Reflection is the lightest way to exercise
 * the policy in isolation — running the full HTTP path would also need
 * a real project, real `php` binary, and a writable Laravel project to
 * point Process::path() at.
 */
class ToolControllerArtisanGateTest extends TestCase
{
    private ToolController $controller;

    private \ReflectionMethod $isAllowed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = new ToolController(app(ProjectService::class));

        $reflection = new ReflectionClass($this->controller);
        $this->isAllowed = $reflection->getMethod('isArtisanCommandAllowed');
        $this->isAllowed->setAccessible(true);
    }

    private function allow(string $subcommand): bool
    {
        return (bool) $this->isAllowed->invoke($this->controller, $subcommand);
    }

    #[DataProvider('allowedDefaults')]
    public function test_default_allowlist_accepts_safe_subcommands(string $subcommand): void
    {
        $this->assertTrue($this->allow($subcommand));
    }

    public static function allowedDefaults(): array
    {
        return [
            'about' => ['about'],
            'list' => ['list'],
            'route-list' => ['route:list'],
            'route-cache' => ['route:cache'],
            'view-cache' => ['view:cache'],
            'config-cache' => ['config:cache'],
            'cache-clear' => ['cache:clear'],
            'queue-work' => ['queue:work'],
            'queue-listen' => ['queue:listen'],
            'queue-failed' => ['queue:failed'],
            'schedule-list' => ['schedule:list'],
            'storage-link' => ['storage:link'],
            'migrate-forward' => ['migrate'],
            'migrate-status' => ['migrate:status'],
            'optimize' => ['optimize'],
            'pail' => ['pail'],
        ];
    }

    #[DataProvider('blockedSubcommands')]
    public function test_blocklist_beats_unknown_and_destructive_subcommands(string $subcommand): void
    {
        $this->assertFalse($this->allow($subcommand));
    }

    public static function blockedSubcommands(): array
    {
        return [
            'tinker' => ['tinker'],
            'key-generate' => ['key:generate'],
            'env-encrypt' => ['env:encrypt'],
            'env-decrypt' => ['env:decrypt'],
            'down' => ['down'],
            'up' => ['up'],
            'db-wipe' => ['db:wipe'],
            'db-seed' => ['db:seed'],
            'migrate-fresh' => ['migrate:fresh'],
            'migrate-rollback' => ['migrate:rollback'],
            'migrate-reset' => ['migrate:reset'],
            'migrate-refresh' => ['migrate:refresh'],
            'serve' => ['serve'],
            'reverb-start' => ['reverb:start'],
            'reverb-restart' => ['reverb:restart'],
        ];
    }

    public function test_unknown_subcommands_are_rejected(): void
    {
        $this->assertFalse($this->allow('inspire'));
        $this->assertFalse($this->allow('vendor:publish'));
        $this->assertFalse($this->allow('completely-made-up-command'));
    }

    public function test_blank_subcommand_rejected(): void
    {
        $this->assertFalse($this->allow(''));
        $this->assertFalse($this->allow('   '));
    }

    public function test_blocklist_wins_when_operator_adds_blocked_to_allowlist(): void
    {
        // Even if a misguided operator extends the allowlist with `tinker`,
        // the blocklist should still reject it.
        config(['panel.allowed_artisan_commands' => ['tinker']]);

        $this->assertFalse(
            $this->allow('tinker'),
            'Blocklist must override the allowlist when an operator adds a blocked subcommand.',
        );
    }

    public function test_panel_config_can_extend_allowlist(): void
    {
        $this->assertFalse($this->allow('vendor:publish'));

        config(['panel.allowed_artisan_commands' => ['vendor:publish']]);

        $this->assertTrue(
            $this->allow('vendor:publish'),
            'Operator-extended allowlist must accept new safe subcommands.',
        );
    }

    public function test_subcommand_match_is_case_insensitive(): void
    {
        $this->assertTrue($this->allow('LIST'));
        $this->assertTrue($this->allow('Route:List'));
    }
}
