<?php

declare(strict_types=1);

namespace Pandora\Pandora\Tools\Enums;

/**
 * Which layer decided a tool call's fate.
 *
 * Recorded on every decision so an operator reading a trace can see not just
 * that a call was refused but which rule refused it — the difference between
 * "the agent may not do that" and "you may not do that".
 */
enum AuthorizationLayer: string
{
    case Registry = 'registry';
    case Agent = 'agent';
    case Tenant = 'tenant';
    case Autonomy = 'autonomy';
    case Validation = 'validation';
    case Policy = 'policy';
    case Tool = 'tool';
    case Budget = 'budget';

    public function label(): string
    {
        return match ($this) {
            self::Registry => 'Tool registry',
            self::Agent => 'Agent allowlist',
            self::Tenant => 'Tenant restriction',
            self::Autonomy => 'Autonomy level',
            self::Validation => 'Argument validation',
            self::Policy => 'Tool policy',
            self::Tool => 'Tool authorization',
            self::Budget => 'Run budget',
        };
    }
}
