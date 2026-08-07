<?php

declare(strict_types=1);

namespace Pandora\Memory\Enums;

/**
 * What kind of thing is remembered.
 *
 * Type is not visibility -- scope is. Type governs how a memory is presented
 * to a model and how aggressively it expires: a `Working` note is scaffolding
 * for one task, a `UserFact` is a claim about a person that outlives every
 * conversation it was learnt in.
 */
enum MemoryType: string
{
    /** A claim about a person. The type that needs curation most. */
    case UserFact = 'user_fact';

    /** Something the agent concluded and chose to keep. */
    case AgentCurated = 'agent_curated';

    /** A conversation compressed. Produced by summarisation, never by hand. */
    case Summary = 'summary';

    /** A document or excerpt kept for retrieval rather than for truth. */
    case SemanticDoc = 'semantic_doc';

    /** Something that happened, with a time. */
    case Episodic = 'episodic';

    /** Scratch space for a task in flight. Expected to expire. */
    case Working = 'working';

    /**
     * The default lifetime in seconds, or null for "until forgotten".
     *
     * Working memory that never expires is a leak of a different kind: the
     * retrieval set grows without bound and the useful facts sink.
     */
    public function defaultTtlSeconds(): ?int
    {
        return match ($this) {
            self::Working => 60 * 60 * 24,
            self::Episodic => 60 * 60 * 24 * 90,
            self::UserFact, self::AgentCurated, self::Summary, self::SemanticDoc => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::UserFact => 'Fact about a person',
            self::AgentCurated => 'Agent-curated',
            self::Summary => 'Summary',
            self::SemanticDoc => 'Document',
            self::Episodic => 'Episode',
            self::Working => 'Working note',
        };
    }
}
