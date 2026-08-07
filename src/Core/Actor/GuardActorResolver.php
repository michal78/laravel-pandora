<?php

declare(strict_types=1);

namespace Pandora\Core\Actor;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Pandora\Contracts\ActorResolver;

/**
 * Resolves the actor from the application's configured guard.
 *
 * Pandora ships no authentication of its own -- this is the whole integration
 * point with the host's existing auth.
 */
final class GuardActorResolver implements ActorResolver
{
    public function __construct(
        private readonly AuthFactory $auth,
        private readonly ?string $guard = null,
    ) {}

    public function current(): ?ActorContext
    {
        $user = $this->auth->guard($this->guard)->user();

        return $user instanceof Authorizable ? ActorContext::forUser($user) : null;
    }

    public function with(?ActorContext $actor, \Closure $callback): mixed
    {
        return app(ActorManager::class)->with($actor, $callback);
    }
}
