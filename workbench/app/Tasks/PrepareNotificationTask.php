<?php

namespace Workbench\App\Tasks;

use Sergiumhi\LaravelFlow\Task;

/**
 * Prepares a notification campaign — resolves the subject, body, and recipient
 * list. The output is fed into FanOutChannelsTask, which dispatches one subtask
 * per delivery channel in parallel.
 */
class PrepareNotificationTask extends Task
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        return [
            'campaign_id' => $this->payload['campaign_id'] ?? 1,
            'subject' => $this->payload['subject'] ?? 'You have a new notification',
            'body' => $this->payload['body'] ?? 'Please check your account for details.',
            'recipients' => (int) ($this->payload['recipients'] ?? 100),
        ];
    }
}
