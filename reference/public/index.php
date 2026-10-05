<?php

declare(strict_types=1);

use Msgpit\Api\Api;
use Msgpit\Api\Stream;
use Msgpit\Core\DlrDispatcher;
use Msgpit\Core\Docs;
use Msgpit\Core\ProviderRegistry;
use Msgpit\Core\Router;
use Msgpit\Core\Storage;
use Msgpit\Http\Request;
use Msgpit\Http\Response;

require dirname(__DIR__) . '/bootstrap.php';

$root = dirname(__DIR__);
$request = Request::fromGlobals();

// Serve assets ourselves rather than returning false to the built-in server, so the source
// mounted during development is never served from the browser cache.
if ($request->path !== '/' && is_file(__DIR__ . $request->path)) {
    $types = ['css' => 'text/css', 'js' => 'text/javascript', 'html' => 'text/html', 'svg' => 'image/svg+xml'];
    $extension = strtolower(pathinfo($request->path, PATHINFO_EXTENSION));

    header('Content-Type: ' . ($types[$extension] ?? 'application/octet-stream') . '; charset=utf-8');
    header('Cache-Control: no-store');
    readfile(__DIR__ . $request->path);

    return;
}

$env = static fn (string $name, string $default): string => is_string($value = getenv($name)) && $value !== ''
    ? $value
    : $default;

$storage = Storage::open(
    $env('MSGPIT_DB', '/data/msgpit.sqlite'),
    (int) $env('MSGPIT_MAX_MESSAGES', '1000'),
);

$enabled = array_values(array_filter(array_map('trim', explode(',', $env('MSGPIT_PROVIDERS', '')))));

/** @var list<class-string> $classes */
$classes = require $root . '/providers.php';
$registry = ProviderRegistry::fromClasses($classes, $enabled);

$version = $env('MSGPIT_VERSION', 'dev');

if ($request->path === '/healthz') {
    Response::json(['status' => 'ok', 'version' => $version])->send();

    return;
}

if ($request->path === '/api/stream') {
    (new Stream($storage))->send($request);

    return;
}

if (str_starts_with($request->path, '/api')) {
    ((new Api($storage, $registry, new DlrDispatcher($storage), new Docs($root . '/docs'), $version))->handle($request)
        ?? Response::json(['error' => 'Not found.'], 404))->send();

    return;
}

$response = (new Router($registry, $storage))->handle($request);

if ($response !== null) {
    $response->send();

    return;
}

if ($request->path === '/') {
    header('Content-Type: text/html; charset=utf-8');
    readfile($root . '/public/ui/index.html');

    return;
}

Response::json(['error' => 'Not found.'], 404)->send();
