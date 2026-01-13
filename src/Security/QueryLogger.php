<?php

namespace Eric0324\AIDBQuery\Security;

use Illuminate\Support\Facades\Log;

class QueryLogger
{
    protected bool $enabled;

    protected string $channel;

    public function __construct(bool $enabled = true, string $channel = 'smart-query')
    {
        $this->enabled = $enabled;
        $this->channel = $channel;
    }

    /**
     * Log a query execution.
     */
    public function logQuery(
        string $question,
        string $sql,
        string $driver,
        float $duration,
        ?int $rowCount = null,
        ?string $error = null
    ): void {
        if (! $this->enabled) {
            return;
        }

        $context = [
            'question' => $question,
            'sql' => $sql,
            'driver' => $driver,
            'duration_ms' => round($duration * 1000, 2),
            'row_count' => $rowCount,
            'timestamp' => now()->toIso8601String(),
        ];

        if ($error) {
            $context['error'] = $error;
            Log::channel($this->channel)->error('Smart Query failed', $context);
        } else {
            Log::channel($this->channel)->info('Smart Query executed', $context);
        }
    }

    /**
     * Log a security violation.
     */
    public function logSecurityViolation(
        string $question,
        string $sql,
        string $violation
    ): void {
        if (! $this->enabled) {
            return;
        }

        Log::channel($this->channel)->warning('Smart Query security violation', [
            'question' => $question,
            'sql' => $sql,
            'violation' => $violation,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Enable or disable logging.
     */
    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    /**
     * Check if logging is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
