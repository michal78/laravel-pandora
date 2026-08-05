<?php

declare(strict_types=1);

namespace Pandora\Pandora\Core\Actor;

use Pandora\Pandora\Contracts\ActorResolver;

/**
 * Holds the actor for the current process.
 *
 * Queued jobs re-enter their actor through `with()`; a worker has no session
 * to resolve one from.
 */
final class ActorManager implements ActorResolver
{
    private ?ActorContext $override = null;

    private bool $overridden = false;

    public function __construct(
        private readonly ActorResolver $resolver,
    ) {}

    public function current(): ?ActorContext
    {
        return $this->overridden ? $this->override : $this->resolver->current();
    }

    public function with(?ActorContext $actor, \Closure $callback): mixed
    {
        $previousOverride = $this->override;
        $previouslyOverridden = $this->overridden;

        $this->override = $actor;
        $this->overridden = true;

        try {
            return $callback();
        } finally {
            $this->override = $previousOverride;
            $this->overridden = $previouslyOverridden;
        }
    }
}
