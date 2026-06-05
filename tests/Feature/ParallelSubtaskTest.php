<?php

namespace Sergiumhi\LaravelFlow\Tests\Feature;

use Sergiumhi\LaravelFlow\FlowStatus;
use Sergiumhi\LaravelFlow\FlowTaskStatus;
use Sergiumhi\LaravelFlow\SubTaskCollection;
use Workbench\App\Flows\CsvImportFlow;
use Workbench\App\Tasks\GenerateReportTask;
use Workbench\App\Tasks\ProcessCsvTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Sergiumhi\LaravelFlow\Tests\TestCase;

class ParallelSubtaskTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_waits_for_all_children_then_aggregates_and_advances(): void
    {
        $flow = CsvImportFlow::start(['rows' => range(1, 10), 'chunk_size' => 3]);

        $record = $flow->record()->fresh();
        $this->assertSame(FlowStatus::Completed, $record->status);

        $parent = $record->topLevelTasks()
            ->where('task_class', ProcessCsvTask::class)
            ->first();

        $this->assertSame(SubTaskCollection::MODE_PARALLEL, $parent->subtask_mode);
        $this->assertSame(FlowTaskStatus::Completed, $parent->status);

        // 10 rows / chunk size 3 => 4 chunks (3, 3, 3, 1).
        $children = $parent->children()->get();
        $this->assertCount(4, $children);
        foreach ($children as $child) {
            $this->assertSame(FlowTaskStatus::Completed, $child->status);
        }

        // Parent output is the aggregated list of subtask outputs.
        $this->assertSame([
            ['rows_processed' => 3],
            ['rows_processed' => 3],
            ['rows_processed' => 3],
            ['rows_processed' => 1],
        ], $parent->output);

        // Aggregated output flowed into the next task.
        $report = $record->topLevelTasks()
            ->where('task_class', GenerateReportTask::class)
            ->first();
        $this->assertSame(['total_rows' => 10, 'chunks' => 4], $report->output);
    }
}
