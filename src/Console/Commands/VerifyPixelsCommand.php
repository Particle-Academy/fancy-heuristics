<?php

declare(strict_types=1);

namespace FancyHeuristics\Console\Commands;

use FancyHeuristics\Models\HeuristicsSite;
use FancyHeuristics\Services\PixelVerifier;
use Illuminate\Console\Command;

/**
 * Re-poll every registered site and verify its Fancy UI pixel is still
 * present + visible. Intended to run twice daily (see config heuristics.verify.cron).
 *
 * Each site that passes fires PixelVerificationPassed; each that misses fires
 * PixelVerificationFailed — hosts listen to toggle a listing's visibility.
 */
class VerifyPixelsCommand extends Command
{
    protected $signature = 'heuristics:verify-pixels
                            {--site= : Limit verification to a single site_key}';

    protected $description = 'Re-poll registered sites and verify the Fancy UI pixel is present';

    public function handle(PixelVerifier $verifier): int
    {
        $query = HeuristicsSite::query();

        if ($site = $this->option('site')) {
            $query->where('site_key', $site);
        }

        $sites = $query->get();

        if ($sites->isEmpty()) {
            $this->info('No sites registered for verification.');

            return self::SUCCESS;
        }

        $passed = 0;
        $failed = 0;

        foreach ($sites as $site) {
            $ok = $verifier->verify($site);

            if ($ok) {
                $passed++;
                $this->line("  <info>✓</info> {$site->site_key} ({$site->url})");
            } else {
                $failed++;
                $this->line("  <error>✗</error> {$site->site_key} ({$site->url}) — {$site->pixel_status}");
            }
        }

        $this->info("Verified {$sites->count()} site(s): {$passed} passed, {$failed} failed.");

        return self::SUCCESS;
    }
}
