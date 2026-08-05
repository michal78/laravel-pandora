<?php

declare(strict_types=1);

namespace Pandora\Pandora\Messages\Enums;

enum MessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
    case System = 'system';
    case Tool = 'tool';
    case Event = 'event';

    public function isVisibleInChat(): bool
    {
        return $this !== self::System;
    }
}
