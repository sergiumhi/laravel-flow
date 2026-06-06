<?php

namespace Sergiumhi\LaravelFlow\Events;

use Sergiumhi\LaravelFlow\FlowStatus;
use Sergiumhi\LaravelFlow\Models\FlowModel;

/**
 * Dispatched whenever a flow's status changes from one state to another.
 *
 * This is a plain domain event — it deliberately does NOT implement
 * ShouldBroadcast. The engine announces "this flow moved from X to Y" and
 * nothing more; how that reaches a browser (poll the DB, publish to Redis for
 * SSE, rebroadcast over a websocket) is the host app's choice. Subscribe in your
 * app and decide what to do: broadcast, notify, log, or write metrics.
 *
 * Carrying `from`/`to` means a listener can react to a specific transition (e.g.
 * only on `to === FlowStatus::Failed`) without re-querying — and the enums stay
 * meaningful even for a queued listener that re-fetches the model at its current,
 * possibly-newer status.
 */
final class FlowStateChanged
{
    public function __construct(
        public FlowModel $flow,
        public FlowStatus $from,
        public FlowStatus $to,
    ) {}
}
