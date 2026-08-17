<?php

declare(strict_types=1);

namespace Pandora\Tools\Enums;

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
     * Whether a deployment's configuration requires approval at this level.
     *
     * Reads `pandora.approvals.required_for`, which is what the gatekeeper
     * actually consults. It previously hard-coded high and critical, matching
     * the shipped default and nothing else -- so a deployment that narrowed
     * the list to `critical` had `pandora:tool:list` print "required" beside
     * high-risk tools that would run without a human. A control surface that
     * disagrees with the control is worse than no control surface: it is read
     * by exactly the person deciding whether the configuration is safe.
     *
     * Found while auditing T1, by removing a mitigation and watching nothing
     * fail -- this method looked like the control and was never on the path.
     *
     * A policy may still demand approval for a lower level; it may never
     * silently waive it for a higher one without an explicit `allow` outcome.
     */
    public function requiresApprovalByDefault(): bool
    {
        /** @var list<string> $levels */
        $levels = config('pandora.approvals.required_for', ['high', 'critical']);

        return in_array($this->value, $levels, true);
    }

    /**
     * Whether using this tool changes something.
     *
     * Derived from risk rather than declared separately, because the two would
     * drift and the disagreement would always be discovered the hard way. The
     * documented definitions already say it: `low` reads, or affects only the
     * run itself; everything above it writes, spends or is visible outside.
     *
     * This is what an `observe_only` autonomy level actually forbids.
     */
    public function isMutating(): bool
    {
        return $this !== self::Low;
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
