<?php

declare(strict_types=1);

namespace Pandora\Channels\Enums;

enum DeliveryDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';
}
