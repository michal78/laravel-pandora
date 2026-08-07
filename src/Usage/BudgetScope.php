<?php

declare(strict_types=1);

namespace Pandora\Usage;

/**
 * The scopes a budget can be drawn around.
 *
 * Checked narrowest first, so the message names the limit closest to the
 * person who can do something about it. "This conversation has spent its
 * budget" is actionable; "the deployment has spent its budget" is a support
 * ticket.
 */
enum BudgetScope: string
{
    case Run = 'run';
    case Conversation = 'conversation';
    case Agent = 'agent';
    case Tenant = 'tenant';
    case Global = 'global';

    public function label(): string
    {
        return match ($this) {
            self::Run => 'this run',
            self::Conversation => 'this conversation',
            self::Agent => 'this agent',
            self::Tenant => 'this tenant',
            self::Global => 'this deployment',
        };
    }

    /**
     * Narrowest first.
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [self::Run, self::Conversation, self::Agent, self::Tenant, self::Global];
    }
}
