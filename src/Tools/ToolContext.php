<?php

declare(strict_types=1);

namespace Pandora\Tools;

use Pandora\Agents\Agent;
use Pandora\Conversations\Session;
use Pandora\Core\Actor\ActorContext;
use Pandora\Runs\Run;

/**
 * Everything a tool may know about the call it is serving.
 *
 * Passed rather than resolved from a container or a facade, so a tool is
 * testable without booting a run, and so nothing can quietly reach for an
 * ambient actor that does not match the one being authorized.
 */
final readonly class ToolContext
{
    public function __construct(
        public Run $run,
        public Agent $agent,
        public Session $session,
        public ?ActorContext $actor,
        public string $toolCallId,
        public int $attempt = 1,
    ) {}

    public function runId(): string
    {
        return (string) $this->run->getKey();
    }

    public function tenantId(): ?string
    {
        return $this->run->tenant_id;
    }

    /**
     * The actor's underlying model, when there is one.
     *
     * A system actor (automation, webhook, delegation) has none, which is why
     * `Tool::authorize()` must treat a null here as a denial rather than as
     * "no restrictions apply".
     */
    public function user(): ?object
    {
        return $this->actor?->authorizable;
    }
}
