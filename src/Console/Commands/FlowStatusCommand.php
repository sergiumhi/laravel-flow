<?php

namespace Sergiumhi\LaravelFlow\Console\Commands;

use Illuminate\Console\Command;
use Sergiumhi\LaravelFlow\Models\FlowTask;
use Sergiumhi\LaravelFlow\Support\FlowModels;

class FlowStatusCommand extends Command
{
    protected $signature = 'flow:status {publicId : The flow public id (flow_...)}';

    protected $description = 'Render a flow run and its task DAG';

    public function handle(): int
    {
        $flow = FlowModels::flow()::where('public_id', $this->argument('publicId'))->first();

        if ($flow === null) {
            $this->components->error("No flow found with id \"{$this->argument('publicId')}\".");

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('  <options=bold>'.class_basename($flow->flow_class).'</>  '.$flow->public_id);
        $this->components->twoColumnDetail('Status', $this->colorStatus($flow->status->value));
        $this->newLine();

        foreach ($flow->topLevelTasks()->get() as $task) {
            $this->renderTask($task);

            foreach ($task->children as $child) {
                $this->renderTask($child, indent: true);
            }
        }

        $this->newLine();

        return self::SUCCESS;
    }

    private function renderTask(FlowTask $task, bool $indent = false): void
    {
        $prefix = $indent ? '      ↳ ' : '  ';

        $label = $task->isSignal()
            ? "[signal: {$task->signal_name}]"
            : class_basename($task->task_class);

        $timing = $this->timing($task);

        $this->line(sprintf(
            '%s%-3s %-28s %s%s',
            $prefix,
            '#'.$task->index,
            $label,
            $this->colorStatus($task->status->value),
            $timing ? "  <fg=gray>{$timing}</>" : '',
        ));
    }

    private function timing(FlowTask $task): ?string
    {
        if ($task->job_started_at && $task->job_finished_at) {
            $ms = $task->job_started_at->diffInMilliseconds($task->job_finished_at);

            return "{$ms}ms";
        }

        return null;
    }

    private function colorStatus(string $status): string
    {
        $color = match ($status) {
            'completed', 'reverted' => 'green',
            'running', 'waiting', 'reverting' => 'yellow',
            'failed', 'paused' => 'red',
            'skipped' => 'gray',
            default => 'blue',
        };

        return "<fg={$color}>{$status}</>";
    }
}
