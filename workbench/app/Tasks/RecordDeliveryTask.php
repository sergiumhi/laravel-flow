<?php

namespace Workbench\App\Tasks;

use Sergiumhi\LaravelFlow\Task;

/**
 * Aggregates the delivery results from all three channel subtasks and records
 * a campaign summary. Receives the FanOutChannelsTask aggregated output via the
 * generator (a list of per-channel results).
 */
class RecordDeliveryTask extends Task
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        /** @var list<array{channel: string, delivered: int, failed: int}> $channelResults */
        $channelResults = $this->payload['channel_results'] ?? [];

        $totalDelivered = array_sum(array_column($channelResults, 'delivered'));
        $totalFailed = array_sum(array_column($channelResults, 'failed'));

        return [
            'campaign_id' => $this->payload['campaign_id'] ?? null,
            'channels' => count($channelResults),
            'total_delivered' => $totalDelivered,
            'total_failed' => $totalFailed,
        ];
    }
}
