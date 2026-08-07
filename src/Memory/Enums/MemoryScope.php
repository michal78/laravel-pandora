<?php

declare(strict_types=1);

namespace Pandora\Memory\Enums;

/**
 * Who a memory belongs to.
 *
 * The scope is not a label describing a memory -- it is the visibility
 * constraint applied to every retrieval, resolved from the run the retrieval
 * happens in and never from anything the model said. See `ScopeResolver`.
 *
 * `scope_id` carries the identifier the scope needs: the user key, the agent
 * id, the conversation id, the workspace id. `Global` and `Tenant` need none,
 * because the tenant is already on every row.
 */
enum MemoryScope: string
{
    /** Installation-wide. Operator-written only -- never written by an agent. */
    case Global = 'global';

    /** Everything inside one tenant. Null tenant means a single-tenant install. */
    case Tenant = 'tenant';

    /** One person. The scope most likely to hold a fact that must not leak. */
    case User = 'user';

    /** One agent's own curated knowledge, across everyone it talks to. */
    case Agent = 'agent';

    /** One conversation. Dies with it, unless promoted. */
    case Conversation = 'conversation';

    /** Knowledge attached to a workspace rather than to a person. */
    case Workspace = 'workspace';

    /**
     * Whether this scope requires a `scope_id` to mean anything.
     *
     * A `user` memory with no scope id is a memory belonging to everyone,
     * which is precisely the leak this phase exists to prevent -- so it is
     * rejected at write time rather than filtered at read time.
     */
    public function requiresScopeId(): bool
    {
        return match ($this) {
            self::Global, self::Tenant => false,
            self::User, self::Agent, self::Conversation, self::Workspace => true,
        };
    }

    /**
     * Whether an agent may write this scope, as opposed to an operator.
     */
    public function writableByAgent(): bool
    {
        return $this !== self::Global;
    }

    public function label(): string
    {
        return match ($this) {
            self::Global => 'Everywhere',
            self::Tenant => 'This tenant',
            self::User => 'One person',
            self::Agent => 'One agent',
            self::Conversation => 'One conversation',
            self::Workspace => 'One workspace',
        };
    }
}
