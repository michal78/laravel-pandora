<?php

declare(strict_types=1);

namespace Pandora\Pandora\Tools\Policies;

use Pandora\Pandora\Contracts\ToolPolicy;
use Pandora\Pandora\Tools\PolicyDecision;
use Pandora\Pandora\Tools\Tool;
use Pandora\Pandora\Tools\ToolContext;
use Pandora\Pandora\Tools\ToolInput;

/**
 * The default policy: read the agent's `approval_policy`, and otherwise raise
 * no objection.
 *
 * Deliberately dull. The interesting decisions -- clamping a refund, forcing a
 * tenant filter, refusing a tool outside business hours -- belong to the host
 * application, which is why this is an interface with a boring default rather
 * than a rules engine nobody asked for.
 *
 * Raising no objection is not the same as approving: a `high` risk tool still
 * pauses for a human, because that floor is the gatekeeper's and only an
 * explicit `allowWithoutApproval()` lowers it.
 */
final class RiskBasedToolPolicy implements ToolPolicy
{
    public function evaluate(Tool $tool, ToolInput $input, ToolContext $context): PolicyDecision
    {
        $policy = $context->agent->approval_policy ?? [];

        if ($this->lists($policy, 'deny', $tool)) {
            return PolicyDecision::deny(
                "Agent [{$context->agent->slug}] is configured to refuse [{$tool->name()}].",
            );
        }

        if ($this->lists($policy, 'require_approval', $tool)) {
            return PolicyDecision::requireApproval(
                "Agent [{$context->agent->slug}] requires approval for [{$tool->name()}].",
            );
        }

        if ($this->lists($policy, 'require_confirmation', $tool)) {
            return PolicyDecision::requireConfirmation(
                "Agent [{$context->agent->slug}] asks you to confirm [{$tool->name()}].",
            );
        }

        if ($this->lists($policy, 'auto_approve', $tool)) {
            return PolicyDecision::allowWithoutApproval(
                "Agent [{$context->agent->slug}] pre-approves [{$tool->name()}].",
            );
        }

        return PolicyDecision::allow();
    }

    /**
     * @param array<string, mixed> $policy
     */
    private function lists(array $policy, string $key, Tool $tool): bool
    {
        $entries = $policy[$key] ?? [];

        if (! is_array($entries)) {
            return false;
        }

        foreach ($entries as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            if (str_starts_with($entry, 'group:')) {
                if ($tool->group() === substr($entry, 6)) {
                    return true;
                }

                continue;
            }

            if ($entry === $tool->name() || $entry === $tool->name().'@'.$tool->version()) {
                return true;
            }
        }

        return false;
    }
}
