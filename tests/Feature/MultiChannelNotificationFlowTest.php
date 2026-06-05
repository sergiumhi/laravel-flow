<?php

namespace Sergiumhi\LaravelFlow\Tests\Feature;

use Sergiumhi\LaravelFlow\FlowStatus;
use Sergiumhi\LaravelFlow\FlowTaskStatus;
use Sergiumhi\LaravelFlow\SubTaskCollection;
use Workbench\App\Flows\MultiChannelNotificationFlow;
use Workbench\App\Tasks\FanOutChannelsTask;
use Workbench\App\Tasks\PrepareNotificationTask;
use Workbench\App\Tasks\RecordDeliveryTask;
use Workbench\App\Tasks\SendEmailTask;
use Workbench\App\Tasks\SendPushTask;
use Workbench\App\Tasks\SendSmsTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Sergiumhi\LaravelFlow\Tests\TestCase;

class MultiChannelNotificationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_flow_completes_with_three_parallel_channel_subtasks(): void
    {
        $flow = MultiChannelNotificationFlow::start([
            'campaign_id' => 42,
            'subject' => 'Your order is ready',
            'recipients' => 100,
        ]);

        $record = $flow->record()->fresh();
        $this->assertSame(FlowStatus::Completed, $record->status);
    }

    public function test_fan_out_task_spawns_three_parallel_subtasks(): void
    {
        $flow = MultiChannelNotificationFlow::start([
            'campaign_id' => 42,
            'recipients' => 100,
        ]);

        $record = $flow->record()->fresh();

        $parent = $record->topLevelTasks()
            ->where('task_class', FanOutChannelsTask::class)
            ->first();

        $this->assertSame(SubTaskCollection::MODE_PARALLEL, $parent->subtask_mode);
        $this->assertSame(FlowTaskStatus::Completed, $parent->status);

        $children = $parent->children()->orderBy('step_index')->get();
        $this->assertCount(3, $children);

        $childClasses = $children->pluck('task_class')->all();
        $this->assertContains(SendEmailTask::class, $childClasses);
        $this->assertContains(SendSmsTask::class, $childClasses);
        $this->assertContains(SendPushTask::class, $childClasses);

        foreach ($children as $child) {
            $this->assertSame(FlowTaskStatus::Completed, $child->status);
        }
    }

    public function test_prepare_task_output_flows_into_fan_out(): void
    {
        $flow = MultiChannelNotificationFlow::start([
            'campaign_id' => 99,
            'subject' => 'Hello',
            'recipients' => 200,
        ]);

        $record = $flow->record()->fresh();

        $prepare = $record->topLevelTasks()
            ->where('task_class', PrepareNotificationTask::class)
            ->first();

        $this->assertSame([
            'campaign_id' => 99,
            'subject' => 'Hello',
            'body' => 'Please check your account for details.',
            'recipients' => 200,
        ], $prepare->output);
    }

    public function test_record_delivery_aggregates_all_channel_outputs(): void
    {
        $flow = MultiChannelNotificationFlow::start([
            'campaign_id' => 42,
            'recipients' => 100,
        ]);

        $record = $flow->record()->fresh();

        $recordTask = $record->topLevelTasks()
            ->where('task_class', RecordDeliveryTask::class)
            ->first();

        $output = $recordTask->output;
        $this->assertSame(42, $output['campaign_id']);
        $this->assertSame(3, $output['channels']);
        // email=100, sms=60, push=40
        $this->assertSame(200, $output['total_delivered']);
        $this->assertSame(0, $output['total_failed']);
    }
}
