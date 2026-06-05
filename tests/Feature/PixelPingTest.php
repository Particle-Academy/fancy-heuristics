<?php

declare(strict_types=1);

use FancyHeuristics\Models\HeuristicsPixelPing;

it('persists a pixel ping posted to the pixel endpoint', function () {
    $response = $this->postJson('/heuristics/pixel', [
        'siteKey' => 'showcase',
        'style' => 'badge',
        'mode' => 'floating',
        'visible' => true,
        'path' => '/projects/acme',
        'ts' => 1_717_000_000_000,
    ]);

    $response->assertStatus(202)->assertJson(['ok' => true]);

    $ping = HeuristicsPixelPing::first();
    expect($ping)->not->toBeNull();
    expect($ping->site_key)->toBe('showcase');
    expect($ping->style)->toBe('badge');
    expect($ping->mode)->toBe('floating');
    expect($ping->visible)->toBeTrue();
    expect($ping->path)->toBe('/projects/acme');
});

it('hashes the client IP and never stores it raw', function () {
    $this->postJson('/heuristics/pixel', [
        'siteKey' => 'showcase',
        'style' => 'beacon',
        'mode' => 'placed',
        'visible' => false,
        'path' => '/',
    ])->assertStatus(202);

    $ping = HeuristicsPixelPing::first();
    expect($ping->ip_hash)->not->toBeNull();
    expect(strlen((string) $ping->ip_hash))->toBe(64); // sha256 hex
});

it('rejects a pixel ping missing required fields', function () {
    $this->postJson('/heuristics/pixel', [
        'siteKey' => 'showcase',
    ])->assertStatus(422);
});
