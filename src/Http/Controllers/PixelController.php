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
            'ipHash' => $this->hashIp($request->ip()),
        ]);

        return new JsonResponse([
            'ok' => true,
            'id' => $ping->id,
        ], 202);
    }

    /**
     * HMAC-SHA256 of the IP under `heuristics.ip_hash_key` (default: the app
     * key). An unkeyed SHA-256 of an IPv4 address is reversible by trying
     * every address, so with no key at all nothing is stored.
     */
    protected function hashIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        $key = (string) (config('heuristics.ip_hash_key') ?: config('app.key'));

        return $key !== '' ? hash_hmac('sha256', $ip, $key) : null;
    }
}
