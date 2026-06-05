<?php

namespace Workbench\App\Tasks;

use Sergiumhi\LaravelFlow\Task;

/**
 * Final CsvImportFlow step: summarise how many rows were processed across all
 * chunk subtasks. Receives the parent task's aggregated subtask output via the
 * generator.
 */
class GenerateReportTask extends Task
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        $chunkOutputs = $this->payload['chunks'] ?? [];

        $total = array_sum(array_map(
            fn ($output) => $output['rows_processed'] ?? 0,
            $chunkOutputs,
        ));

        return ['total_rows' => $total, 'chunks' => count($chunkOutputs)];
    }
}
