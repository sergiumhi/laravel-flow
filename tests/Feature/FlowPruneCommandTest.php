<?php

namespace Sergiumhi\LaravelFlow\Tests\Feature;

use Sergiumhi\LaravelFlow\FlowStatus;
use Sergiumhi\LaravelFlow\FlowTaskStatus;
use Sergiumhi\LaravelFlow\Models\FlowModel;
use Sergiumhi\LaravelFlow\Models\FlowTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Sergiumhi\LaravelFlow\Tests\TestCase;

/**
 * Covers the flow:prune command — deleting finished flow runs older than the
 * retention period while leaving recent and in-flight flows untouched.
 */
class FlowPruneCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deletes_terminal_flows_past_retention_and_cascades_to_tasks(): void
    {
        $old = $this->makeFlow(FlowStatus::Completed, updatedDaysAgo: 40);
        $task = FlowTask::create([
            'flow_id' => $old->id,
            'task_class' => 'App\\Tasks\\ExampleTask',
            'status' => FlowTaskStatus::Completed,
        ]);

        $this->artisan('flow:prune')->assertSuccessful();

        $this->assertDatabaseMissing('flows', ['id' => $old->id]);
        $this->assertDatabaseMissing('flow_tasks', ['id' => $task->id]);
    }

    public function test_it_keeps_terminal_flows_within_retention(): void
    {
        $recent = $this->makeFlow(FlowStatus::Failed, updatedDaysAgo: 5);

        $this->artisan('flow:prune')->assertSuccessful();

        $this->assertDatabaseHas('flows', ['id' => $recent->id]);
    }

    public function test_it_keeps_non_terminal_flows_even_when_old(): void
    {
        $running = $this->makeFlow(FlowStatus::Running, updatedDaysAgo: 90);
        $paused = $this->makeFlow(FlowStatus::Paused, updatedDaysAgo: 90);

        $this->artisan('flow:prune')->assertSuccessful();

        $this->assertDatabaseHas('flows', ['id' => $running->id]);
        $this->assertDatabaseHas('flows', ['id' => $paused->id]);
    }

    public function test_the_days_option_overrides_the_configured_retention(): void
    {
        $flow = $this->makeFlow(FlowStatus::Cancelled, updatedDaysAgo: 10);

        // Within the default 30-day retention, but past a 7-day override.
        $this->artisan('flow:prune', ['--days' => 7])->assertSuccessful();

        $this->assertDatabaseMissing('flows', ['id' => $flow->id]);
    }

    private function makeFlow(FlowStatus $status, int $updatedDaysAgo): FlowModel
    {
        $flow = FlowModel::create([
            'flow_class' => 'App\\Flows\\ExampleFlow',
            'status' => $status,
        ]);

        // Bypass Eloquent's timestamp touch to backdate updated_at directly.
        DB::table('flows')->where('id', $flow->id)->update([
            'updated_at' => now()->subDays($updatedDaysAgo),
        ]);

        return $flow;
    }
}
