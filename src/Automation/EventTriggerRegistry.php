<?php

declare(strict_types=1);

namespace Pandora\Automation;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Pandora\Agents\Agent;
use Pandora\Agents\AgentRunner;
use Pandora\Exceptions\AgentNotFound;
use Pandora\Exceptions\PandoraException;
use Pandora\Jobs\RunAutomation;
use Pandora\Runs\Enums\AutonomyLevel;
use Pandora\Runs\Enums\TriggerType;

/**
 * Which Laravel events start a run, and what happens when one fires.
 *
 * Two sources, on purpose:
 *
 *  - **Code**, via `Pandora::on(OrderShipped::class)->run('support')`. Lives
 *    in a service provider, gets reviewed, ships with a deploy.
 *  - **Database**, an automation of trigger type `event`. An operator adds one
 *    at 3am without a release.
 *
 * Listeners are registered only for classes some binding actually names. The
 * obvious alternative -- a wildcard listener on `*` -- would make Pandora a
 * tax on every event the host application dispatches, forever, including all
 * the ones it dispatches in a loop.
 *
 * The database half of that list is cached. It changes when somebody edits an
 * automation, which is rare, and reading it means a query on every boot,
 * which is not.
 */
final class EventTriggerRegistry
{
    public const CACHE_KEY = 'pandora:automation:event-classes';

    /** @var array<string, list<EventBinding>> */
    private array $bindings = [];

    private bool $listening = false;

    /**
     * Classes a listener has already been attached for.
     *
     * Attaching twice is not harmless: the dispatcher would hold two closures
     * and every binding would fire twice, which for an automation means two
     * runs and two bills.
     *
     * @var array<string, true>
     */
    private array $attached = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    /** `Pandora::on(SomeEvent::class)` */
    public function on(string $eventClass): PendingEventTrigger
    {
        return new PendingEventTrigger($this, $eventClass);
    }

    public function add(EventBinding $binding): void
    {
        $this->bindings[$binding->eventClass][] = $binding;

        // A binding declared after listeners were attached still has to work:
        // hosts register these in boot(), and boot order is not something a
        // package gets to insist on.
        if ($this->listening) {
            $this->attach($binding->eventClass);
        }
    }

    /**
     * @return list<EventBinding>
     */
    public function bindingsFor(string $eventClass): array
    {
        return $this->bindings[$eventClass] ?? [];
    }

    /**
     * Every event class anything is bound to -- code first, then the database.
     *
     * @return list<string>
     */
    public function eventClasses(): array
    {
        return array_values(array_unique([
            ...array_keys($this->bindings),
            ...$this->databaseEventClasses(),
        ]));
    }

    /**
     * Attach the listeners.
     *
     * Called once from the service provider's boot. Safe to call again: the
     * dispatcher would otherwise accumulate a duplicate listener per call and
     * fire every binding twice.
     */
    public function listen(): void
    {
        $this->listening = true;

        foreach ($this->eventClasses() as $eventClass) {
            $this->attach($eventClass);
        }
    }

    /**
     * One event fired. Start whatever it is bound to.
     *
     * Nothing here may throw. An agent that cannot be resolved must not take
     * down the business transaction that dispatched the event -- an order does
     * not fail to ship because a reporting agent was renamed.
     */
    public function handle(string $eventClass, object $event): void
    {
        foreach ($this->bindingsFor($eventClass) as $binding) {
            try {
                $this->runBinding($binding, $event);
            } catch (PandoraException) {
                // Deliberately swallowed. See above.
            }
        }

        $this->runDatabaseAutomations($eventClass);
    }

    /** Called when an automation is saved or deleted, so the list is re-read. */
    public function flush(): void
    {
        $this->cache()->forget(self::CACHE_KEY);
    }

    // ------------------------------------------------------------------ inner

    private function attach(string $eventClass): void
    {
        if (isset($this->attached[$eventClass])) {
            return;
        }

        $this->attached[$eventClass] = true;

        /** @var Dispatcher $events */
        $events = $this->container->make(Dispatcher::class);

        $events->listen($eventClass, function (object|string $event, array $payload = []) use ($eventClass): void {
            // Laravel hands a string event name plus a payload array when the
            // event is not an object. Pandora binds to classes, so the object
            // form is the only one that can be mapped.
            $object = is_object($event) ? $event : ($payload[0] ?? null);

            if (is_object($object)) {
                $this->handle($eventClass, $object);
            }
        });
    }

    private function runBinding(EventBinding $binding, object $event): void
    {
        if (! $binding->applies($event)) {
            return;
        }

        /** @var Agent|null $agent */
        $agent = Agent::query()->where('slug', $binding->agentSlug)->first();

        if ($agent === null) {
            throw AgentNotFound::slug($binding->agentSlug);
        }

        if (! $agent->enabled) {
            return;
        }

        $pending = $this->container->make(AgentRunner::class)
            ->agent($agent)
            ->asSystem('event:'.class_basename($event))
            ->triggeredBy(TriggerType::Event)
            ->withContext([
                'event' => ['class' => $binding->eventClass],
                'payload' => $binding->payload($event),
            ]);

        if ($binding->queue !== null) {
            $pending->onQueue($binding->queue);
        }

        $run = $pending->dispatch($binding->instruction($event));

        // Clamped, always. A binding may ask for less than the agent has and
        // never for more, or `Pandora::on()` would be a way around the agent's
        // own configuration.
        $run->forceFill([
            'autonomy_level' => $this->clamp($binding, $agent)->value,
        ])->save();
    }

    private function clamp(EventBinding $binding, Agent $agent): AutonomyLevel
    {
        return ($binding->autonomy ?? $agent->autonomy_level)->narrowerOf($agent->autonomy_level);
    }

    /**
     * The database half: automations of trigger type `event`.
     *
     * These go through `RunAutomation` and get the full occurrence machinery --
     * condition, concurrency, autonomy budget, history. A code binding does
     * not, because it has no row to record against.
     */
    private function runDatabaseAutomations(string $eventClass): void
    {
        /** @var Builder<Automation> $query */
        $query = Automation::acrossAllTenants();

        $automations = $query
            ->where('enabled', true)
            ->where('event_class', $eventClass)
            ->get();

        foreach ($automations as $automation) {
            RunAutomation::dispatch(
                automationId: (string) $automation->getKey(),
                tenantId: $automation->tenant_id,
                // Second resolution, so the same event delivered twice in the
                // same instant by two listeners claims one occurrence.
                occurrence: Carbon::now()->startOfSecond()->toIso8601String(),
                payload: ['event' => ['class' => $eventClass]],
            );
        }
    }

    /**
     * @return list<string>
     */
    private function databaseEventClasses(): array
    {
        try {
            /** @var list<string> $classes */
            $classes = $this->cache()->remember(self::CACHE_KEY, 3600, static function (): array {
                /** @var Builder<Automation> $query */
                $query = Automation::acrossAllTenants();

                return array_values(array_filter($query
                    ->where('enabled', true)
                    ->whereNotNull('event_class')
                    ->distinct()
                    ->pluck('event_class')
                    ->all()));
            });

            return $classes;
        } catch (QueryException) {
            // No table yet: `pandora:install` has not run, or this is the boot
            // during which the migration is about to. Answering "nothing is
            // bound" is correct, and it must NOT be cached -- a fresh install
            // would then ignore every event automation for an hour.
            return [];
        }
    }

    private function cache(): Cache
    {
        /** @var CacheFactory $factory */
        $factory = $this->container->make(CacheFactory::class);

        return $factory->store();
    }
}
