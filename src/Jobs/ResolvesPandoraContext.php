<?php

declare(strict_types=1);

namespace Pandora\Jobs;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Pandora\Core\Actor\ActorContext;
use Pandora\Core\Actor\ActorManager;
use Pandora\Core\Tenancy\TenantContext;
use Pandora\Core\Tenancy\TenantManager;

/**
 * Re-establishes tenant and actor context inside a queue worker.
 *
 * A worker has no request and no session, so the tenant and actor must travel
 * ON the job and be re-entered explicitly. Forgetting this is the classic way
 * a queued job silently reads across every tenant -- so jobs carry the ids as
 * constructor properties and always run their body through here.
 */
trait ResolvesPandoraContext
{
    /**
     * @template TReturn
     *
     * @param \Closure(): TReturn $callback
     * @return TReturn
     */
    protected function withPandoraContext(
        TenantManager $tenants,
        ActorManager $actors,
        \Closure $callback,
    ): mixed {
        $tenant = $this->tenantId !== null ? new TenantContext($this->tenantId) : null;

        return $tenants->with($tenant, function () use ($actors, $callback): mixed {
            return $actors->with($this->resolveActor(), $callback);
        });
    }

    /**
     * Rehydrate the actor from the ids carried on the job.
     *
     * The Authorizable itself is deliberately NOT rehydrated here: a tool
     * needing a live user model resolves it at authorization time, so a job
     * payload never carries a serialised user.
     */
    private function resolveActor(): ?ActorContext
    {
        if ($this->actorType === null || $this->actorId === null) {
            return null;
        }

        if ($this->actorType === 'system') {
            return ActorContext::system($this->actorId);
        }

        $class = $this->actorType;

        if (! class_exists($class)) {
            return null;
        }

        /** @var Model $model */
        $model = new $class;

        $user = $model->newQuery()->find($this->actorId);

        return $user instanceof Authorizable
            ? ActorContext::forUser($user)
            : null;
    }

    protected static function queueName(string $key): ?string
    {
        /** @var string|null $queue */
        $queue = config("pandora.queues.{$key}");

        return $queue;
    }

    protected static function queueConnection(): ?string
    {
        /** @var string|null $connection */
        $connection = config('pandora.queues.connection');

        return $connection;
    }
}
