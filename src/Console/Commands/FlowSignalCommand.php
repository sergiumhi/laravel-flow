<?php

namespace Sergiumhi\LaravelFlow\Console\Commands;

use Illuminate\Console\Command;
use Sergiumhi\LaravelFlow\Flow;
use Throwable;

class FlowSignalCommand extends Command
{
    protected $signature = 'flow:signal
        {publicId : The flow public id (flow_...)}
        {name : The signal name to send}
        {--payload= : JSON payload delivered to the waiting yield}';

    protected $description = 'Send a named signal to a waiting flow';

    public function handle(): int
    {
        $flow = Flow::find($this->argument('publicId'));

        if ($flow === null) {
            $this->components->error("No flow found with id \"{$this->argument('publicId')}\".");

            return self::FAILURE;
        }

        $raw = $this->option('payload');
        $payload = [];

        if ($raw !== null && $raw !== '') {
            try {
                $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                $this->components->error('The --payload option must be valid JSON.');

                return self::FAILURE;
            }
        }

        $flow->signal($this->argument('name'), is_array($payload) ? $payload : []);

        $this->components->info("Signal \"{$this->argument('name')}\" delivered.");
        $this->components->twoColumnDetail('Status', $flow->status()->value);

        return self::SUCCESS;
    }
}
