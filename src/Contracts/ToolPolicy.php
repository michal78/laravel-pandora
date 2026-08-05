<?php

declare(strict_types=1);

namespace Pandora\Pandora\Contracts;

use Pandora\Pandora\Tools\PolicyDecision;
use Pandora\Pandora\Tools\Tool;
use Pandora\Pandora\Tools\ToolContext;
use Pandora\Pandora\Tools\ToolInput;

/**
 * Authorization layer 4 — the deployment's own rules about tool use.
 *
 * Distinct from `Tool::authorize()` in both subject and author: a policy is
 * written by whoever operates the deployment and speaks about agents, tenants
 * and circumstances; `authorize()` is written by whoever wrote the tool and
 * speaks about the acting user's abilities.
 *
 * A policy can never widen authority. `allow` means "this layer raises no
 * objection", never "skip the remaining checks".
 */
interface ToolPolicy
{
    public function evaluate(Tool $tool, ToolInput $input, ToolContext $context): PolicyDecision;
}
