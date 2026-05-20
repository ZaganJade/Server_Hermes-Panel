<?php

namespace App\Services\Monitoring;

/**
 * Single source of truth for "where is /proc and /sys on this system?".
 *
 * In the container we mount the host's /proc and /sys read-only at
 * /host/proc and /host/sys (see docker-compose.yml). On a host run or
 * during local development the resolver picks /proc and /sys directly.
 *
 * Every reader takes a ProcResolver in its read() signature so tests
 * can swap fixture directories in without touching reader code.
 */
final class ProcResolver
{
    public function __construct(
        protected string $procRoot,
        protected string $sysRoot,
    ) {}

    /**
     * Pick the right /proc and /sys roots based on what's mounted.
     *
     * /host/proc is what the container sees when docker-compose maps the
     * host filesystem. /proc is what we get on the host or in a dev run
     * outside the container.
     */
    public static function autodetect(): self
    {
        $proc = is_dir('/host/proc') ? '/host/proc' : '/proc';
        $sys = is_dir('/host/sys') ? '/host/sys' : '/sys';

        return new self($proc, $sys);
    }

    public function procRoot(): string
    {
        return $this->procRoot;
    }

    public function sysRoot(): string
    {
        return $this->sysRoot;
    }

    /**
     * Join the resolved /proc root with a relative path.
     */
    public function proc(string $relativePath): string
    {
        return rtrim($this->procRoot, '/').'/'.ltrim($relativePath, '/');
    }

    /**
     * Join the resolved /sys root with a relative path.
     */
    public function sys(string $relativePath): string
    {
        return rtrim($this->sysRoot, '/').'/'.ltrim($relativePath, '/');
    }

    /**
     * Read a /proc file by relative path. Throws with a clear message
     * when the path is missing — readers are expected to either let
     * that propagate (so MetricCollector captures it as a per-reader
     * error) or catch and downgrade to a soft failure when appropriate.
     */
    public function readFile(string $relativePath): string
    {
        $full = $this->proc($relativePath);
        $content = @file_get_contents($full);

        if ($content === false) {
            throw new \RuntimeException("ProcResolver: failed to read {$full}");
        }

        return $content;
    }
}
