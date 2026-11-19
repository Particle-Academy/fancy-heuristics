<?php

declare(strict_types=1);

namespace FancyHeuristics\Tests;

use FancyHeuristics\Contracts\HostResolver;
use FancyHeuristics\Facades\Heuristics;
use FancyHeuristics\HeuristicsServiceProvider;
use FancyHeuristics\Tests\Support\FakeHostResolver;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as LaravelTestCase;

/**
 * Base TestCase for Fancy Heuristics package tests.
 *
 * A hand-built Laravel application (laravel/framework only, no
 * orchestra/testbench) in a throwaway base path: no `.env` content, no package
 * discovery, the framework's own default config plus the overrides below.
 * In-memory SQLite and blank provider keys are forced.
 */
abstract class TestCase extends LaravelTestCase
{
    use RefreshDatabase;

    /**
     * `artisan()` returns the exit code. Laravel's output expectations are
     * built on Mockery, which is not a dependency here; read
     * `Artisan::output()` instead.
     */
    public $mockConsoleOutput = false;

    public function createApplication(): Application
    {
        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fancy-heuristics-tests';
        foreach (['bootstrap/cache', 'config', 'database', 'storage/framework/cache', 'storage/framework/views', 'storage/framework/sessions', 'storage/logs'] as $dir) {
            if (! is_dir($base.'/'.$dir)) {
                mkdir($base.'/'.$dir, 0777, true);
            }
        }
        if (! is_file($base.'/.env')) {
            touch($base.'/.env');
        }

        $app = Application::configure(basePath: $base)->withMiddleware()->withExceptions()->create();

        $app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
            $config = $app['config'];
            $config->set('app.env', 'testing');
            $app->detectEnvironment(fn (): string => 'testing');
            $config->set('app.providers', array_merge($config->get('app.providers', []), [HeuristicsServiceProvider::class]));
            $config->set('app.aliases', array_merge($config->get('app.aliases', []), ['Heuristics' => Heuristics::class]));
            $config->set('session.driver', 'array');
            $config->set('queue.default', 'sync');
            $config->set('mail.default', 'array');
            $config->set('logging.default', 'null');
            $config->set('services.anthropic.key', '');
            $config->set('services.openai.key', '');
        });

        // After every provider has registered and before any boots.
        $app->afterBootstrapping(RegisterProviders::class, function (Application $app): void {
            $this->defineEnvironment($app);
        });

        $app->make(Kernel::class)->bootstrap();

        // Routes registered while providers booted are not in the name index
        // until it is refreshed (a host app's route loader does this).
        $app['router']->getRoutes()->refreshNameLookups();
        $app['router']->getRoutes()->refreshActionLookups();

        return $app;
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

        // Pixel IP hashes are keyed by the app key; a real host always has one.
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));

        // Array cache so the throttle middleware doesn't need a DB cache table.
        $app['config']->set('cache.default', 'array');

        // Deterministic DNS: no test may depend on a live lookup.
        $app->instance(HostResolver::class, new FakeHostResolver);
    }
}
