<?php

namespace Sergiumhi\LaravelFlow\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Sergiumhi\LaravelFlow\FlowStatus;
use Sergiumhi\LaravelFlow\Support\FlowModels;

class FlowPruneCommand extends Command
{
    protected $signature = 'flow:prune {--days= : Override the configured retention period (in days)}';

    protected $description = 'Delete finished flow runs older than the retention period';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('flow.prune.retention_days', 30));

        /** @var class-string<Model> $model */
        $model = FlowModels::flow();

        $cutoff = now()->subDays($days);

        $terminalStatuses = array_map(
            static fn (FlowStatus $status): string => $status->value,
            [FlowStatus::Completed, FlowStatus::Failed, FlowStatus::Cancelled, FlowStatus::Reverted],
        );

        // Deleting a `flows` row cascades to its `flow_tasks` via the FK on
        // flow_tasks.flow_id, so child rows are removed automatically.
        $deleted = $model::query()
            ->whereIn('status', $terminalStatuses)
            ->where('updated_at', '<', $cutoff)
            ->delete();

        $this->components->info("Pruned {$deleted} finished flow(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
