<?php

namespace Workbench\App\Tasks;

use Sergiumhi\LaravelFlow\SubTaskCollection;
use Sergiumhi\LaravelFlow\Task;

/**
 * Splits a row set into chunks and fans them out as parallel subtasks. The parent
 * is only marked completed once every chunk subtask completes; its output is the
 * aggregated list of subtask outputs.
 */
class ProcessCsvTask extends Task
{
    public function handle(): SubTaskCollection
    {
        $rows = $this->payload['rows'] ?? [];
        $chunkSize = (int) ($this->payload['chunk_size'] ?? 2);

        $chunks = array_chunk($rows, max(1, $chunkSize));

        return SubTaskCollection::parallel(
            array_map(
                fn (array $chunk) => ProcessChunkTask::init(['chunk' => $chunk]),
                $chunks,
            ),
        );
    }
}
