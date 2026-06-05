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
 * Static analysis seeds BOTH sides of a conditional as preview rows. Runtime
 * advancement promotes the branch actually taken (assigning it a real index) and,
 * once the flow completes, marks the untaken branch `abandoned` — it stays visible
 * in the DAG as the path not followed.
 */
class ReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_promotes_the_taken_branch_and_abandons_the_other(): void
    {
        $flow = BranchFlow::start(['go_left' => true]);

        $record = $flow->record()->fresh();
        $this->assertSame(FlowStatus::Completed, $record->status);

        // The left branch ran and was promoted to an execution row.
        $left = $record->topLevelTasks()->where('task_class', LeftTask::class)->first();
        $this->assertNotNull($left);
        $this->assertSame(FlowTaskStatus::Completed, $left->status);

        // The untaken right branch is not an execution row...
        $right = $record->topLevelTasks()->where('task_class', RightTask::class)->first();
        $this->assertNull($right, 'the untaken branch should not be a promoted execution row');

        // ...but it is still present in the full DAG, marked abandoned.
        $abandonedRight = $record->allTopLevelTasks()->where('task_class', RightTask::class)->first();
        $this->assertNotNull($abandonedRight);
        $this->assertSame(FlowTaskStatus::Abandoned, $abandonedRight->status);

        // Final task still runs, at its real index after the chosen branch.
        $final = $record->topLevelTasks()->where('task_class', FinalTask::class)->first();
        $this->assertSame(FlowTaskStatus::Completed, $final->status);

        // The executed top-level shape matches the real path taken.
        $this->assertSame([
            DecideTask::class,
            LeftTask::class,
            FinalTask::class,
        ], $record->topLevelTasks()->pluck('task_class')->all());
    }

    public function test_other_branch_is_abandoned_when_the_else_path_is_taken(): void
    {
        $flow = BranchFlow::start(['go_left' => false]);

        $record = $flow->record()->fresh();
        $this->assertSame(FlowStatus::Completed, $record->status);

        $this->assertSame([
            DecideTask::class,
            RightTask::class,
            FinalTask::class,
        ], $record->topLevelTasks()->pluck('task_class')->all());

        // The untaken left branch is abandoned, still visible in the DAG.
        $abandonedLeft = $record->allTopLevelTasks()->where('task_class', LeftTask::class)->first();
        $this->assertNotNull($abandonedLeft);
        $this->assertSame(FlowTaskStatus::Abandoned, $abandonedLeft->status);
    }
}

class BranchFlow extends Flow
{
    public function run(array $payload): Generator
    {
        $decision = yield DecideTask::init($payload);

        // Both LeftTask and RightTask are seeded by static analysis; only the
        // branch matching the real DecideTask output is promoted at runtime.
        if ($decision['go_left'] ?? false) {
            yield LeftTask::init();
        } else {
            yield RightTask::init();
        }

        yield FinalTask::init();
    }
}

class DecideTask extends Task
{
    public function handle(): array
    {
        return ['go_left' => (bool) ($this->payload['go_left'] ?? false)];
    }
}

class LeftTask extends Task
{
    public function handle(): array
    {
        return ['branch' => 'left'];
    }
}

class RightTask extends Task
{
    public function handle(): array
    {
        return ['branch' => 'right'];
    }
}

class FinalTask extends Task
{
    public function handle(): array
    {
        return ['done' => true];
    }
}
