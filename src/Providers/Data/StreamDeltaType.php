<?php

declare(strict_types=1);

namespace Pandora\Providers\Data;

enum StreamDeltaType: string
{
    case Text = 'text';
    case Reasoning = 'reasoning';
    case ToolCall = 'tool_call';
    case Usage = 'usage';
    case Done = 'done';
}
