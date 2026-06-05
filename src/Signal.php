<?php

namespace Sergiumhi\LaravelFlow;

/**
 * A named pause point inside a flow. The flow stops at the yield and waits —
 * indefinitely — until an external caller sends the matching signal. No job is
 * dispatched while waiting.
 *
 * Usage inside Flow::run():
 *
 *     $approval = yield Signal::waitFor('manual_approval');
 */
final class Signal
{
    private function __construct(public readonly string $name) {}

    public static function waitFor(string $name): self
    {
        return new self($name);
    }
}
