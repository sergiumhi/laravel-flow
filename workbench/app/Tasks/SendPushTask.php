<?php

namespace Workbench\App\Tasks;

use Sergiumhi\LaravelFlow\Task;

/**
 * Simulates dispatching push notifications. Only recipients with the mobile app
 * installed receive them (modelled as 40 % of the total).
 */
class SendPushTask extends Task
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        $recipients = (int) ($this->payload['recipients'] ?? 0);
        $pushRecipients = (int) round($recipients * 0.4);

        return [
            'channel' => 'push',
            'delivered' => $pushRecipients,
            'failed' => 0,
        ];
    }
}
