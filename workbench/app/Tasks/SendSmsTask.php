<?php

namespace Workbench\App\Tasks;

use Sergiumhi\LaravelFlow\Task;

/**
 * Simulates dispatching campaign SMS messages. Only recipients who have opted
 * in to SMS receive it (modelled as 60 % of the total).
 */
class SendSmsTask extends Task
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        $recipients = (int) ($this->payload['recipients'] ?? 0);
        $smsRecipients = (int) round($recipients * 0.6);

        return [
            'channel' => 'sms',
            'delivered' => $smsRecipients,
            'failed' => 0,
        ];
    }
}
