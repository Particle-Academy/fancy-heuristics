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
 *
 * Responds 202 `{ ok, replayed, accepted, rejected, errors: [{index, code}] }`.
 * An invalid envelope is a 422. Submitted values are never echoed.
 */
class CollectController
{
    public function __invoke(Request $request, HeuristicsManager $heuristics): JsonResponse
    {
        $result = $heuristics->ingest($request->all(), $request->userAgent(), public: true);

        return new JsonResponse($result->toResponse(), 202);
    }
}
