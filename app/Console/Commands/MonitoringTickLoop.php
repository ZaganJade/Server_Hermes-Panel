<?php

namespace App\Console\Commands;

use App\Events\MonitoringSnapshot;
use App\Services\Monitoring\MetricCollector;
use App\Services\Monitoring\MetricStorage;
use App\Services\Monitoring\ThresholdEvaluator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Long-running loop that drives the v3.2 monitoring pipeline:
 *
 *   sample → evaluate thresholds → record sample → broadcast event
 *
 * Started by supervisord (`docker/supervisord.conf`):
 *   php artisan hermes:monitoring-tick
 *
 * Cadence: 5 s by default (overridable via --sleep). Drift correction
 * keeps successive ticks on stable boundaries even when collector or
 * storage take measurable time. Every 12th iteration the loop also:
 *   - aggregates the previous minute into samples_1m
 *   - prunes samples_raw + samples_1m past their retention windows
 *
 * SIGTERM trap exits cleanly mid-iteration (pcntl). On Windows hosts
 * without pcntl the trap is silently skipped — supervisord on Linux
 * always provides it.
 */
class MonitoringTickLoop extends Command
{
    /** @var string */
    protected $signature = 'hermes:monitoring-tick
        {--max-iterations=0 : Stop after N iterations (0 = run forever; useful for tests)}
        {--sleep=5 : Seconds between samples (default 5; tests override)}';

    /** @var string */
    protected $description = 'Long-running loop that samples host metrics and broadcasts via Reverb.';

    /** Aggregate + prune cadence: every 12th iteration at default 5 s = 60 s */
    public const PRUNE_EVERY_N_ITERATIONS = 12;

    public const AGGREGATE_EVERY_N_ITERATIONS = 12;

    protected bool $shuttingDown = false;

    protected int $iteration = 0;

    public function __construct(
        protected MetricCollector $collector,
        protected MetricStorage $storage,
        protected ThresholdEvaluator $evaluator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->registerSignalHandlers();
        $this->storage->bootstrap();

        $sleep = max(1, (int) $this->option('sleep'));
        $maxIterations = (int) $this->option('max-iterations');

        $this->logInfo('monitoring-tick loop started', [
            'sleep_s' => $sleep,
            'max_iter' => $maxIterations,
        ]);

        while (! $this->shuttingDown) {
            $tickStart = microtime(true);

            try {
                $this->tick();
            } catch (\Throwable $e) {
                $this->logError('tick iteration failed', [
                    'message' => $e->getMessage(),
                    'iteration' => $this->iteration,
                ]);
            }

            $this->iteration++;
            if ($maxIterations > 0 && $this->iteration >= $maxIterations) {
                break;
            }

            // Drift correction: aim for stable cadence even if work took time.
            $elapsed = microtime(true) - $tickStart;
            $remaining = max(0.0, $sleep - $elapsed);
            usleep((int) ($remaining * 1_000_000));
        }

        $this->logInfo('monitoring-tick loop exited cleanly', [
            'iterations' => $this->iteration,
        ]);

        return self::SUCCESS;
    }

    /**
     * One iteration: sample → evaluate → record → broadcast → maintenance.
     */
    protected function tick(): void
    {
        $snapshot = $this->collector->sample();
        $alerts = $this->evaluator->evaluate($snapshot);

        try {
            $this->storage->recordSample($snapshot);
        } catch (\Throwable $e) {
            // Storage failure is non-fatal — UI still gets the live broadcast.
            $this->logError('storage write failed', ['message' => $e->getMessage()]);
        }

        // Maintenance: aggregate + prune piggybacks on the tick boundary
        // instead of running a separate scheduler.
        if ($this->iteration > 0 && $this->iteration % self::AGGREGATE_EVERY_N_ITERATIONS === 0) {
            $minuteBoundary = (intdiv($snapshot->ts, 60)) * 60;
            try {
                $this->storage->aggregateMinute($minuteBoundary);
            } catch (\Throwable $e) {
                $this->logError('aggregate failed', ['message' => $e->getMessage()]);
            }
        }

        if ($this->iteration > 0 && $this->iteration % self::PRUNE_EVERY_N_ITERATIONS === 0) {
            try {
                $this->storage->prune($snapshot->ts);
            } catch (\Throwable $e) {
                $this->logError('prune failed', ['message' => $e->getMessage()]);
            }
        }

        // Broadcast last so storage failures don't suppress the live UI.
        $payload = [
            'ts' => $snapshot->ts,
            'entries' => $snapshot->entries,
            'alerts' => $alerts,
            'errors' => $snapshot->errors,
        ];
        event(new MonitoringSnapshot($payload));
    }

    protected function registerSignalHandlers(): void
    {
        if (! function_exists('pcntl_async_signals')) {
            $this->logInfo('pcntl not available; SIGTERM trap disabled');

            return;
        }

        pcntl_async_signals(true);

        $handler = function (int $signal): void {
            $this->logInfo('signal received, finishing iteration', ['signal' => $signal]);
            $this->shuttingDown = true;
        };

        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
    }

    protected function logInfo(string $message, array $context = []): void
    {
        Log::channel('monitoring-tick')->info($message, $context);
    }

    protected function logError(string $message, array $context = []): void
    {
        Log::channel('monitoring-tick')->error($message, $context);
    }
}
