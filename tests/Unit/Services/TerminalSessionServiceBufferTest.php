<?php

namespace Tests\Unit\Services;

use App\Services\TerminalCommandPolicy;
use App\Services\TerminalSessionService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Log;
use Psr\Log\NullLogger;
use Tests\TestCase;

/**
 * Tests that exercise the cache envelope (MED-2) and the history lock
 * fallback (MED-3) without requiring a real bash subprocess.
 */
class TerminalSessionServiceBufferTest extends TestCase
{
    private Repository $cache;

    private TerminalSessionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = new Repository(new ArrayStore);
        $this->service = new TerminalSessionService(
            $this->cache,
            new TerminalCommandPolicy,
        );

        config(['logging.channels.terminal-audit' => ['driver' => 'null']]);
        Log::extend('null', fn () => new NullLogger);
    }

    public function test_replay_handles_legacy_flat_buffer_payload(): void
    {
        $sessionId = 'legacy-1';

        // Pre-Phase-2 shape: a flat array of chunks, no envelope.
        $this->cache->put(
            "hermes:term:buffer:{$sessionId}",
            [
                ['ts' => 100, 'type' => 'stdout', 'data' => 'one'],
                ['ts' => 101, 'type' => 'stdout', 'data' => 'two'],
            ],
            300,
        );

        $payload = $this->service->replay($sessionId);

        $this->assertCount(2, $payload['chunks']);
        $this->assertSame('one', $payload['chunks'][0]['data']);
        $this->assertSame('two', $payload['chunks'][1]['data']);
    }

    public function test_replay_handles_new_envelope_buffer_payload(): void
    {
        $sessionId = 'envelope-1';

        $this->cache->put(
            "hermes:term:buffer:{$sessionId}",
            [
                'bytes' => 6,
                'entries' => [
                    ['ts' => 200, 'type' => 'stdout', 'data' => 'foo'],
                    ['ts' => 201, 'type' => 'stdout', 'data' => 'bar'],
                ],
            ],
            300,
        );

        $payload = $this->service->replay($sessionId);

        $this->assertCount(2, $payload['chunks']);
        $this->assertSame('foo', $payload['chunks'][0]['data']);
        $this->assertSame('bar', $payload['chunks'][1]['data']);
    }

    public function test_replay_sorts_chunks_by_timestamp(): void
    {
        $sessionId = 'sorted-1';

        $this->cache->put(
            "hermes:term:buffer:{$sessionId}",
            [
                'bytes' => 0,
                'entries' => [
                    ['ts' => 300, 'type' => 'stdout', 'data' => 'late'],
                    ['ts' => 100, 'type' => 'stdout', 'data' => 'early'],
                    ['ts' => 200, 'type' => 'stdout', 'data' => 'mid'],
                ],
            ],
            300,
        );

        $payload = $this->service->replay($sessionId);

        $this->assertSame('early', $payload['chunks'][0]['data']);
        $this->assertSame('mid', $payload['chunks'][1]['data']);
        $this->assertSame('late', $payload['chunks'][2]['data']);
    }

    public function test_replay_returns_idle_status_for_unknown_session(): void
    {
        $payload = $this->service->replay('does-not-exist');

        $this->assertSame('idle', $payload['status']);
        $this->assertSame([], $payload['chunks']);
        $this->assertNull($payload['session']);
    }

    public function test_push_history_serialises_writes_through_lock_fallback(): void
    {
        // ArrayStore doesn't implement lock(), so this exercises the
        // unlocked fallback path. The two writes must still both land.
        $this->service->pushHistory('alpha', 'first', 0);
        $this->service->pushHistory('alpha', 'second', 1);

        $history = $this->service->history('alpha');

        $this->assertCount(2, $history);
        $this->assertSame('second', $history[0]['command']);
        $this->assertSame('first', $history[1]['command']);
    }

    public function test_push_history_caps_at_history_item_cap(): void
    {
        for ($i = 0; $i < TerminalSessionService::HISTORY_ITEM_CAP + 5; $i++) {
            $this->service->pushHistory('beta', "cmd-{$i}", 0);
        }

        $history = $this->service->history('beta');

        $this->assertCount(TerminalSessionService::HISTORY_ITEM_CAP, $history);
    }

    public function test_clear_history_removes_per_project_history(): void
    {
        $this->service->pushHistory('gamma', 'one', 0);
        $this->service->pushHistory('gamma', 'two', 0);

        $this->service->clearHistory('gamma');

        $this->assertSame([], $this->service->history('gamma'));
    }
}
