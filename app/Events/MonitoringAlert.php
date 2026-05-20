<?php

namespace App\Events;

/**
 * In-process alert event emitted when a monitoring threshold transitions
 * between levels (ok ↔ warning ↔ critical).
 *
 * v3.2 surfaces alerts visually only — the snapshot broadcast carries
 * `alerts: [...]` inline and the UI lights up cards/charts. v3.2.1 (or
 * later) can register a listener on this event to push WhatsApp/email
 * without touching ThresholdEvaluator.
 */
final class MonitoringAlert
{
    public function __construct(
        public readonly string $ruleId,
        public readonly string $level,            // 'warning' | 'critical' | 'ok'
        public readonly string $message,
        public readonly mixed $currentValue,
        public readonly int $ts,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'rule_id' => $this->ruleId,
            'level' => $this->level,
            'message' => $this->message,
            'current_value' => $this->currentValue,
            'ts' => $this->ts,
        ];
    }
}
