<?php

declare(strict_types=1);

namespace Pandora\Pandora\Tools\Enums;

/**
 * How much damage a tool can do if the model is persuaded to misuse it.
 *
 * Risk is declared by the tool author, because only they know what the code
 * touches. It drives approval requirements, so understating it is the most
 * consequential mistake a tool author can make -- the docs say so.
 */
enum RiskLevel: string
{
    /** Reads non-sensitive data, or affects only the run itself. */
    case Low = 'low';

    /** Writes data the actor already owns, or has a bounded external effect. */
    case Medium = 'medium';

    /** Destructive, financial, or visible outside the application. */
    case High = 'high';

    /** Irreversible, or affecting people other than the actor. */
    case Critical = 'critical';

    /**
     * Whether this level requires human approval by default. A policy may
     * still demand approval for a lower level; it may never silently waive it
     * for a higher one without an explicit `allow` outcome.
     */
    public function requiresApprovalByDefault(): bool
    {
        return $this === self::High || $this === self::Critical;
    }

    public function atLeast(self $other): bool
    {
        return $this->weight() >= $other->weight();
    }

    public function weight(): int
    {
        return match ($this) {
            self::Low => 0,
            self::Medium => 1,
            self::High => 2,
            self::Critical => 3,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low',
            self::Medium => 'Medium',
            self::High => 'High',
            self::Critical => 'Critical',
        };
    }

    /** Semantic colour token; mapped to classes by the UI, not here. */
    public function tone(): string
    {
        return match ($this) {
            self::Low => 'muted',
            self::Medium => 'info',
            self::High => 'warning',
            self::Critical => 'danger',
        };
    }
}
