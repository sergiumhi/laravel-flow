<?php

namespace Sergiumhi\LaravelFlow\Tests\Feature;

use Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Sergiumhi\LaravelFlow\Flow;
use Sergiumhi\LaravelFlow\FlowOrchestrator;
use Sergiumhi\LaravelFlow\FlowStatus;
use Sergiumhi\LaravelFlow\FlowTaskStatus;
use Sergiumhi\LaravelFlow\Models\FlowModel;
use Sergiumhi\LaravelFlow\Models\FlowTask;
use Sergiumhi\LaravelFlow\SubTaskCollection;
use Sergiumhi\LaravelFlow\Task;
use Sergiumhi\LaravelFlow\Tests\TestCase;
use Workbench\App\Flows\CsvImportFlow;
use Workbench\App\Flows\MultiChannelNotificationFlow;
use Workbench\App\Flows\ReportFlow;
use Workbench\App\Tasks\BuildSectionsTask;
use Workbench\App\Tasks\FanOutChannelsTask;
use Workbench\App\Tasks\ProcessChunkTask;
use Workbench\App\Tasks\ProcessCsvTask;
use Workbench\App\Tasks\SendEmailTask;
use Workbench\App\Tasks\SendPushTask;
use Workbench\App\Tasks\SendSmsTask;

/**
 * Covers the subtask-preview half of FlowOrchestrator::init(): reflecting into a
 * seeded task's handle() to seed one preview child per declared subtask. init()
 * is called in isolation so the assertions describe the raw seeded shape.
 */
class SubtaskSeedingTest extends TestCase
{
    use RefreshDatabase;

    public function test_array_map_parallel_fan_out_seeds_one_preview_child_per_class(): void
    {
        $parent = $this->seedAndFindParent(CsvImportFlow::class, ProcessCsvTask::class);

        $this->assertSame(SubTaskCollection::MODE_PARALLEL, $parent->subtask_mode);

        $children = $parent->children()->get();
        $this->assertCount(1, $children);
        $this->assertSame(ProcessChunkTask::class, $children[0]->task_class);
        $this->assertNull($children[0]->index, 'preview children must stay index=null');
        $this->assertSame(FlowTaskStatus::Pending, $children[0]->status);
    }

    public function test_array_map_sequential_fan_out_records_the_mode(): void
    {
        $parent = $this->seedAndFindParent(ReportFlow::class, BuildSectionsTask::class);

        $this->assertSame(SubTaskCollection::MODE_SEQUENTIAL, $parent->subtask_mode);
        $this->assertSame(1, $parent->children()->count());
    }

    public function test_literal_list_of_distinct_classes_seeds_one_child_each(): void
    {
        $parent = $this->seedAndFindParent(MultiChannelNotificationFlow::class, FanOutChannelsTask::class);

        $classes = $parent->children()->get()->pluck('task_class')->all();
        $this->assertSame([SendEmailTask::class, SendSmsTask::class, SendPushTask::class], $classes);
    }

    public function test_literal_list_preserves_repeated_class_count(): void
    {
        $parent = $this->seedAndFindParent(RepeatedSectionFlow::class, SeedRepeatParentTask::class);

        // Both literal entries are the same class — the known count is preserved.
        $classes = $parent->children()->get()->pluck('task_class')->all();
        $this->assertSame([SeedSectionTask::class, SeedSectionTask::class], $classes);
    }

    public function test_task_returning_a_plain_array_seeds_no_children_and_no_mode(): void
    {
        $parent = $this->seedAndFindParent(PlainTaskFlow::class, SeedPlainTask::class);

        $this->assertNull($parent->subtask_mode);
        $this->assertSame(0, $parent->children()->count());
    }

    public function test_dynamically_built_collection_seeds_no_children(): void
    {
        $parent = $this->seedAndFindParent(DynamicFanOutFlow::class, SeedDynamicParentTask::class);

        // The ::init() calls are not inside the collection call, so no class is
        // statically resolvable — the mode is still shown, but no preview rows.
        $this->assertSame(SubTaskCollection::MODE_PARALLEL, $parent->subtask_mode);
        $this->assertSame(0, $parent->children()->count());
    }

    public function test_real_fan_out_replaces_preview_children_and_completes(): void
    {
        // Hazard A: preview children must be discarded when the parent actually
        // fans out, otherwise their Pending status blocks the parent completing.
        $flow = CsvImportFlow::start(['rows' => range(1, 6), 'chunk_size' => 2]);
        $record = $flow->record()->fresh();

        $this->assertSame(FlowStatus::Completed, $record->status);

        $parent = $record->topLevelTasks()->where('task_class', ProcessCsvTask::class)->firstOrFail();
        $children = $parent->children()->get();

        // 6 rows / chunk 2 => 3 real children, all with an index; no leftover preview.
        $this->assertCount(3, $children);
        $children->each(fn (FlowTask $c) => $this->assertNotNull($c->index));
        $this->assertSame(FlowTaskStatus::Completed, $parent->status);
    }

    public function test_preview_children_on_an_untaken_branch_are_abandoned(): void
    {
        // Hazard B: a fan-out parent on a branch never reached, plus its preview
        // children, must end Abandoned rather than linger Pending.
        $flow = UntakenFanOutFlow::start(['fan' => false]);
        $record = $flow->record()->fresh();

        $this->assertSame(FlowStatus::Completed, $record->status);

        $parent = $record->tasks()->where('task_class', SeedRepeatParentTask::class)->firstOrFail();
        $this->assertSame(FlowTaskStatus::Abandoned, $parent->status);

        $children = $parent->children()->get();
        $this->assertCount(2, $children);
        $children->each(fn (FlowTask $c) => $this->assertSame(FlowTaskStatus::Abandoned, $c->status));
    }

    private function seedAndFindParent(string $flowClass, string $parentTaskClass): FlowTask
    {
        $model = FlowModel::query()->create([
            'flow_class' => $flowClass,
            'status' => FlowStatus::Pending,
            'payload' => [],
        ]);

        app(FlowOrchestrator::class)->init($model);

        return $model->tasks()
            ->whereNull('parent_id')
            ->where('task_class', $parentTaskClass)
            ->firstOrFail();
    }
}

class UntakenFanOutFlow extends Flow
{
    public function run(array $payload): Generator
    {
        if ($payload['fan'] ?? false) {
            yield SeedRepeatParentTask::init();
        } else {
            yield SeedPlainTask::init();
        }
    }
}

class RepeatedSectionFlow extends Flow
{
    public function run(array $payload): Generator
    {
        yield SeedRepeatParentTask::init();
    }
}

class SeedRepeatParentTask extends Task
{
    public function handle(): SubTaskCollection
    {
        return SubTaskCollection::sequential([
            SeedSectionTask::init(['section' => 'intro']),
            SeedSectionTask::init(['section' => 'body']),
        ]);
    }
}

class SeedSectionTask extends Task
{
    public function handle(): array
    {
        return [];
    }
}

class PlainTaskFlow extends Flow
{
    public function run(array $payload): Generator
    {
        yield SeedPlainTask::init();
    }
}

class SeedPlainTask extends Task
{
    public function handle(): array
    {
        return ['done' => true];
    }
}

class DynamicFanOutFlow extends Flow
{
    public function run(array $payload): Generator
    {
        yield SeedDynamicParentTask::init();
    }
}

class SeedDynamicParentTask extends Task
{
    public function handle(): SubTaskCollection
    {
        $tasks = [];

        foreach (range(1, 3) as $i) {
            $tasks[] = SeedSectionTask::init(['i' => $i]);
        }

        return SubTaskCollection::parallel($tasks);
    }
}
