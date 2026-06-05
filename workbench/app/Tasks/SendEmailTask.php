<?php

namespace Workbench\App\Tasks;

use Sergiumhi\LaravelFlow\Task;

/**
 * Simulates dispatching a campaign email to all recipients.
 */
class SendEmailTask extends Task
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        $recipients = (int) ($this->payload['recipients'] ?? 0);

        return [
            'channel' => 'email',
            'delivered' => $recipients,
            'failed' => 0,
        ];
    }
}
