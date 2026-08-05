<?php

declare(strict_types=1);

namespace Pandora\Pandora\Context;

use Illuminate\Contracts\Container\Container;
use Pandora\Pandora\Contracts\ContextProvider;

/**
 * Runs the registered context providers in order, within the agent's token
 * budget.
 *
 * Providers are ordered by configuration, not by priority negotiation: an
 * explicit list is easier to reason about than an implicit ranking, and the
 * order is exactly what appears on the trace.
 */
final class ContextBuilder
{
    /** @var list<class-string<ContextProvider>> */
    private array $providers;

    /**
     * @param list<class-string<ContextProvider>> $providers
     */
    public function __construct(
        private readonly Container $container,
        array $providers = [],
    ) {
        $this->providers = $providers;
    }

    /**
     * @param class-string<ContextProvider> $provider
     */
    public function register(string $provider): void
    {
        if (! in_array($provider, $this->providers, true)) {
            $this->providers[] = $provider;
        }
    }

    public function build(ContextRequest $request): BuiltContext
    {
        $messages = [];
        $included = [];
        $omitted = [];
        $used = 0;

        foreach ($this->providers as $providerClass) {
            /** @var ContextProvider $provider */
            $provider = $this->container->make($providerClass);

            $section = $provider->provide($request);

            if ($section === null) {
                $omitted[] = ['key' => $provider->key(), 'reason' => 'no_content'];

                continue;
            }

            if ($used + $section->estimatedTokens > $request->tokenBudget) {
                $omitted[] = ['key' => $section->key, 'reason' => 'budget_exhausted'];

                continue;
            }

            foreach ($section->messages as $message) {
                $messages[] = $message;
            }

            $used += $section->estimatedTokens;

            $included[] = [
                'key' => $section->key,
                'tokens' => $section->estimatedTokens,
                'messages' => count($section->messages),
            ];
        }

        return new BuiltContext(
            messages: $messages,
            included: $included,
            omitted: $omitted,
            estimatedTokens: $used,
            budget: $request->tokenBudget,
        );
    }
}
