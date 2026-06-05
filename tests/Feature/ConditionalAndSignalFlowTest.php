<?php

namespace Sergiumhi\LaravelFlow\Tests\Feature;

use Sergiumhi\LaravelFlow\Flow;
use Sergiumhi\LaravelFlow\FlowStatus;
use Sergiumhi\LaravelFlow\FlowTaskStatus;
use Workbench\App\Flows\OrderFlow;
use Workbench\App\Tasks\ChargeShippingTask;
use Workbench\App\Tasks\ManualReviewTask;
use Workbench\App\Tasks\ProcessPaymentTask;
use Workbench\App\Tasks\SendReceiptTask;
use Workbench\App\Tasks\SyncAnalyticsTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Sergiumhi\LaravelFlow\Tests\TestCase;

class ConditionalAndSignalFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_not_taken_runs_straight_through(): void
    {
        $flow = OrderFlow::start(['order_id' => 1]);

        $record = $flow->record()->fresh();
        $this->assertSame(FlowStatus::Completed, $record->status);

        // The signal was seeded as a preview but abandoned — the branch was never taken.
        $signal = $record->tasks()->whereNotNull('signal_name')->first();
        $this->assertNotNull($signal);
        $this->assertSame(FlowTaskStatus::Abandoned, $signal->status);

        $classes = $record->topLevelTasks()->pluck('task_class')->all();
        $this->assertSame([
            ProcessPaymentTask::class,
            ChargeShippingTask::class,
            SyncAnalyticsTask::class,
            SendReceiptTask::class,
        ], $classes);
    }

    public function test_it_parks_on_a_signal_then_resumes_with_payload(): void
    {
        $flow = OrderFlow::start(['order_id' => 1, 'requires_review' => true]);

        $record = $flow->record()->fresh();
        $this->assertSame(FlowStatus::Waiting, $record->status);

        $signalRow = $record->currentTask;
        $this->assertNotNull($signalRow);
        $this->assertSame('manual_approval', $signalRow->signal_name);
        $this->assertSame(FlowTaskStatus::Waiting, $signalRow->status);
        $this->assertNotNull($signalRow->signal_waited_at);

        // Sending the signal resumes the flow and delivers the payload.
        $flow->signal('manual_approval', ['approved_by' => 7]);

        $record->refresh();
        $this->assertSame(FlowStatus::Completed, $record->status);

        $review = $record->topLevelTasks()
            ->where('task_class', ManualReviewTask::class)
            ->first();
        $this->assertSame(['reviewed_by' => 7], $review->output);

        $signalRow->refresh();
        $this->assertNotNull($signalRow->signal_received_at);
        $this->assertSame(['approved_by' => 7], $signalRow->signal_payload);
    }

    public function test_wrong_signal_name_is_ignored(): void
    {
        $flow = OrderFlow::start(['order_id' => 1, 'requires_review' => true]);
        $this->assertSame(FlowStatus::Waiting, $flow->record()->fresh()->status);

        $flow->signal('not_the_signal', ['approved_by' => 7]);

        $this->assertSame(FlowStatus::Waiting, $flow->record()->fresh()->status);
    }

    public function test_signal_to_a_flow_not_waiting_is_ignored(): void
    {
        $flow = OrderFlow::start(['order_id' => 1]);
        $this->assertSame(FlowStatus::Completed, $flow->record()->fresh()->status);

        // Should be silently ignored, not throw or change state.
        Flow::find($flow->publicId())->signal('manual_approval', ['approved_by' => 7]);

        $this->assertSame(FlowStatus::Completed, $flow->record()->fresh()->status);
    }
}
