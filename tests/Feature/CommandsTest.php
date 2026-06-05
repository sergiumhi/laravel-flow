<?php

namespace Sergiumhi\LaravelFlow\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Sergiumhi\LaravelFlow\FlowStatus;
use Sergiumhi\LaravelFlow\Tests\TestCase;
use Workbench\App\Flows\OrderFlow;

class CommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_command_starts_a_flow(): void
    {
        // Flows live in the Workbench namespace here, so pass the FQCN. In a host
        // app, the short name "order" would resolve via config('flow.flows_folder').
        $this->artisan('flow:run', ['flow' => OrderFlow::class, '--payload' => '{"order_id":1}'])
            ->assertSuccessful()
            ->expectsOutputToContain('Started');
    }

    public function test_run_command_rejects_unknown_flow(): void
    {
        $this->artisan('flow:run', ['flow' => 'NopeFlow'])
            ->assertFailed();
    }

    public function test_run_command_rejects_invalid_payload(): void
    {
        $this->artisan('flow:run', ['flow' => OrderFlow::class, '--payload' => 'not-json'])
            ->assertFailed();
    }

    public function test_status_and_signal_commands(): void
    {
        $flow = OrderFlow::start(['order_id' => 1, 'requires_review' => true]);
        $id = $flow->publicId();

        $this->artisan('flow:status', ['publicId' => $id])
            ->assertSuccessful()
            ->expectsOutputToContain('manual_approval');

        $this->artisan('flow:signal', [
            'publicId' => $id,
            'name' => 'manual_approval',
            '--payload' => '{"approved_by":7}',
        ])->assertSuccessful();

        $this->assertSame(FlowStatus::Completed, $flow->record()->fresh()->status);
    }

    public function test_status_command_rejects_unknown_flow(): void
    {
        $this->artisan('flow:status', ['publicId' => 'flow_nope'])
            ->assertFailed();
    }
}
