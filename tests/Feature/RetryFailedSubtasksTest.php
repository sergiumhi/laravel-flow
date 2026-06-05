<?php

namespace Sergiumhi\LaravelFlow\Tests\Feature;

use Sergiumhi\LaravelFlow\Flow;
use Sergiumhi\LaravelFlow\FlowStatus;
use Sergiumhi\LaravelFlow\FlowTaskStatus;
use Sergiumhi\LaravelFlow\SubTaskCollection;
use Sergiumhi\LaravelFlow\Task;
use Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Sergiumhi\LaravelFlow\Tests\TestCase;

/**
 * retryFailedSubtasks() re-runs only the failed children of a failed subtask
 * parent. Already-completed children are left in place, and the flow resumes
 * only once every child has finished.
 */
class RetryFailedSubtasksTest extends TestCase
{
    use RefreshDatabase;

    public function test_parallel_retry_reruns_only_the_failed_child(): void
    {
        Cache::flush();

        $flow = FanOutRetryFlow::start(['mode' => SubTaskCollection::MODE_PARALLEL]);
        $record = $flow->record()->fresh();

        // The middle chunk failed, pausing the flow with the parent marked failed.
        $this->assertSame(FlowStatus::Paused, $record->status);

        $parent = $record->topLevelTasks()->where('task_class', FanOutParentTask::class)->first();
        $this->assertSame(FlowTaskStatus::Failed, $parent->status);

        $children = $parent->children()->get()->keyBy('payload.id');
        $this->assertSame(FlowTaskStatus::Completed, $children['a']->status);
        $this->assertSame(FlowTaskStatus::Failed, $children['b']->status);
        $this->assertSame(FlowTaskStatus::Completed, $children['c']->status);

        // Each chunk ran once; 'b' threw on its single attempt.
        $this->assertSame(1, Cache::get('ran_a'));
        $this->assertSame(1, Cache::get('ran_b'));
        $this->assertSame(1, Cache::get('ran_c'));

        $flow->retryFailedSubtasks();

        $record = $flow->record()->fresh();
        $this->assertSame(FlowStatus::Completed, $record->status);

        // Only 'b' re-ran; the already-completed siblings were left untouched.
        $this->assertSame(1, Cache::get('ran_a'));
        $this->assertSame(2, Cache::get('ran_b'));
        $this->assertSame(1, Cache::get('ran_c'));

        // The parent aggregated all three child outputs in index order.
        $parent = $parent->fresh();
        $this->assertSame(FlowTaskStatus::Completed, $parent->status);
        $this->assertSame(
            [['id' => 'a'], ['id' => 'b'], ['id' => 'c']],
            $parent->output,
        );
    }

    public function test_retry_current_task_reruns_the_whole_fanout_without_duplicates(): void
    {
        Cache::flush();

        $flow = FanOutRetryFlow::start(['mode' => SubTaskCollection::MODE_PARALLEL]);
        $this->assertSame(FlowStatus::Paused, $flow->record()->fresh()->status);

        // Retry the whole parent: handle() re-runs and regenerates every chunk.
        $flow->retryCurrentTask();

        $record = $flow->record()->fresh();
        $this->assertSame(FlowStatus::Completed, $record->status);

        // All three chunks re-ran from scratch.
        $this->assertSame(2, Cache::get('ran_a'));
        $this->assertSame(2, Cache::get('ran_b'));
        $this->assertSame(2, Cache::get('ran_c'));

        // The previous children were discarded first, so the aggregated output has
        // exactly three entries — not a duplicated six.
        $parent = $record->topLevelTasks()->where('task_class', FanOutParentTask::class)->first();
        $this->assertCount(3, $parent->children()->get());
        $this->assertSame(
            [['id' => 'a'], ['id' => 'b'], ['id' => 'c']],
            $parent->output,
        );
    }

    public function test_sequential_retry_resumes_the_remaining_chunks_in_order(): void
    {
        Cache::flush();

        $flow = FanOutRetryFlow::start(['mode' => SubTaskCollection::MODE_SEQUENTIAL]);
        $record = $flow->record()->fresh();

        $this->assertSame(FlowStatus::Paused, $record->status);

        $parent = $record->topLevelTasks()->where('task_class', FanOutParentTask::class)->first();
        $children = $parent->children()->get()->keyBy('payload.id');

        // Sequential mode stops at the failure: 'a' completed, 'b' failed, 'c'
        // was never dispatched.
        $this->assertSame(FlowTaskStatus::Completed, $children['a']->status);
        $this->assertSame(FlowTaskStatus::Failed, $children['b']->status);
        $this->assertSame(FlowTaskStatus::Pending, $children['c']->status);
        $this->assertSame(1, Cache::get('ran_a'));
        $this->assertSame(1, Cache::get('ran_b'));
        $this->assertNull(Cache::get('ran_c'));

        $flow->retryFailedSubtasks();

        $record = $flow->record()->fresh();
        $this->assertSame(FlowStatus::Completed, $record->status);

        // 'b' re-ran and succeeded, then 'c' ran for the first time. 'a' untouched.
        $this->assertSame(1, Cache::get('ran_a'));
        $this->assertSame(2, Cache::get('ran_b'));
        $this->assertSame(1, Cache::get('ran_c'));

        $this->assertSame(
            [['id' => 'a'], ['id' => 'b'], ['id' => 'c']],
            $parent->fresh()->output,
        );
    }
}

class FanOutRetryFlow extends Flow
{
    public function run(array $payload): Generator
    {
        yield FanOutParentTask::init(['mode' => $payload['mode']]);
    }
}

class FanOutParentTask extends Task
{
    public function handle(): SubTaskCollection
    {
        $tasks = array_map(
            fn (string $id) => RetryChunkTask::init(['id' => $id]),
            ['a', 'b', 'c'],
        );

        return $this->payload['mode'] === SubTaskCollection::MODE_SEQUENTIAL
            ? SubTaskCollection::sequential($tasks)
            : SubTaskCollection::parallel($tasks);
    }
}

class RetryChunkTask extends Task
{
    public function handle(): array
    {
        $id = $this->payload['id'];
        Cache::increment("ran_{$id}");

        // 'b' fails on its first attempt and succeeds when retried.
        if ($id === 'b' && ! Cache::get('b_failed_once')) {
            Cache::put('b_failed_once', true);

            throw new \RuntimeException('chunk b failed');
        }

        return ['id' => $id];
    }
}
