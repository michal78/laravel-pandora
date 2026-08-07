<?php

declare(strict_types=1);

namespace Pandora\Runs\Enums;

enum RunStepStatus: string
{
    case Started = 'started';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
