<?php

namespace Sergiumhi\LaravelFlow\Console\Commands;

use Illuminate\Console\Command;
use Sergiumhi\LaravelFlow\Flow;
use Throwable;

class FlowRunCommand extends Command
{
    protected $signature = 'flow:run
        {flow : Flow class name (e.g. OrderFlow, App\\Flows\\OrderFlow, or "order")}
        {--payload= : JSON payload, e.g. {"order_id":1}}';

    protected $description = 'Start a flow and print its public id';

    public function handle(): int
    {
        $class = $this->resolveFlowClass($this->argument('flow'));

        if ($class === null) {
            $this->components->error("Could not resolve a Flow class from \"{$this->argument('flow')}\".");

            return self::FAILURE;
        }

        $payload = $this->decodePayload();

        if ($payload === false) {
            $this->components->error('The --payload option must be valid JSON.');

            return self::FAILURE;
        }

        /** @var Flow $flow */
        $flow = $class::start($payload);

        $this->components->info("Started {$class}");
        $this->components->twoColumnDetail('Flow id', $flow->publicId());
        $this->components->twoColumnDetail('Status', $flow->status()->value);
        $this->newLine();
        $this->line("  Inspect with: <fg=cyan>php artisan flow:status {$flow->publicId()}</>");

        return self::SUCCESS;
    }

    /**
     * Resolve a Flow class from a short name, studly name, or FQCN. Candidates
     * are built against the host application's flows namespace
     * (root namespace + config('flow.flows_folder'), e.g. App\Flows), with the
     * raw value tried first so fully-qualified names always work.
     */
    public static function resolveFlowClass(string $name): ?string
    {
        $namespace = rtrim(app()->getNamespace(), '\\').'\\'.config('flow.flows_folder', 'Flows').'\\';

        $candidates = [
            $name,
            $namespace.$name,
            $namespace.str($name)->studly(),
            $namespace.str($name)->studly()->finish('Flow'),
        ];

        foreach ($candidates as $candidate) {
            if (class_exists($candidate) && is_subclass_of($candidate, Flow::class)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|false
     */
    private function decodePayload(): array|false
    {
        $raw = $this->option('payload');

        if ($raw === null || $raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return false;
        }

        return is_array($decoded) ? $decoded : false;
    }
}
