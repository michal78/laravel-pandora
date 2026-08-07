<?php

declare(strict_types=1);

namespace Pandora\Providers\Routing;

/**
 * Why a model was chosen.
 *
 * Recorded on the run step, because "why is this run using gpt-4o-mini?" is a
 * question an operator asks at the worst possible moment, and the answer
 * should be readable rather than reconstructed.
 *
 * The order of the cases IS the precedence order (ADR-0006).
 */
enum RoutingSource: string
{
    case Explicit = 'explicit';
    case Run = 'run';
    case Conversation = 'conversation';
    case Agent = 'agent';
    case Config = 'config';

    /** Not a precedence level: where the chain went after a failure. */
    case Fallback = 'fallback';

    public function label(): string
    {
        return match ($this) {
            self::Explicit => 'Explicitly requested',
            self::Run => 'Run override',
            self::Conversation => 'Conversation override',
            self::Agent => 'Agent default',
            self::Config => 'Configured default',
            self::Fallback => 'Fallback after failure',
        };
    }
}
