<?php

declare(strict_types=1);

namespace FancyHeuristics;

use FancyHeuristics\Console\Commands\VerifyPixelsCommand;
use FancyHeuristics\Services\EventCollector;
use FancyHeuristics\Services\HeatmapAggregator;
use Illuminate\Support\ServiceProvider;

/**
 * Fancy Heuristics Service Provider.
 *
 * Merges config, loads + publishes migrations, loads the ingestion routes,
 * registers the `heuristics:verify-pixels` command, and binds the
 * HeuristicsManager singleton behind the `heuristics` facade accessor.
 *
 * Scheduling the twice-daily verifier is the host's job. Example
 * (bootstrap/app.php ->withSchedule, Laravel 11+):
 *
 *   use Illuminate\Console\Scheduling\Schedule;
 *
 *   ->withSchedule(function (Schedule $schedule) {
 *       $schedule->command('heuristics:verify-pixels')
 *           ->cron(config('heuristics.verify.cron')); // default: 03:00 & 15:00
 *   })
 */
class HeuristicsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/heuristics.php', 'heuristics');

        $this->app->singleton('heuristics', function ($app) {
            return new HeuristicsManager(
                $app->make(EventCollector::class),
                $app->make(HeatmapAggregator::class),
            );
        });

        $this->app->alias('heuristics', HeuristicsManager::class);
    }

    public function boot(): void
    {
        $this->registerMigrations();
        $this->registerPublishables();
        $this->registerRoutes();
        $this->registerCommands();
    }

    protected function registerMigrations(): void
    {
        if (config('heuristics.migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }
    }

    protected function registerPublishables(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/heuristics.php' => config_path('heuristics.php'),
        ], 'heuristics-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'heuristics-migrations');

        $this->publishes([
            __DIR__.'/../config/heuristics.php' => config_path('heuristics.php'),
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'heuristics');
    }

    protected function registerRoutes(): void
    {
        if (config('heuristics.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/heuristics.php');
        }
    }

    protected function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            VerifyPixelsCommand::class,
        ]);
    }
}
