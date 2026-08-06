<?php

declare(strict_types=1);

namespace Pandora\Pandora\Memory\Enums;

/**
 * Where a memory came from.
 *
 * Provenance is not decoration. "The agent inferred this" and "the user typed
 * this" are different claims about the same sentence, and a person reviewing
 * what an assistant believes about them needs to be able to tell which is
 * which.
 */
enum MemorySource: string
{
    /** A person said it, in as many words. */
    case User = 'user';

    /** An agent concluded it during a run. Carries `source_run_id`. */
    case Agent = 'agent';

    /** Produced by summarisation. */
    case Summariser = 'summariser';

    /** Written by an operator in the control center. */
    case Operator = 'operator';

    /** Loaded from a versioned export. Never executes anything. */
    case Import = 'import';

    public function label(): string
    {
        return match ($this) {
            self::User => 'Stated by the user',
            self::Agent => 'Inferred by the agent',
            self::Summariser => 'Summarised',
            self::Operator => 'Entered by an operator',
            self::Import => 'Imported',
        };
    }
}
