<?php

namespace Workbench\App\Flows;

use Sergiumhi\LaravelFlow\Flow;
use Workbench\App\Tasks\FanOutChannelsTask;
use Workbench\App\Tasks\PrepareNotificationTask;
use Workbench\App\Tasks\RecordDeliveryTask;
use Generator;

/**
 * Demonstrates parallel fan-out across independent delivery channels.
 *
 * 1. PrepareNotificationTask  – resolves campaign content and recipient count.
 * 2. FanOutChannelsTask        – fans out three subtasks in parallel:
 *      • SendEmailTask   (all recipients)
 *      • SendSmsTask     (60 % opted-in)
 *      • SendPushTask    (40 % with the app)
 *    The parent waits for all three; its output is the aggregated channel list.
 * 3. RecordDeliveryTask        – summarises total delivered / failed across channels.
 *
 *     MultiChannelNotificationFlow::start([
 *         'campaign_id' => 42,
 *         'subject'     => 'Your order is ready',
 *         'recipients'  => 150,
 *     ]);
 */
class MultiChannelNotificationFlow extends Flow
{
    public function run(array $payload): Generator
    {
        $notification = yield PrepareNotificationTask::init($payload);

        $channelResults = yield FanOutChannelsTask::init($notification);

        yield RecordDeliveryTask::init([
            'campaign_id' => $notification['campaign_id'],
            'channel_results' => $channelResults,
        ]);
    }
}
