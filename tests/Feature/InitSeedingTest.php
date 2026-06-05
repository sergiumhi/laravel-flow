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
