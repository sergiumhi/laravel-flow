<?php

namespace Workbench\App\Flows;

use Sergiumhi\LaravelFlow\Flow;
use Workbench\App\Tasks\GenerateReportTask;
use Workbench\App\Tasks\ProcessCsvTask;
use Generator;

/**
 * Demonstrates parallel fan-out: ProcessCsvTask splits the rows into chunks and
 * spawns one subtask per chunk. The flow does not advance to GenerateReportTask
 * until every chunk subtask has completed; the aggregated chunk outputs are fed
 * into the report task.
 *
 *     CsvImportFlow::start(['rows' => range(1, 10), 'chunk_size' => 3]);
 */
class CsvImportFlow extends Flow
{
    public function run(array $payload): Generator
    {
        $chunks = yield ProcessCsvTask::init($payload);

        yield GenerateReportTask::init(['chunks' => $chunks]);
    }
}
