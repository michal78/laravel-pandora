<?php

declare(strict_types=1);

namespace Pandora\Pandora\Automation\Enums;

/**
 * What an occurrence does when the previous run of the same automation has
 * not finished.
 *
 * The default is `skip`, because the failure mode of `allow` is silent and
 * cumulative: an hourly automation whose run takes ninety minutes accumulates
 * workers until the queue stops moving, and the only symptom is that
 * everything else got slow.
 */
enum ConcurrencyPolicy: string
{
    /** Start anyway. Correct only when runs are genuinely independent. */
    case Allow = 'allow';

    /** Refuse this occurrence, recording it as skipped. */
    case Skip = 'skip';

    /** Cancel the run in flight, then start. For "only the latest matters". */
    case CancelPrevious = 'cancel_previous';

    public function label(): string
    {
        return match ($this) {
            self::Allow => 'Allow overlap',
            self::Skip => 'Skip while running',
            self::CancelPrevious => 'Cancel the previous run',
        };
    }
}
