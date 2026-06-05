<?php

declare(strict_types=1);

namespace FancyHeuristics\Tests;

use FancyHeuristics\Facades\Heuristics;
use FancyHeuristics\HeuristicsServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Base TestCase for Fancy Heuristics package tests.
 *
 * Uses Orchestra Testbench to provide a Laravel testing environment without
 * a full host application.
 */
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            HeuristicsServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return [
            'Heuristics' => Heuristics::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Array cache so the throttle middleware doesn't need a DB cache table.
        $app['config']->set('cache.default', 'array');
    }
}
