<?php

namespace Workbench\App\Tasks;

use Sergiumhi\LaravelFlow\Task;

/**
 * Builds a single report section. Used as a sequential subtask by BuildSectionsTask.
 */
class BuildSectionTask extends Task
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        $section = $this->payload['section'] ?? 'unknown';

        return ['section' => $section, 'words' => strlen($section) * 10];
    }
}
