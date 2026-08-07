<?php

declare(strict_types=1);

namespace Pandora\Contracts;

use Pandora\Core\Actor\ActorContext;

/**
 * Resolves the actor a run acts on behalf of, for authorization.
 *
 * Usually the authenticated host user; may be a system actor for automations.
 * The actor -- not the agent -- is what tool authorization is checked against.
 */
interface ActorResolver
{
    public function current(): ?ActorContext;

    /**
     * @template TReturn
     *
     * @param \Closure(): TReturn $callback
     * @return TReturn
     */
    public function with(?ActorContext $actor, \Closure $callback): mixed;
}
