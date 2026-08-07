<?php

declare(strict_types=1);

namespace Pandora\Context;

use Pandora\Agents\Agent;
use Pandora\Conversations\Session;
use Pandora\Runs\Run;

/**
 * What a context provider is given.
 *
 * Carries the SESSION rather than just the conversation, because the session is
 * the isolation boundary: a provider that queries by conversation alone could
 * pull another participant's private context into this run.
 */
final readonly class ContextRequest
{
    public function __construct(
        public Run $run,
        public Agent $agent,
        public Session $session,
        public int $tokenBudget,
    ) {}
}
