<?php

namespace Sergiumhi\LaravelFlow\Tests\Feature;

use Sergiumhi\LaravelFlow\FlowStatus;
use Sergiumhi\LaravelFlow\FlowTaskStatus;
use Workbench\App\Flows\GreetingFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Sergiumhi\LaravelFlow\Tests\TestCase;

class LinearFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_runs_tasks_in_order_and_chains_output(): void
    {
        $flow = GreetingFlow::start(['name' => 'Ada']);

        $record = $flow->record()->fresh();

        $this->assertSame(FlowStatus::Completed, $record->status);

        $tasks = $record->topLevelTasks()->get();
        $this->assertCount(3, $tasks);

        // Output chaining survived the rebuild-and-replay between steps.
        $this->assertSame(['greeting' => 'Hello, Ada'], $tasks[0]->output);
        $this->assertSame(['shout' => 'HELLO, ADA!'], $tasks[1]->output);
        $this->assertSame(['recorded' => 'HELLO, ADA!'], $tasks[2]->output);

        foreach ($tasks as $task) {
            $this->assertSame(FlowTaskStatus::Completed, $task->status);
        }
    }

    public function test_it_records_job_timing_for_each_task(): void
    {
        $flow = GreetingFlow::start(['name' => 'Grace']);

        foreach ($flow->record()->topLevelTasks()->get() as $task) {
            $this->assertNotNull($task->job_dispatched_at, 'job_dispatched_at should be set');
            $this->assertNotNull($task->job_started_at, 'job_started_at should be set');
            $this->assertNotNull($task->job_finished_at, 'job_finished_at should be set');
            $this->assertSame(1, $task->attempts);
        }
    }
}
