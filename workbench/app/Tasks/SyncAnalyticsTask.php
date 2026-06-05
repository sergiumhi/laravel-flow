<?php

namespace Workbench\App\Tasks;

use Sergiumhi\LaravelFlow\Task;
use RuntimeException;

/**
 * Non-critical analytics sync. Fails on demand (payload `fail_analytics`) to
 * demonstrate OnFailure::SKIP — the flow skips this step and continues.
 */
class SyncAnalyticsTask extends Task
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        if ($this->payload['fail_analytics'] ?? false) {
            throw new RuntimeException('Analytics endpoint timed out.');
        }

        return ['synced' => true];
    }
}
