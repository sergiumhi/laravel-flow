<?php

namespace Sergiumhi\LaravelFlow\Tests\Feature;

use Sergiumhi\LaravelFlow\FlowStatus;
use Sergiumhi\LaravelFlow\FlowTaskStatus;
use Sergiumhi\LaravelFlow\SubTaskCollection;
use Workbench\App\Flows\ReportFlow;
use Workbench\App\Tasks\BuildSectionsTask;
use Workbench\App\Tasks\CompileReportTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Sergiumhi\LaravelFlow\Tests\TestCase;

class SequentialSubtaskTest extends TestCase
{
    use RefreshDatabase;

    public function test_children_run_in_order_and_aggregate(): void
    {
        $flow = ReportFlow::start(['sections' => ['intro', 'body', 'summary']]);

        $record = $flow->record()->fresh();
        $this->assertSame(FlowStatus::Completed, $record->status);

        $parent = $record->topLevelTasks()
            ->where('task_class', BuildSectionsTask::class)
            ->first();

        $this->assertSame(SubTaskCollection::MODE_SEQUENTIAL, $parent->subtask_mode);

        $children = $parent->children()->get();
        $this->assertCount(3, $children);

        // Children are indexed in order and each completed.
        $this->assertSame(['intro', 'body', 'summary'], $children->pluck('payload.section')->all());
        foreach ($children as $child) {
            $this->assertSame(FlowTaskStatus::Completed, $child->status);
        }

        // Each child was dispatched only after the previous finished.
        $this->assertTrue(
            $children[0]->job_finished_at <= $children[1]->job_dispatched_at,
            'second child should be dispatched after the first finished',
        );
        $this->assertTrue(
            $children[1]->job_finished_at <= $children[2]->job_dispatched_at,
            'third child should be dispatched after the second finished',
        );

        $report = $record->topLevelTasks()
            ->where('task_class', CompileReportTask::class)
            ->first();
        $this->assertSame(['intro', 'body', 'summary'], $report->output['sections']);
    }
}
