<?php

namespace Sergiumhi\LaravelFlow\Tests\Feature;

use Sergiumhi\LaravelFlow\Flow;
use Sergiumhi\LaravelFlow\FlowOrchestrator;
use Sergiumhi\LaravelFlow\FlowStatus;
use Sergiumhi\LaravelFlow\Models\FlowModel;
use Sergiumhi\LaravelFlow\Models\FlowTask;
use Sergiumhi\LaravelFlow\Signal;
use Sergiumhi\LaravelFlow\Task;
use Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Sergiumhi\LaravelFlow\Tests\TestCase;

/**
 * Covers FlowOrchestrator::init() — the php-parser based static analysis that
 * pre-seeds a flow's yields as preview rows. init() is called in isolation (no
 * orchestrator dispatch) so the assertions describe the raw seeded shape.
 */
class InitSeedingTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_both_branches_and_literal_signals_in_source_order(): void
    {
        $model = $this->makeRecord(SeedShapeFlow::class);

        app(FlowOrchestrator::class)->init($model);

        $shape = $model->allTopLevelTasks()->get()
            ->map(fn (FlowTask $row): string => $row->task_class ?? 'signal:'.$row->signal_name)
            ->all();

        // Both sides of the conditional are seeded, in source order.
        $this->assertSame([
            SeedFirstTask::class,
            'signal:approve',
            SeedLeftTask::class,
            SeedRightTask::class,
            SeedLastTask::class,
        ], $shape);
    }

    public function test_it_does_not_seed_a_signal_with_a_dynamic_name(): void
    {
        $model = $this->makeRecord(DynamicSignalFlow::class);

        app(FlowOrchestrator::class)->init($model);

        // The dynamic waitFor('ack_'.$channel) cannot be statically resolved, so
        // no signal row is seeded — only the literal task yield is.
        $this->assertSame([], $model->allTopLevelTasks()->whereNull('task_class')->pluck('signal_name')->all());
        $this->assertSame(
            [SeedFirstTask::class],
            $model->allTopLevelTasks()->whereNotNull('task_class')->pluck('task_class')->all(),
        );
    }

    public function test_it_records_branch_point_for_a_task_output_driven_branch(): void
    {
        $model = $this->makeRecord(TaskBranchFlow::class);

        app(FlowOrchestrator::class)->init($model);

        $rows = $model->allTopLevelTasks()->orderBy('source_order')->get()
            ->keyBy(fn (FlowTask $row): string => $row->task_class);

        // The task before the if is on the main spine.
        $this->assertNull($rows[SeedFirstTask::class]->branch_point_source_order);

        // Both arms fork from that task's source_order, regardless of which
        // branch is taken at runtime (here keyed on a task output).
        $first = $rows[SeedFirstTask::class]->source_order;
        $this->assertSame($first, $rows[SeedLeftTask::class]->branch_point_source_order);
        $this->assertSame($first, $rows[SeedRightTask::class]->branch_point_source_order);

        // The task after the if rejoins the spine.
        $this->assertNull($rows[SeedLastTask::class]->branch_point_source_order);
    }

    public function test_it_records_branch_point_for_a_signal_driven_branch(): void
    {
        $model = $this->makeRecord(SeedShapeFlow::class);

        app(FlowOrchestrator::class)->init($model);

        $rows = $model->allTopLevelTasks()->orderBy('source_order')->get();

        $signal = $rows->firstWhere('signal_name', 'approve');
        $left = $rows->firstWhere('task_class', SeedLeftTask::class);
        $right = $rows->firstWhere('task_class', SeedRightTask::class);

        // The if keys on the signal payload, so both arms fork from the signal.
        $this->assertSame($signal->source_order, $left->branch_point_source_order);
        $this->assertSame($signal->source_order, $right->branch_point_source_order);

        // The signal itself, and the trailing task, are on the spine.
        $this->assertNull($signal->branch_point_source_order);
        $this->assertNull($rows->firstWhere('task_class', SeedLastTask::class)->branch_point_source_order);
    }

    public function test_it_uses_the_innermost_enclosing_if_for_nested_branches(): void
    {
        $model = $this->makeRecord(NestedBranchFlow::class);

        app(FlowOrchestrator::class)->init($model);

        $rows = $model->allTopLevelTasks()->orderBy('source_order')->get()
            ->keyBy(fn (FlowTask $row): string => $row->task_class);

        $first = $rows[SeedFirstTask::class]->source_order;
        $left = $rows[SeedLeftTask::class]->source_order;

        // The outer-arm task forks from the task before the outer if.
        $this->assertSame($first, $rows[SeedLeftTask::class]->branch_point_source_order);

        // The inner-arm task forks from the last spine yield before the inner
        // if (SeedLeftTask), not from the outer fork point.
        $this->assertSame($left, $rows[SeedRightTask::class]->branch_point_source_order);
    }

    private function makeRecord(string $flowClass): FlowModel
    {
        return FlowModel::query()->create([
            'flow_class' => $flowClass,
            'status' => FlowStatus::Pending,
            'payload' => [],
        ]);
    }
}

class SeedShapeFlow extends Flow
{
    public function run(array $payload): Generator
    {
        yield SeedFirstTask::init();

        $approval = yield Signal::waitFor('approve');

        if ($approval['ok'] ?? false) {
            yield SeedLeftTask::init();
        } else {
            yield SeedRightTask::init();
        }

        yield SeedLastTask::init();
    }
}

class TaskBranchFlow extends Flow
{
    public function run(array $payload): Generator
    {
        $prev = yield SeedFirstTask::init();

        if (($prev['ok'] ?? false) === true) {
            yield SeedLeftTask::init();
        } else {
            yield SeedRightTask::init();
        }

        yield SeedLastTask::init();
    }
}

class NestedBranchFlow extends Flow
{
    public function run(array $payload): Generator
    {
        $prev = yield SeedFirstTask::init();

        if (($prev['ok'] ?? false) === true) {
            yield SeedLeftTask::init();

            if (($prev['deep'] ?? false) === true) {
                yield SeedRightTask::init();
            }
        }
    }
}

class DynamicSignalFlow extends Flow
{
    public function run(array $payload): Generator
    {
        yield SeedFirstTask::init();

        $channel = 'email';

        yield Signal::waitFor('ack_'.$channel);
    }
}

class SeedFirstTask extends Task
{
    public function handle(): array
    {
        return [];
    }
}

class SeedLeftTask extends Task
{
    public function handle(): array
    {
        return [];
    }
}

class SeedRightTask extends Task
{
    public function handle(): array
    {
        return [];
    }
}

class SeedLastTask extends Task
{
    public function handle(): array
    {
        return [];
    }
}
