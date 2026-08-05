<?php

declare(strict_types=1);

namespace Pandora\Pandora\Contracts;

use Pandora\Pandora\Context\ContextRequest;
use Pandora\Pandora\Context\ContextSection;

/**
 * Supplies one section of the context assembled for a model request.
 *
 * Providers run in the order configured and share the agent's context token
 * budget. A provider that cannot fit is dropped, and the omission is recorded
 * on the run trace -- context is never silently truncated.
 *
 * Providers MUST NOT serialise sensitive model attributes. Allowlist the
 * attributes you expose.
 */
interface ContextProvider
{
    /**
     * A stable identifier used in the run trace, e.g. 'recent_messages'.
     */
    public function key(): string;

    public function provide(ContextRequest $request): ?ContextSection;
}
