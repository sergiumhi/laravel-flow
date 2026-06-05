<?php

namespace Sergiumhi\LaravelFlow\Tests\Feature;

use Sergiumhi\LaravelFlow\Flow;
use Sergiumhi\LaravelFlow\FlowStatus;
use Sergiumhi\LaravelFlow\FlowTaskStatus;
use Sergiumhi\LaravelFlow\Task;
use Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Sergiumhi\LaravelFlow\Tests\TestCase;

/**
 * The payload passed to start() is handed straight to run() as its argument, so a
 * flow can branch on it directly without reaching through $this->record. The flow
 * below reads ONLY $payload — never the record — which is what discriminates this
 * behaviour from the payload merely being merged into each task's payload.
 */
class PayloadArgumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_receives_the_start_payload_and_branches_on_it(): void
    {
        $flow = PayloadArgFlow::start(['go' => true]);

        $record = $flow->record()->fresh();
        $this->assertSame(FlowStatus::Completed, $record->status);

        $taken = $record->topLevelTasks()->where('task_class', PayloadYesTask::class)->first();
        $this->assertNotNull($taken);
        $this->assertSame(FlowTaskStatus::Completed, $taken->status);

        // The other branch was never promoted to an execution row.
        $this->assertNull($record->topLevelTasks()->where('task_class', PayloadNoTask::class)->first());
    }

    public function test_run_takes_the_other_branch_for_the_opposite_payload(): void
    {
        $flow = PayloadArgFlow::start(['go' => false]);

        $record = $flow->record()->fresh();
        $this->assertSame(FlowStatus::Completed, $record->status);

        $taken = $record->topLevelTasks()->where('task_class', PayloadNoTask::class)->first();
        $this->assertNotNull($taken);
        $this->assertSame(FlowTaskStatus::Completed, $taken->status);

        $this->assertNull($record->topLevelTasks()->where('task_class', PayloadYesTask::class)->first());
    }
}

class PayloadArgFlow extends Flow
{
    public function run(array $payload): Generator
    {
        // Branch purely on the argument — never touch $this->record.
        if ($payload['go'] ?? false) {
            yield PayloadYesTask::init();
        } else {
            yield PayloadNoTask::init();
        }
    }
}

class PayloadYesTask extends Task
{
    public function handle(): array
    {
        return ['branch' => 'yes'];
    }
}

class PayloadNoTask extends Task
{
    public function handle(): array
    {
        return ['branch' => 'no'];
    }
}
