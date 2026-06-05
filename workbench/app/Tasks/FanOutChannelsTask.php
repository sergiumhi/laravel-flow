<?php

namespace Workbench\App\Tasks;

use Sergiumhi\LaravelFlow\SubTaskCollection;
use Sergiumhi\LaravelFlow\Task;

/**
 * Fans out to three parallel delivery channels (email, SMS, push). All three run
 * at the same time; the parent completes once every channel subtask finishes.
 * The aggregated channel outputs flow into RecordDeliveryTask.
 */
class FanOutChannelsTask extends Task
{
    public function handle(): SubTaskCollection
    {
        return SubTaskCollection::parallel([
            SendEmailTask::init($this->payload),
            SendSmsTask::init($this->payload),
            SendPushTask::init($this->payload),
        ]);
    }
}
