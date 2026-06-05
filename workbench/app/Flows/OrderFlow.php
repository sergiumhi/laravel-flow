<?php

namespace Workbench\App\Flows;

use Sergiumhi\LaravelFlow\Flow;
use Sergiumhi\LaravelFlow\OnFailure;
use Sergiumhi\LaravelFlow\Signal;
use Workbench\App\Tasks\CancelShippingTask;
use Workbench\App\Tasks\ChargeShippingTask;
use Workbench\App\Tasks\ManualReviewTask;
use Workbench\App\Tasks\ProcessPaymentTask;
use Workbench\App\Tasks\RefundPaymentTask;
use Workbench\App\Tasks\SendReceiptTask;
use Workbench\App\Tasks\SyncAnalyticsTask;
use Generator;

/**
 * Exercises most of the framework: a conditional branch, a Signal pause point,
 * revert tasks (saga), and per-task failure behaviour.
 *
 *     OrderFlow::start(['order_id' => 1]);                       // happy path
 *     OrderFlow::start(['order_id' => 1, 'requires_review' => true]); // parks on signal
 *     OrderFlow::start(['order_id' => 1, 'fail_shipping' => true]);   // pauses on failure
 *     OrderFlow::start(['order_id' => 1, 'fail_analytics' => true]);  // skips and continues
 *
 * Resume a parked flow:
 *     Flow::find($publicId)->signal('manual_approval', ['approved_by' => 7]);
 */
class OrderFlow extends Flow
{
    public function run(array $payload): Generator
    {
        // Result of a task is available immediately after the yield.
        $payment = yield ProcessPaymentTask::init($payload)
            ->revert(RefundPaymentTask::class);

        // Conditional task — only persisted if this branch is taken. The start
        // payload is passed straight into run(), so branch on it directly.
        if ($payload['requires_review'] ?? false) {
            // Flow pauses here until the 'manual_approval' signal is sent externally.
            $approval = yield Signal::waitFor('manual_approval');

            yield ManualReviewTask::init(['approved_by' => $approval['approved_by']]);
        }

        yield ChargeShippingTask::init($payload)
            ->revert(CancelShippingTask::class)
            ->onFailure(OnFailure::PAUSE);

        yield SyncAnalyticsTask::init($payload)
            ->onFailure(OnFailure::SKIP);

        yield SendReceiptTask::init();
    }
}
