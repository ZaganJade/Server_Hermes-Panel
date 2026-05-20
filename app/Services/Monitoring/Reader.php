<?php

namespace App\Services\Monitoring;

/**
 * Each metric source implements this. Readers are stateless from the
 * outside — anything they need to remember between calls (delta
 * counters, cached discoveries) lives in the cache layer keyed by
 * `hermes:monitoring:*`.
 *
 * MetricCollector iterates registered readers, calls read() on each,
 * and folds the results into a Snapshot. A reader that throws is
 * recorded in Snapshot::$errors and skipped — never fails the whole
 * snapshot.
 */
interface Reader
{
    /** Stable identifier used as the snapshot key + cache namespace. */
    public function key(): string;

    /**
     * Read the metric once.
     *
     * @return array Reader-specific shape; see Section 2 of the v3.2
     *               design doc for the exact shape per reader.
     */
    public function read(ProcResolver $proc): array;
}
