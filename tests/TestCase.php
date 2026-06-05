<?php

namespace Sergiumhi\LaravelFlow\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Sergiumhi\LaravelFlow\FlowServiceProvider;

abstract class TestCase extends BaseTestCase
{
    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            FlowServiceProvider::class,
        ];
    }

    /**
     * Testbench defaults sqlite foreign keys to off. Enable them so the
     * `flow_tasks.flow_id` cascade behaves as it would on MySQL/Postgres.
     *
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $connection = $app['config']->get('database.default');

        $app['config']->set("database.connections.{$connection}.foreign_key_constraints", true);
    }
}
