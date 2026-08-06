<?php

declare(strict_types=1);

namespace Pandora\Pandora\Context;

use Illuminate\Contracts\Container\Container;
use Pandora\Pandora\Contracts\ContextProvider;
use Pandora\Pandora\Providers\Data\ChatMessage;
use Pandora\Pandora\Support\Redactor;

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

            $section = $this->redact($section);

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

    /**
     * Strip credential-shaped strings from a section before it is sent.
     *
     * Belt and braces, and knowingly so: the real defence is providers not
     * putting secrets in context, and `AttributeAllowlist` is what makes that
     * enforceable. This catches what neither can -- a bearer token a user
     * pasted into a message, or one echoed back inside a tool result, which
     * arrives as ordinary conversation text and would otherwise be forwarded
     * to a third party and stored in their logs.
     *
     * Applied after the budget check on purpose. Redaction only ever shortens
     * a section, so checking first is the conservative order; re-estimating
     * afterwards would let a section that was refused sneak back in at a
     * smaller size, which makes the trace disagree with what was sent.
     */
    private function redact(ContextSection $section): ContextSection
    {
        $redactor = $this->container->make(Redactor::class);

        $messages = array_map(
            static fn (ChatMessage $message): ChatMessage => $message->withContent(
                $redactor->redactText($message->content),
            ),
            $section->messages,
        );

        return new ContextSection($section->key, $messages, $section->estimatedTokens);
    }
}
