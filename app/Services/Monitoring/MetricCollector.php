<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Facades\Log;

/**
 * Orchestrates a single sample pass: invoke every registered reader,
 * tolerate per-reader exceptions, return a Snapshot DTO.
 */
final class MetricCollector
{
    /** @var iterable<Reader> */
    protected iterable $readers;

    /**
     * @param  iterable<Reader>  $readers  Tagged via the `monitoring.readers` container tag.
     */
    public function __construct(iterable $readers, protected ProcResolver $procResolver)
    {
        $this->readers = $readers;
    }

    public function sample(): Snapshot
    {
        $entries = [];
        $errors = [];

        foreach ($this->readers as $reader) {
            try {
                $entries[$reader->key()] = $reader->read($this->procResolver);
            } catch (\Throwable $e) {
                $errors[$reader->key()] = $e->getMessage();
                $this->logFailure($reader->key(), $e);
            }
        }

        return new Snapshot(
            ts: time(),
            entries: $entries,
            errors: $errors,
        );
    }

    /**
     * Log to the dedicated monitoring-tick channel when it's been
     * registered (story v3.2-06). Falls back silently otherwise so
     * tests don't need the channel set up just to exercise readers.
     */
    protected function logFailure(string $key, \Throwable $e): void
    {
        $hasChannel = config()->has('logging.channels.monitoring-tick');
        if (! $hasChannel) {
            return;
        }

        try {
            Log::channel('monitoring-tick')->warning('reader_failed', [
                'key' => $key,
                'message' => $e->getMessage(),
            ]);
        } catch (\Throwable) {
            // Logging is best-effort; never let it break the sample loop.
        }
    }
}
