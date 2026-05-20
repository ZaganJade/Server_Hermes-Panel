<?php

namespace App\Services\Monitoring;

/**
 * Read-only DTO for one collector pass.
 *
 * `entries` maps reader key → reader output array. `errors` maps
 * reader key → exception message for readers that failed this tick.
 * Both are surfaced to the broadcast payload so the UI can show `?`
 * placeholders without faking missing data.
 */
final class Snapshot
{
    public function __construct(
        public readonly int $ts,
        public readonly array $entries,
        public readonly array $errors = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            ts: (int) ($data['ts'] ?? 0),
            entries: (array) ($data['entries'] ?? []),
            errors: (array) ($data['errors'] ?? []),
        );
    }

    public function toArray(): array
    {
        return [
            'ts' => $this->ts,
            'entries' => $this->entries,
            'errors' => $this->errors,
        ];
    }

    public function get(string $key): ?array
    {
        $value = $this->entries[$key] ?? null;

        return is_array($value) ? $value : null;
    }
}
