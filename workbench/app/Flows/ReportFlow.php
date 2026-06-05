<?php

namespace Workbench\App\Flows;

use Sergiumhi\LaravelFlow\Flow;
use Workbench\App\Tasks\BuildSectionsTask;
use Workbench\App\Tasks\CompileReportTask;
use Generator;

/**
 * Demonstrates sequential fan-out: BuildSectionsTask spawns one subtask per
 * section, dispatched one at a time in order. Once all sections are built, the
 * aggregated section outputs are compiled into the final report.
 *
 *     ReportFlow::start(['sections' => ['intro', 'body', 'summary']]);
 */
class ReportFlow extends Flow
{
    public function run(array $payload): Generator
    {
        $sections = yield BuildSectionsTask::init($payload);

        yield CompileReportTask::init(['sections' => $sections]);
    }
}
