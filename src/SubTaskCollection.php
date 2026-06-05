<?php

namespace Sergiumhi\LaravelFlow;

use Traversable;

/**
 * Returned from a Task::handle() to spawn child tasks (subtasks). The parent task
 * is only marked completed once all of its subtasks complete.
 *
 *     return SubTaskCollection::parallel($tasks);   // dispatched all at once
 *     return SubTaskCollection::sequential($tasks);  // one at a time, in order
 */
final class SubTaskCollection
{
    public const MODE_PARALLEL = 'parallel';

    public const MODE_SEQUENTIAL = 'sequential';

    /**
     * @param  array<int, Task>  $tasks
     */
    private function __construct(
        public readonly string $mode,
        public readonly array $tasks,
    ) {}

    /**
     * @param  iterable<int, Task>  $tasks
     */
    public static function parallel(iterable $tasks): self
    {
        return new self(self::MODE_PARALLEL, self::normalize($tasks));
    }

    /**
     * @param  iterable<int, Task>  $tasks
     */
    public static function sequential(iterable $tasks): self
    {
        return new self(self::MODE_SEQUENTIAL, self::normalize($tasks));
    }

    /**
     * @param  iterable<int, Task>  $tasks
     * @return array<int, Task>
     */
    private static function normalize(iterable $tasks): array
    {
        $items = $tasks instanceof Traversable ? iterator_to_array($tasks) : $tasks;

        return array_values($items);
    }
}
