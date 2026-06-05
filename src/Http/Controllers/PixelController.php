<?php

declare(strict_types=1);

namespace FancyHeuristics\Http\Controllers;

use FancyHeuristics\HeuristicsManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pixel liveness + visibility beacon endpoint (POST {prefix}/pixel).
 *
 * The embedded pixel POSTs here on mount and on visibility change. We hash
 * the client IP (never store it raw) and capture the user agent for liveness
 * debugging. Cross-origin: host must exempt from CSRF / use `api` middleware.
 */
class PixelController
{
    public function __invoke(Request $request, HeuristicsManager $heuristics): JsonResponse
    {
        $validated = $request->validate([
            'siteKey' => ['required', 'string'],
            'style' => ['required', 'string'],
            'mode' => ['required', 'string'],
            'visible' => ['required', 'boolean'],
            'path' => ['required', 'string'],
            'ts' => ['nullable', 'numeric'],
        ]);

        $ping = $heuristics->ping([
            ...$validated,
            'ua' => substr((string) $request->userAgent(), 0, 255),
            'ipHash' => $request->ip() !== null ? hash('sha256', (string) $request->ip()) : null,
        ]);

        return new JsonResponse([
            'ok' => true,
            'id' => $ping->id,
        ], 202);
    }
}
