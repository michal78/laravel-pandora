<?php

declare(strict_types=1);

namespace Pandora\Pandora\Messages\Enums;

/**
 * Whether a message is still being written.
 *
 * Persisted so a browser reloading mid-stream can render the partial message
 * from the database and know more is coming -- rather than depending on having
 * received every broadcast.
 */
enum StreamingState: string
{
    case Pending = 'pending';
    case Streaming = 'streaming';
    case Complete = 'complete';
    case Failed = 'failed';

    public function isFinished(): bool
    {
        return in_array($this, [self::Complete, self::Failed], true);
    }
}
