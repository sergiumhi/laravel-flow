<?php

namespace Sergiumhi\LaravelFlow\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Sergiumhi\LaravelFlow\Events\FlowStateChanged;
use Sergiumhi\LaravelFlow\Events\FlowTaskStateChanged;
use Sergiumhi\LaravelFlow\Tests\TestCase;
use Workbench\App\Flows\OrderFlow;

/**
 * When `flow.events.enabled` is false the model observers are never registered
 * and the bulk-update paths short-circuit, so nothing is dispatched. The flag is
 * read at boot, so it must be set in defineEnvironment() — before the provider's
 * boot() runs.
 */
class EventsDisabledTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('flow.events.enabled', false);
    }

    public function test_no_events_dispatched_when_disabled(): void
    {
        Event::fake([FlowStateChanged::class, FlowTaskStateChanged::class]);

        $flow = OrderFlow::start(['order_id' => 1, 'fail_shipping' => true]);
        $flow->cancel(); // exercises both the observer and the bulk-update path

        Event::assertNotDispatched(FlowStateChanged::class);
        Event::assertNotDispatched(FlowTaskStateChanged::class);
    }
}
