<?php

namespace Workbench\App\Tasks;

use Sergiumhi\LaravelFlow\SubTaskCollection;
use Sergiumhi\LaravelFlow\Task;

/**
 * Spawns one subtask per report section, dispatched sequentially (each waits for
 * the previous to finish). The parent's aggregated output is the ordered list of
 * section outputs.
 */
class BuildSectionsTask extends Task
{
    public function handle(): SubTaskCollection
    {
        $sections = $this->payload['sections'] ?? ['intro', 'body', 'summary'];

        return SubTaskCollection::sequential(
            array_map(
                fn (string $section) => BuildSectionTask::init(['section' => $section]),
                $sections,
            ),
        );
    }
}
