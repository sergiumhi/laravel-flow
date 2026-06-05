<?php

namespace Sergiumhi\LaravelFlow\Tests\Feature;

use Sergiumhi\LaravelFlow\FlowStatus;
use Sergiumhi\LaravelFlow\FlowTaskStatus;
use Workbench\App\Flows\BookingFlow;
use Workbench\App\Flows\OrderFlow;
use Workbench\App\Tasks\ChargeCardTask;
use Workbench\App\Tasks\ChargeShippingTask;
use Workbench\App\Tasks\ConfirmBookingTask;
use Workbench\App\Tasks\ReserveRoomTask;
use Workbench\App\Tasks\SyncAnalyticsTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Sergiumhi\LaravelFlow\Tests\TestCase;

class OnFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_pause_halts_the_flow_for_manual_intervention(): void
    {
        $flow = OrderFlow::start(['order_id' => 1, 'fail_shipping' => true]);

        $record = $flow->record()->fresh();
        $this->assertSame(FlowStatus::Paused, $record->status);

        $failed = $record->currentTask;
        $this->assertSame(ChargeShippingTask::class, $failed->task_class);
        $this->assertSame(FlowTaskStatus::Failed, $failed->status);
    }

    public function test_skip_current_task_advances_a_paused_flow(): void
    {
        $flow = OrderFlow::start(['order_id' => 1, 'fail_shipping' => true]);
        $this->assertSame(FlowStatus::Paused, $flow->record()->fresh()->status);

        $flow->skipCurrentTask();

        $record = $flow->record()->fresh();
        $this->assertSame(FlowStatus::Completed, $record->status);

        $shipping = $record->topLevelTasks()
            ->where('task_class', ChargeShippingTask::class)
            ->first();
        $this->assertSame(FlowTaskStatus::Skipped, $shipping->status);
    }

    public function test_retry_current_task_redispatches_the_failed_task(): void
    {
        $flow = OrderFlow::start(['order_id' => 1, 'fail_shipping' => true]);
        $failedId = $flow->record()->fresh()->current_task_id;

        $flow->retryCurrentTask();

        // Still failing (payload unchanged) → paused again, but it was re-attempted.
        $record = $flow->record()->fresh();
        $this->assertSame(FlowStatus::Paused, $record->status);
        $this->assertSame($failedId, $record->current_task_id);
        $this->assertSame(1, $record->currentTask->attempts);
    }

    public function test_skip_failure_mode_auto_advances_without_pausing(): void
    {
        $flow = OrderFlow::start(['order_id' => 1, 'fail_analytics' => true]);

        $record = $flow->record()->fresh();
        $this->assertSame(FlowStatus::Completed, $record->status);

        $analytics = $record->topLevelTasks()
            ->where('task_class', SyncAnalyticsTask::class)
            ->first();
        $this->assertSame(FlowTaskStatus::Skipped, $analytics->status);
    }

    public function test_revert_failure_mode_retries_then_compensates(): void
    {
        $flow = BookingFlow::start(['room' => '101', 'fail_charge' => true]);

        $record = $flow->record()->fresh();
        $this->assertSame(FlowStatus::Failed, $record->status);

        $charge = $record->topLevelTasks()
            ->where('task_class', ChargeCardTask::class)
            ->first();
        $this->assertSame(FlowTaskStatus::Failed, $charge->status);
        $this->assertSame(3, $charge->attempts, 'ChargeCardTask should retry up to tries()');

        // The completed reservation was rolled back.
        $reserve = $record->topLevelTasks()
            ->where('task_class', ReserveRoomTask::class)
            ->first();
        $this->assertSame(FlowTaskStatus::Reverted, $reserve->status);

        // The final step was seeded as a preview but abandoned — it was never reached.
        $confirm = $record->allTopLevelTasks()
            ->where('task_class', ConfirmBookingTask::class)
            ->first();
        $this->assertSame(FlowTaskStatus::Abandoned, $confirm->status);
        $this->assertNull($confirm->job_started_at, 'ConfirmBookingTask should never have run');
    }
}
