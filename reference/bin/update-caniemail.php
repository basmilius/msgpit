<?php

declare(strict_types=1);

/**
 * Refreshes data/caniemail.json from caniemail.com.
 *
 * The data is bundled rather than fetched at runtime: msgpit is a local tool that has to work
 * offline, and a compatibility score that silently disappears when the network is down is worse
 * than one that is a few weeks old. Run this now and then and commit the result.
 *
 * Data by Rémi Parmentier, MIT licensed. https://www.caniemail.com
 */

$source = 'https://www.caniemail.com/api/data.json';
$target = dirname(__DIR__) . '/data/caniemail.json';

fwrite(STDERR, "Fetching {$source}\n");

$raw = @file_get_contents($source, false, stream_context_create(['http' => ['timeout' => 30]]));

if ($raw === false) {
    fwrite(STDERR, "Could not fetch the data.\n");

    exit(1);
}

$decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

if (!is_array($decoded) || !is_array($decoded['data'] ?? null)) {
    fwrite(STDERR, "Unexpected shape.\n");

    exit(1);
}

// Only what the check itself needs: the prose, urls and test dates are for the website.
$features = [];

foreach ($decoded['data'] as $feature) {
    if (!is_array($feature) || !is_array($feature['stats'] ?? null)) {
        continue;
    }

    $features[] = array_filter([
        'slug' => $feature['slug'] ?? '',
        'title' => $feature['title'] ?? '',
        'category' => $feature['category'] ?? '',
        'stats' => $feature['stats'],
    ], static fn (mixed $value): bool => $value !== null && $value !== '');
}

$slim = [
    'source' => 'https://www.caniemail.com',
    'license' => 'MIT, Rémi Parmentier',
    'last_update_date' => $decoded['last_update_date'] ?? null,
    'nicenames' => $decoded['nicenames'] ?? [],
    'data' => $features,
];

file_put_contents($target, json_encode($slim, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

printf("Wrote %s: %d features, %.0f kB, last updated %s\n", $target, count($features), filesize($target) / 1024, $slim['last_update_date'] ?? '?');
