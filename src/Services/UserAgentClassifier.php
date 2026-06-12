<?php

declare(strict_types=1);

namespace FancyHeuristics\Services;

/**
 * Self-contained, regex-only User-Agent classifier — NO third-party
 * UA-parser dependency (the package ships zero runtime deps). Buckets a raw
 * UA string into the coarse device / os / browser categories the GA-parity
 * audience report needs. It deliberately makes no attempt at version or exact
 * model fidelity: just the family.
 *
 *   device  ∈ mobile | tablet | desktop
 *   os      ∈ Windows | macOS | iOS | Android | Linux | Other
 *   browser ∈ Chrome | Safari | Firefox | Edge | Other
 *
 * Order matters: the more specific token wins (Edge before Chrome, since Edge
 * UAs also contain "Chrome"; iPad/Android tablet before generic mobile).
 */
class UserAgentClassifier
{
    /**
     * Classify a raw User-Agent string.
     *
     * @return array{device: string, os: string, browser: string}
     */
    public function classify(?string $ua): array
    {
        $ua = (string) $ua;

        return [
            'device' => $this->device($ua),
            'os' => $this->os($ua),
            'browser' => $this->browser($ua),
        ];
    }

    /**
     * mobile | tablet | desktop.
     */
    protected function device(string $ua): string
    {
        if ($ua === '') {
            return 'desktop';
        }

        // Tablets first — they often also match the generic "Mobile" token.
        // iPad, Android tablets (Android without the "Mobile" token), and the
        // common "Tablet" / Kindle / PlayBook markers.
        if (preg_match('/iPad|Tablet|PlayBook|Silk|Kindle/i', $ua)) {
            return 'tablet';
        }

        if (preg_match('/Android/i', $ua) && ! preg_match('/Mobile/i', $ua)) {
            return 'tablet';
        }

        if (preg_match('/Mobi|iPhone|iPod|Android.*Mobile|Windows Phone|IEMobile|BlackBerry|Opera Mini/i', $ua)) {
            return 'mobile';
        }

        return 'desktop';
    }

    /**
     * Windows | macOS | iOS | Android | Linux | Other.
     */
    protected function os(string $ua): string
    {
        // iOS before macOS (iPhone/iPad UAs also contain "like Mac OS X").
        if (preg_match('/iPhone|iPad|iPod/i', $ua)) {
            return 'iOS';
        }

        // Android before Linux (Android UAs also contain "Linux").
        if (preg_match('/Android/i', $ua)) {
            return 'Android';
        }

        if (preg_match('/Windows/i', $ua)) {
            return 'Windows';
        }

        if (preg_match('/Macintosh|Mac OS X/i', $ua)) {
            return 'macOS';
        }

        if (preg_match('/Linux|X11|CrOS/i', $ua)) {
            return 'Linux';
        }

        return 'Other';
    }

    /**
     * Chrome | Safari | Firefox | Edge | Other.
     */
    protected function browser(string $ua): string
    {
        // Edge first — its UA also carries "Chrome" and "Safari".
        if (preg_match('/Edg(e|A|iOS)?\//i', $ua)) {
            return 'Edge';
        }

        if (preg_match('/Firefox\/|FxiOS\//i', $ua)) {
            return 'Firefox';
        }

        // Chrome (and Chromium-based: CriOS, plus most Android browsers).
        // Must come before Safari since Chrome UAs also contain "Safari".
        if (preg_match('/Chrome\/|CriOS\/|Chromium\//i', $ua)) {
            return 'Chrome';
        }

        // Genuine Safari: has "Safari" and the "Version/" token, no Chrome.
        if (preg_match('/Safari\//i', $ua) && preg_match('/Version\//i', $ua)) {
            return 'Safari';
        }

        return 'Other';
    }
}
