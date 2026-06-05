<?php

namespace Sergiumhi\LaravelFlow\Tests\Feature;

use Sergiumhi\LaravelFlow\Flow;
use Sergiumhi\LaravelFlow\FlowStatus;
use Sergiumhi\LaravelFlow\Task;
use Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Sergiumhi\LaravelFlow\Tests\TestCase;

class RetryFromTaskTest extends TestCase
{
    use RefreshDatabase;

    public function test_retry_from_task_reruns_target_and_later_tasks_only(): void
    {
        Cache::flush();

        $flow = CountingFlow::start();
        $this->assertSame(FlowStatus::Completed, $flow->record()->fresh()->status);

        // Each task ran exactly once.
        $this->assertSame(1, Cache::get('ran_a'));
        $this->assertSame(1, Cache::get('ran_b'));
        $this->assertSame(1, Cache::get('ran_c'));

        // Re-run from the middle task.
        $middle = $flow->record()->topLevelTasks()
            ->where('task_class', CountBTask::class)
            ->first();

        $flow->retryFromTask($middle->public_id);

        $this->assertSame(FlowStatus::Completed, $flow->record()->fresh()->status);

        // Task A (before the target) was untouched; B and C re-ran.
        $this->assertSame(1, Cache::get('ran_a'));
        $this->assertSame(2, Cache::get('ran_b'));
        $this->assertSame(2, Cache::get('ran_c'));
    }
}

class CountingFlow extends Flow
{
    public function run(array $payload): Generator
    {
        yield CountATask::init();
        yield CountBTask::init();
        yield CountCTask::init();
    }
}

class CountATask extends Task
{
    public function handle(): array
    {
        Cache::increment('ran_a');

        return ['a' => true];
    }
}

class CountBTask extends Task
{
    public function handle(): array
    {
        Cache::increment('ran_b');

        return ['b' => true];
    }
}

class CountCTask extends Task
{
    public function handle(): array
    {
        Cache::increment('ran_c');

        return ['c' => true];
    }
}
