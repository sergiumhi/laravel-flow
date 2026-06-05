<?php

namespace Workbench\App\Tasks;

use Sergiumhi\LaravelFlow\Task;

/**
 * Processes a single chunk of CSV rows. Used as a subtask by ProcessCsvTask.
 */
class ProcessChunkTask extends Task
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        $chunk = $this->payload['chunk'] ?? [];

        return ['rows_processed' => count($chunk)];
    }
}
