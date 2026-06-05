<?php

namespace Sergiumhi\LaravelFlow;

/**
 * Declares what a flow should do when a task fails after exhausting its retries.
 */
enum OnFailure: string
{
    /** Flow status → paused. Waits for retryCurrentTask(), skipCurrentTask(), or cancel(). */
    case PAUSE = 'pause';

    /** Task marked skipped, flow advances to the next task automatically. */
    case SKIP = 'skip';

    /** Flow immediately begins reverting all completed tasks in reverse. */
    case REVERT = 'revert';
}
