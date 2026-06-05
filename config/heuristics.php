<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Database table prefix
    |--------------------------------------------------------------------------
    |
    | All Fancy Heuristics tables share this prefix (heuristics_sites,
    | heuristics_events, heuristics_pixel_pings). Override only if it
    | collides with an existing schema.
    |
    */
    'table_prefix' => env('HEURISTICS_TABLE_PREFIX', 'heuristics_'),

    /*
    |--------------------------------------------------------------------------
    | Migrations
    |--------------------------------------------------------------------------
    |
    | Whether the package should auto-load its bundled migrations. Set to
    | false if you publish them with `php artisan vendor:publish
    | --tag=heuristics-migrations` and manage them yourself.
    |
    */
    'migrations' => env('HEURISTICS_MIGRATIONS', true),

    /*
    |--------------------------------------------------------------------------
    | Ingestion routes
    |--------------------------------------------------------------------------
    |
    | The collector posts batched events to `{prefix}/collect` and pixel
    | liveness beacons to `{prefix}/pixel`. These are cross-origin clients,
    | so the host MUST exempt the prefix from CSRF (VerifyCsrfToken $except)
    | or mount them on a stateless `api` middleware group. The throttle
    | limiter name below is registered by the service provider.
    |
    */
    'routes' => [
        'enabled' => env('HEURISTICS_ROUTES', true),
        'prefix' => env('HEURISTICS_ROUTE_PREFIX', 'heuristics'),
        'middleware' => ['api'],
        'throttle' => env('HEURISTICS_THROTTLE', '120,1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Event retention
    |--------------------------------------------------------------------------
    |
    | Days to keep raw events / pixel pings. Pruning is the host's job (call
    | the models' ::query()->where('occurred_at', '<', ...)->delete()); this
    | value is exposed so a scheduled prune can read it.
    |
    */
    'retention_days' => (int) env('HEURISTICS_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Heatmap aggregation
    |--------------------------------------------------------------------------
    |
    | Pointer/click events are bucketed into a normalised grid of
    | `grid_size` x `grid_size` cells per (site_key, path). Coordinates are
    | normalised against each event's viewport (vw/vh) so heatmaps compose
    | across screen sizes.
    |
    */
    'heatmap' => [
        'grid_size' => (int) env('HEURISTICS_HEATMAP_GRID', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pixel verification scheduler
    |--------------------------------------------------------------------------
    |
    | The `heuristics:verify-pixels` command re-fetches every registered
    | site and runs the shared `data-fancy-badge` detection. Run it twice a
    | day. Example schedule (bootstrap/app.php ->withSchedule):
    |
    |   $schedule->command('heuristics:verify-pixels')->cron(
    |       config('heuristics.verify.cron')
    |   );
    |
    */
    'verify' => [
        'cron' => env('HEURISTICS_VERIFY_CRON', '0 3,15 * * *'),
        'timeout' => (int) env('HEURISTICS_VERIFY_TIMEOUT', 15),
        'user_agent' => env('HEURISTICS_VERIFY_UA', 'FancyHeuristics-PixelVerifier/1.0'),
    ],
];
