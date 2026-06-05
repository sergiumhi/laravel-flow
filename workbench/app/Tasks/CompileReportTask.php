<?php

namespace Workbench\App\Tasks;

use Sergiumhi\LaravelFlow\Task;

/**
 * Compiles the finished sections into a report summary.
 */
class CompileReportTask extends Task
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        $sections = $this->payload['sections'] ?? [];

        return [
            'sections' => array_map(fn ($s) => $s['section'] ?? null, $sections),
            'total_words' => array_sum(array_map(fn ($s) => $s['words'] ?? 0, $sections)),
        ];
    }
}
