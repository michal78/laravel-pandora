<?php

declare(strict_types=1);

namespace Pandora\Channels\Enums;

enum DeliveryStatus: string
{
    /** Accepted inbound, and a run was created. */
    case Received = 'received';

    /**
     * Accepted inbound, and nothing was created.
     *
     * The status an unlinked identity's message gets. A refusal is recorded
     * rather than dropped, because "somebody nobody has linked keeps messaging
     * this agent" is worth being able to see.
     */
    case Refused = 'refused';

    case Sent = 'sent';

    case Failed = 'failed';
}
