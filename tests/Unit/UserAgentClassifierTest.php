<?php

declare(strict_types=1);

use FancyHeuristics\Services\UserAgentClassifier;

beforeEach(function () {
    $this->ua = new UserAgentClassifier;
});

it('classifies a desktop Chrome on Windows', function () {
    $result = $this->ua->classify(
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'
    );

    expect($result)->toMatchArray([
        'device' => 'desktop',
        'os' => 'Windows',
        'browser' => 'Chrome',
    ]);
});

it('classifies an iPhone Safari as mobile / iOS / Safari', function () {
    $result = $this->ua->classify(
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1'
    );

    expect($result)->toMatchArray([
        'device' => 'mobile',
        'os' => 'iOS',
        'browser' => 'Safari',
    ]);
});

it('classifies an iPad as a tablet on iOS', function () {
    $result = $this->ua->classify(
        'Mozilla/5.0 (iPad; CPU OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1'
    );

    expect($result['device'])->toBe('tablet');
    expect($result['os'])->toBe('iOS');
});

it('classifies an Android phone as mobile / Android / Chrome', function () {
    $result = $this->ua->classify(
        'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36'
    );

    expect($result)->toMatchArray([
        'device' => 'mobile',
        'os' => 'Android',
        'browser' => 'Chrome',
    ]);
});

it('classifies an Android tablet (no Mobile token) as a tablet', function () {
    $result = $this->ua->classify(
        'Mozilla/5.0 (Linux; Android 13; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'
    );

    expect($result['device'])->toBe('tablet');
    expect($result['os'])->toBe('Android');
});

it('distinguishes Edge from Chrome', function () {
    $result = $this->ua->classify(
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 Edg/124.0.0.0'
    );

    expect($result['browser'])->toBe('Edge');
});

it('classifies Firefox on macOS', function () {
    $result = $this->ua->classify(
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:125.0) Gecko/20100101 Firefox/125.0'
    );

    expect($result)->toMatchArray([
        'device' => 'desktop',
        'os' => 'macOS',
        'browser' => 'Firefox',
    ]);
});

it('falls back to Other / desktop for an empty or unknown UA', function () {
    expect($this->ua->classify(''))->toMatchArray([
        'device' => 'desktop',
        'os' => 'Other',
        'browser' => 'Other',
    ]);

    expect($this->ua->classify(null)['os'])->toBe('Other');
    expect($this->ua->classify('SomeCustomBot/1.0')['browser'])->toBe('Other');
});
