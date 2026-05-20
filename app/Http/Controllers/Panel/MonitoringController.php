<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Services\Monitoring\MetricStorage;
use App\Services\Monitoring\Readers\ServiceReader;
use App\Services\Monitoring\ThresholdEvaluator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;

/**
 * HTTP surface for the v3.2 monitoring sub-project.
 *
 * The async path is the Reverb broadcast on `private-monitoring.host`.
 * These endpoints back the initial page load + the polling fallback for
 * trusted-network bypass mode + the historical chart series fetcher.
 */
class MonitoringController extends Controller
{
    public function __construct(protected MetricStorage $storage) {}

    /**
     * Render the dedicated Monitoring tab. View arrives in story 08.
     */
    public function index()
    {
        return view('panel.monitoring');
    }

    /**
     * Latest sample. Used as the poll fallback when WS is unavailable
     * (trusted-network bypass mode) and as the initial-state hydrator
     * for the Monitoring tab + Dashboard health strip.
     */
    public function snapshot(): JsonResponse
    {
        $payload = $this->storage->latestSnapshot();

        if (! $payload) {
            return response()->json([
                'ts' => null,
                'entries' => [],
                'alerts' => [],
                'errors' => [
                    'storage' => 'No samples yet — tick-loop may still be starting.',
                ],
            ]);
        }

        return response()->json($payload);
    }

    /**
     * Historical range query for charts. Window decides resolution:
     *   5m / 15m / 1h  → raw 5s samples
     *   6h / 24h        → 1m aggregates
     */
    public function series(Request $request): JsonResponse
    {
        $request->validate([
            'metrics' => 'required|array|min:1|max:20',
            'metrics.*' => 'string|max:128',
            'window' => 'in:5m,15m,1h,6h,24h',
        ]);

        $window = (string) $request->input('window', '15m');
        $now = now()->timestamp;
        $from = $now - $this->windowToSeconds($window);
        $useRaw = in_array($window, ['5m', '15m', '1h'], true);

        $series = [];
        foreach ((array) $request->input('metrics') as $metric) {
            $metric = (string) $metric;
            $series[$metric] = $useRaw
                ? $this->storage->rangeRaw($metric, $from, $now)
                : $this->storage->rangeMinute($metric, $from, $now);
        }

        return response()->json([
            'window' => $window,
            'resolution' => $useRaw ? 'raw' : '1m',
            'series' => $series,
        ]);
    }

    /**
     * Service list pulled straight from the latest snapshot blob.
     */
    public function services(): JsonResponse
    {
        $payload = $this->storage->latestSnapshot();

        return response()->json($payload['entries']['services'] ?? []);
    }

    /**
     * Top processes from the latest snapshot blob.
     */
    public function processes(): JsonResponse
    {
        $payload = $this->storage->latestSnapshot();

        return response()->json($payload['entries']['process'] ?? []);
    }

    /**
     * Listening ports from the latest snapshot blob.
     */
    public function ports(): JsonResponse
    {
        $payload = $this->storage->latestSnapshot();

        return response()->json($payload['entries']['ports'] ?? []);
    }

    /**
     * Active threshold violations.
     */
    public function alerts(): JsonResponse
    {
        return response()->json([
            'active' => app(ThresholdEvaluator::class)->activeAlerts(),
        ]);
    }

    /**
     * Force-clear the discovered service list cache so the next tick
     * re-discovers from systemctl/pgrep.
     */
    public function refreshServices(): JsonResponse
    {
        Cache::forget(ServiceReader::CACHE_KEY);

        return response()->json(['success' => true]);
    }

    protected function windowToSeconds(string $window): int
    {
        return match ($window) {
            '5m' => 300,
            '15m' => 900,
            '1h' => 3600,
            '6h' => 21600,
            '24h' => 86400,
            default => 900,
        };
    }
}
