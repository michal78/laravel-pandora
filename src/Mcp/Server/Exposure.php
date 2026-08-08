<?php

declare(strict_types=1);

namespace Pandora\Mcp\Server;

use Pandora\Audit\AuditLogger;
use Pandora\Tools\Tool;
use Pandora\Tools\ToolRegistry;

/**
 * What Pandora serves to somebody else's agent, and what it does not.
 *
 * Two separate questions, and conflating them is how a token becomes a
 * superuser (ADR-0014):
 *
 * - **Exposure** decides what EXISTS on this server. It is an allowlist in
 *   configuration, empty by default, and installing the package exposes
 *   nothing.
 * - **Authorization** decides who may call what exists. That happens per
 *   request, against the actor behind the token, and it is not this class.
 *
 * A caller with a perfectly valid token asking for something not exposed is
 * recorded at `warning`: it is either a misconfiguration somebody should fix
 * or somebody probing, and both are worth seeing.
 */
final readonly class Exposure
{
    public function __construct(
        private ToolRegistry $registry,
        private AuditLogger $audit,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('pandora.mcp.server.enabled', false);
    }

    /**
     * The names an operator has explicitly exposed.
     *
     * @return list<string>
     */
    public function allowlist(): array
    {
        /** @var array<int, mixed> $configured */
        $configured = config('pandora.mcp.server.exposed_tools', []);

        return array_values(array_filter(
            array_map(static fn (mixed $name): string => is_string($name) ? $name : '', $configured),
            static fn (string $name): bool => $name !== '',
        ));
    }

    /**
     * The tools this server will admit to having.
     *
     * @return list<Tool>
     */
    public function tools(): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $exposed = [];

        foreach ($this->allowlist() as $name) {
            $tool = $this->registry->find($name);

            // A name in the allowlist that resolves to nothing is silently
            // absent rather than an error: an operator listing a tool from a
            // package they later removed should not take the server down.
            if ($tool !== null) {
                $exposed[] = $tool;
            }
        }

        return $exposed;
    }

    /**
     * One tool, if it is exposed.
     *
     * Resolved through the allowlist rather than through the registry, so a
     * tool that exists and was not named is indistinguishable from one that
     * does not exist. That is the intended answer: the caller learns what this
     * server serves, not what this installation has.
     */
    public function find(string $name): ?Tool
    {
        foreach ($this->tools() as $tool) {
            if ($tool->name() === $name) {
                return $tool;
            }
        }

        $this->audit->record(
            action: 'mcp.exposure_denied',
            targetType: 'mcp_exposure',
            targetId: $name,
            severity: 'warning',
            metadata: ['tool' => $name],
        );

        return null;
    }
}
