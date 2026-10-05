<?php

declare(strict_types=1);

namespace Msgpit\Core;

use Msgpit\Http\Request;
use Msgpit\Http\Response;

/** Dispatches /{provider}/... to the provider that owns the prefix. */
final readonly class Router
{
    public function __construct(
        private ProviderRegistry $registry,
        private Storage $storage,
    ) {}

    public function handle(Request $request): ?Response
    {
        $segments = explode('/', trim($request->path, '/'), 2);
        $provider = $this->registry->get($segments[0]);

        if ($provider === null) {
            return null;
        }

        $path = '/' . ($segments[1] ?? '');

        foreach ($provider->routes() as $route) {
            $params = $route->match($request->method, $path);

            if ($params === null) {
                continue;
            }

            return $this->capture($provider, ($route->handler)($request, $params), $request);
        }

        return null;
    }

    private function capture(Provider $provider, Capture $capture, Request $request): Response
    {
        // Only ask when the provider can act on it: consuming the one-shot scenario for a provider
        // that ignores it would silently throw the toggle away.
        if ($provider instanceof SupportsErrorScenarios) {
            $scenario = $this->scenarioFor($capture);

            // A forced scenario replaces the response and stores nothing: as far as the app is
            // concerned the request never landed.
            if ($scenario !== null) {
                return $provider->errorResponse($scenario);
            }
        }

        if ($capture->messages !== []) {
            $this->storage->store($capture->messages, RawRequest::fromRequest($request));
        }

        return $capture->response;
    }

    private function scenarioFor(Capture $capture): ?Scenario
    {
        $oneShot = $this->storage->consumeScenario();

        if ($oneShot !== null) {
            return $oneShot;
        }

        foreach ($capture->messages as $message) {
            $scenario = Scenario::forRecipient($message->to);

            if ($scenario !== null) {
                return $scenario;
            }
        }

        return null;
    }
}
