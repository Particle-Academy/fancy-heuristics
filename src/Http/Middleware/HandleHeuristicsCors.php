<?php

declare(strict_types=1);

namespace FancyHeuristics\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CORS for the Fancy Heuristics ingestion endpoints.
 *
 * The collector + pixel beacons are posted by browsers on OTHER origins — every
 * site that embeds the Fancy Pixel beacons back here — so these routes only ever
 * receive cross-origin traffic and MUST answer the preflight (OPTIONS) and carry
 * Access-Control-* headers. This middleware is applied automatically to the
 * ingestion route group when `heuristics.routes.cors.enabled` is true (the
 * default), so a fresh install works cross-origin without the host hand-rolling
 * a `config/cors.php` entry.
 *
 * Origins: with `allowed_origins => ['*']` (default) it replies `*` — correct for
 * anonymous, credential-free telemetry. With a concrete allow-list it echoes the
 * request Origin only when it matches (and sets `Vary: Origin`), so a host can
 * restrict who may beacon without touching Laravel's global CORS config.
 *
 * OPTIONS preflight is short-circuited here (before the throttle limiter) so
 * frequent preflights are neither rate-limited nor dispatched to a controller.
 */
class HandleHeuristicsCors
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $this->resolveOrigin($request);

        $response = $request->isMethod('OPTIONS')
            ? new Response('', 204)
            : $next($request);

        if ($origin === null) {
            // Origin not allowed by a concrete allow-list — no CORS headers, so
            // the browser blocks it. (Never reached with the '*' default.)
            return $response;
        }

        $response->headers->set('Access-Control-Allow-Origin', $origin);
        if ($origin !== '*') {
            $response->headers->set('Vary', 'Origin', false);
        }
        $response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
        $requested = $request->headers->get('Access-Control-Request-Headers');
        $response->headers->set('Access-Control-Allow-Headers', $requested ?: 'Content-Type');
        $response->headers->set('Access-Control-Max-Age', (string) $this->maxAge());

        return $response;
    }

    /**
     * The value for Access-Control-Allow-Origin: '*' for a wildcard allow-list,
     * the echoed request Origin when it is explicitly allowed, or null when it
     * is not (no header → the browser blocks the cross-origin response).
     */
    private function resolveOrigin(Request $request): ?string
    {
        /** @var list<string> $allowed */
        $allowed = (array) config('heuristics.routes.cors.allowed_origins', ['*']);

        if (in_array('*', $allowed, true)) {
            return '*';
        }

        $origin = $request->headers->get('Origin');

        return ($origin !== null && in_array($origin, $allowed, true)) ? $origin : null;
    }

    private function maxAge(): int
    {
        return (int) config('heuristics.routes.cors.max_age', 86400);
    }
}
