<?php

declare(strict_types=1);

namespace FancyHeuristics\Http\Controllers;

use FancyHeuristics\HeuristicsManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ingestion endpoint for batched interaction events (POST {prefix}/collect).
 *
 * Cross-origin clients flush here via navigator.sendBeacon, so the host must
 * exempt this route from CSRF or mount it on stateless `api` middleware.
 */
class CollectController
{
    public function __invoke(Request $request, HeuristicsManager $heuristics): JsonResponse
    {
        $payload = $request->all();

        $saved = $heuristics->collect($payload, $request->userAgent());

        return new JsonResponse([
            'ok' => true,
            'accepted' => $saved->count(),
        ], 202);
    }
}
