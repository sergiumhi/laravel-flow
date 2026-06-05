<?php

namespace Sergiumhi\LaravelFlow\Tests\Feature;

use Sergiumhi\LaravelFlow\Flow;
use Sergiumhi\LaravelFlow\FlowStatus;
use Sergiumhi\LaravelFlow\FlowTaskStatus;
use Sergiumhi\LaravelFlow\Task;
use Workbench\App\Flows\OrderFlow;
use Workbench\App\Tasks\ChargeShippingTask;
use Workbench\App\Tasks\ProcessCsvTask;
use Workbench\App\Tasks\ProcessPaymentTask;
use Workbench\App\Tasks\SendReceiptTask;
use Workbench\App\Tasks\SyncAnalyticsTask;
use Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Sergiumhi\LaravelFlow\Tests\TestCase;

class RevertSagaTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancel_and_revert_reverts_completed_tasks_in_reverse_order(): void
    {
        // Happy path completes ProcessPayment and ChargeShipping (both have reverts).
        $flow = OrderFlow::start(['order_id' => 1]);
        $this->assertSame(FlowStatus::Completed, $flow->record()->fresh()->status);

        $flow->cancelAndRevert();

        $record = $flow->record()->fresh();
        $this->assertSame(FlowStatus::Reverted, $record->status);

        $payment = $record->topLevelTasks()->where('task_class', ProcessPaymentTask::class)->first();
        $shipping = $record->topLevelTasks()->where('task_class', ChargeShippingTask::class)->first();

        $this->assertSame(FlowTaskStatus::Reverted, $payment->status);
        $this->assertSame(FlowTaskStatus::Reverted, $shipping->status);

        // Reverts run in reverse: shipping is compensated before payment.
        $this->assertTrue(
            $shipping->job_finished_at <= $payment->job_finished_at,
            'shipping should be reverted before payment',
        );

        // Tasks without a revert task are left completed, not touched.
        $analytics = $record->topLevelTasks()->where('task_class', SyncAnalyticsTask::class)->first();
        $receipt = $record->topLevelTasks()->where('task_class', SendReceiptTask::class)->first();
        $this->assertSame(FlowTaskStatus::Completed, $analytics->status);
        $this->assertSame(FlowTaskStatus::Completed, $receipt->status);
    }

    public function test_cancel_halts_without_reverting(): void
    {
        // fail_shipping (no requires_review) skips the signal branch and pauses at
        // ChargeShippingTask after ProcessPaymentTask has completed.
        $flow = OrderFlow::start(['order_id' => 1, 'fail_shipping' => true]);
        $this->assertSame(FlowStatus::Paused, $flow->record()->fresh()->status);

        $flow->cancel();

        $record = $flow->record()->fresh();
        $this->assertSame(FlowStatus::Cancelled, $record->status);

        // Completed work is left in place — ProcessPayment is NOT reverted.
        $payment = $record->topLevelTasks()->where('task_class', ProcessPaymentTask::class)->first();
        $this->assertSame(FlowTaskStatus::Completed, $payment->status);

        // The task that failed keeps its Failed status.
        $shipping = $record->topLevelTasks()->where('task_class', ChargeShippingTask::class)->first();
        $this->assertSame(FlowTaskStatus::Failed, $shipping->status);

        // The not-yet-run tasks are seed rows (index null) — mark them Cancelled.
        $analytics = $record->allTopLevelTasks()->where('task_class', SyncAnalyticsTask::class)->first();
        $receipt = $record->allTopLevelTasks()->where('task_class', SendReceiptTask::class)->first();
        $this->assertSame(FlowTaskStatus::Cancelled, $analytics->status);
        $this->assertSame(FlowTaskStatus::Cancelled, $receipt->status);
    }

    public function test_subtask_parent_revert_receives_aggregated_output(): void
    {
        Cache::forget('revert_received');

        $flow = RevertCsvFlow::start(['rows' => range(1, 5), 'chunk_size' => 2]);

        // FailAfterTask pauses the flow.
        $this->assertSame(FlowStatus::Paused, $flow->record()->fresh()->status);

        $flow->cancelAndRevert();

        $record = $flow->record()->fresh();
        $this->assertSame(FlowStatus::Reverted, $record->status);

        $parent = $record->topLevelTasks()->where('task_class', ProcessCsvTask::class)->first();
        $this->assertSame(FlowTaskStatus::Reverted, $parent->status);

        // The revert task received the parent's aggregated subtask output.
        $this->assertSame([
            ['rows_processed' => 2],
            ['rows_processed' => 2],
            ['rows_processed' => 1],
        ], Cache::get('revert_received'));
    }
}

/**
 * Test-only flow: a parent task with subtasks plus a revert, followed by a task
 * that always fails so we can cancel() and observe the subtask parent's revert.
 */
class RevertCsvFlow extends Flow
{
    public function run(array $payload): Generator
    {
        yield ProcessCsvTask::init($payload)->revert(VerifyRevertTask::class);

        yield FailAfterTask::init();
    }
}

class FailAfterTask extends Task
{
    public function handle(): array
    {
        throw new \RuntimeException('stop here');
    }
}

class VerifyRevertTask extends Task
{
    public function handle(): array
    {
        Cache::put('revert_received', $this->payload['output']);

        return ['ok' => true];
    }
}
