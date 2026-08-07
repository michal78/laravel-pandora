<?php

declare(strict_types=1);

namespace Pandora\Contracts;

use Pandora\Exceptions\NoModelAvailable;
use Pandora\Providers\Routing\RoutingDecision;
use Pandora\Providers\Routing\RoutingRequest;

/**
 * Chooses which provider and model a request goes to.
 *
 * v1 ships a deterministic implementation and no optimiser (ADR-0006). This
 * interface is the extension point: a host that wants cost- or latency-aware
 * routing binds its own today, and every hop it chooses is still recorded on
 * the run trace.
 */
interface ModelRouter
{
    /**
     * @throws NoModelAvailable when no candidate survives the constraints
     */
    public function resolve(RoutingRequest $request): RoutingDecision;
}
