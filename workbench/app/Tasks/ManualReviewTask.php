<?php

namespace Workbench\App\Tasks;

use Sergiumhi\LaravelFlow\Task;

/**
 * Records the outcome of a manual review. Runs only when the OrderFlow branch is
 * taken, after the `manual_approval` signal arrives.
 */
class ManualReviewTask extends Task
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        return ['reviewed_by' => $this->payload['approved_by'] ?? null];
    }
}
