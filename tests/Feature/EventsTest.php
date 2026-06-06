<?php

namespace Sergiumhi\LaravelFlow\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Sergiumhi\LaravelFlow\Events\FlowStateChanged;
use Sergiumhi\LaravelFlow\Events\FlowTaskStateChanged;
use Sergiumhi\LaravelFlow\FlowStatus;
use Sergiumhi\LaravelFlow\FlowTaskStatus;
use Sergiumhi\LaravelFlow\Tests\TestCase;
use Workbench\App\Flows\OrderFlow;
use Workbench\App\Tasks\ManualReviewTask;
use Workbench\App\Tasks\SyncAnalyticsTask;

class EventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_flow_status_transitions_dispatch_events(): void
    {
        // Fake only our domain events: the underlying `eloquent.updated` model
        // events still reach the real listeners (registered in the provider),
        // whose `event()` calls are what we intercept here.
        Event::fake([FlowStateChanged::class, FlowTaskStateChanged::class]);

        OrderFlow::start(['order_id' => 1]); // happy path, runs sync to completion

        // First real transition off the initial Pending insert is Pending → Running.
        Event::assertDispatched(
            FlowStateChanged::class,
            fn (FlowStateChanged $e) => $e->from === FlowStatus::Pending && $e->to === FlowStatus::Running,
        );

        // …and it ends Completed.
        Event::assertDispatched(
            FlowStateChanged::class,
            fn (FlowStateChanged $e) => $e->to === FlowStatus::Completed,
        );
    }

    public function test_task_lifecycle_dispatches_events(): void
    {
        Event::fake([FlowStateChanged::class, FlowTaskStateChanged::class]);

        OrderFlow::start(['order_id' => 1]);

        Event::assertDispatched(
            FlowTaskStateChanged::class,
            fn (FlowTaskStateChanged $e) => $e->to === FlowTaskStatus::Running,
        );

        Event::assertDispatched(
            FlowTaskStateChanged::class,
            fn (FlowTaskStateChanged $e) => $e->to === FlowTaskStatus::Completed,
        );
    }

    public function test_no_op_writes_do_not_dispatch(): void
    {
        Event::fake([FlowStateChanged::class]);

        $flow = OrderFlow::start(['order_id' => 1]);

        // Re-saving with the same status must not emit a transition.
        $flow->record()->fresh()->update(['payload' => ['order_id' => 2]]);

        // Every FlowStateChanged recorded must be a real change (from !== to).
        Event::assertDispatched(
            FlowStateChanged::class,
            fn (FlowStateChanged $e) => $e->from !== $e->to,
        );
    }

    public function test_abandoned_seed_rows_dispatch_events_via_bulk_path(): void
    {
        Event::fake([FlowTaskStateChanged::class]);

        // Happy path leaves the conditional review branch (signal + ManualReview)
        // as unmatched seed rows, abandoned at completion via the bulk update.
        OrderFlow::start(['order_id' => 1]);

        Event::assertDispatched(
            FlowTaskStateChanged::class,
            fn (FlowTaskStateChanged $e) => $e->to === FlowTaskStatus::Abandoned
                && $e->task->task_class === ManualReviewTask::class,
        );
    }

    public function test_cancel_dispatches_per_task_cancelled_events_via_bulk_path(): void
    {
        Event::fake([FlowStateChanged::class, FlowTaskStateChanged::class]);

        // Pause on a failed shipping task, leaving later tasks not-yet-run.
        $flow = OrderFlow::start(['order_id' => 1, 'fail_shipping' => true]);
        $this->assertSame(FlowStatus::Paused, $flow->record()->fresh()->status);

        $flow->cancel();

        // The not-yet-run tasks (e.g. SyncAnalytics) are bulk-marked Cancelled.
        Event::assertDispatched(
            FlowTaskStateChanged::class,
            fn (FlowTaskStateChanged $e) => $e->to === FlowTaskStatus::Cancelled
                && $e->task->task_class === SyncAnalyticsTask::class,
        );

        Event::assertDispatched(
            FlowStateChanged::class,
            fn (FlowStateChanged $e) => $e->to === FlowStatus::Cancelled,
        );
    }
}
