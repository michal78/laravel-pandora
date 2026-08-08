<?php

declare(strict_types=1);

namespace Pandora\Channels\Data;

enum InboundOutcome: string
{
    case Accepted = 'accepted';
    case Duplicate = 'duplicate';
    case Unlinked = 'unlinked';
    case LinkCodeIssued = 'link_code_issued';
    case Refused = 'refused';
}
