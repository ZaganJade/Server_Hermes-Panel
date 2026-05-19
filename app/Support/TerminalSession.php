<?php

namespace App\Support;

/**
 * Read-only DTO describing the state of a terminal session.
 *
 * Lives in `hermes:term:active:{sessionId}` cache key. Mirrors the field
 * shape documented in the v3.1 design doc (Section 5).
 */
final class TerminalSession
{
    public function __construct(
        public readonly string $sessionId,
        public readonly int $pid,
        public readonly string $project,
        public readonly string $command,
        public readonly string $cwd,
        public readonly int $startedAt,
        public readonly int $lastChunkAt,
        public readonly string $status,    // 'running' | 'exiting' | 'done'
        public readonly ?int $exitCode = null,
    ) {}

    /**
     * Hydrate from an associative array (cache payload).
     */
    public static function fromArray(array $data): self
    {
        return new self(
            sessionId: (string) ($data['session_id'] ?? ''),
            pid: (int) ($data['pid'] ?? 0),
            project: (string) ($data['project'] ?? ''),
            command: (string) ($data['command'] ?? ''),
            cwd: (string) ($data['cwd'] ?? ''),
            startedAt: (int) ($data['started_at'] ?? 0),
            lastChunkAt: (int) ($data['last_chunk_at'] ?? 0),
            status: (string) ($data['status'] ?? 'running'),
            exitCode: isset($data['exit_code']) ? (int) $data['exit_code'] : null,
        );
    }

    /**
     * Serialize to the cache payload shape.
     */
    public function toArray(): array
    {
        return [
            'session_id' => $this->sessionId,
            'pid' => $this->pid,
            'project' => $this->project,
            'command' => $this->command,
            'cwd' => $this->cwd,
            'started_at' => $this->startedAt,
            'last_chunk_at' => $this->lastChunkAt,
            'status' => $this->status,
            'exit_code' => $this->exitCode,
        ];
    }

    public function withStatus(string $status, ?int $exitCode = null): self
    {
        return new self(
            sessionId: $this->sessionId,
            pid: $this->pid,
            project: $this->project,
            command: $this->command,
            cwd: $this->cwd,
            startedAt: $this->startedAt,
            lastChunkAt: $this->lastChunkAt,
            status: $status,
            exitCode: $exitCode ?? $this->exitCode,
        );
    }

    public function withLastChunkAt(int $ts): self
    {
        return new self(
            sessionId: $this->sessionId,
            pid: $this->pid,
            project: $this->project,
            command: $this->command,
            cwd: $this->cwd,
            startedAt: $this->startedAt,
            lastChunkAt: $ts,
            status: $this->status,
            exitCode: $this->exitCode,
        );
    }
}
