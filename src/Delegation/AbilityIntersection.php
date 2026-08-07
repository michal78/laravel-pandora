<?php

declare(strict_types=1);

namespace Pandora\Delegation;

use Illuminate\Contracts\Config\Repository as Config;
use Pandora\Agents\Agent;
use Pandora\Runs\Run;
use Pandora\Tools\Tool;
use Pandora\Tools\ToolReference;
use Pandora\Tools\ToolRegistry;

/**
 * What a child run is allowed to do: the OVERLAP of what the parent run could
 * do and what the child agent is configured to do. Never the union, and never
 * the child agent's list on its own.
 *
 * The reasoning is short and it is the reason this class exists at all. Once a
 * run can start another run, the interesting question stops being "may this
 * actor do this?" and becomes "may this actor do this THROUGH something else?"
 * A support agent denied the shell does not need the shell if it can ask an
 * agent that has one. If delegation could ever produce an ability the parent
 * lacked, then every permission boundary in the product is decorative and the
 * way around it is one hop long.
 *
 * Computed ONCE, at delegation time, and frozen on the child run. Recomputing
 * per tool call would invite the two sides to drift -- an operator widening the
 * child agent's allowlist would retroactively widen a delegation the parent
 * authorized under narrower terms -- and would leave a trace that can only
 * answer "why was this allowed?" by re-deriving a history that has since
 * changed underneath it.
 *
 * See docs/architecture/security-model.md, T8.
 */
final class AbilityIntersection
{
    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly Config $config,
    ) {}

    /**
     * The tool names a child of `$parent` running as `$childAgent` may request.
     *
     * Sorted, so two equal intersections are equal strings on a trace and a
     * diff between two child runs means something changed rather than that the
     * registry enumerated in a different order.
     *
     * @return list<string>
     */
    public function between(Run $parent, Agent $parentAgent, Agent $childAgent): array
    {
        $intersection = array_values(array_intersect(
            $this->abilitiesOf($parent, $parentAgent),
            $this->abilitiesOfAgent($childAgent),
        ));

        sort($intersection);

        return $intersection;
    }

    /**
     * The tool names a RUN may request.
     *
     * A run that already carries an intersection is itself a child, and its
     * frozen list is the answer -- which is what makes the property hold at
     * depth. A grandchild intersects against its parent's already-narrowed set,
     * so abilities can only shrink as a tree deepens. Two hops cannot recover
     * what one hop gave up.
     *
     * @return list<string>
     */
    public function abilitiesOf(Run $run, Agent $agent): array
    {
        if ($run->effective_tools !== null) {
            return $run->effective_tools;
        }

        return $this->abilitiesOfAgent($agent);
    }

    /**
     * The tool names an AGENT may request -- authorization layer 2, resolved.
     *
     * Deny beats allow, exactly as the gatekeeper reads it, and the
     * always-available tools are folded in so that a child does not lose the
     * ability to answer a question or report a refusal merely by being one.
     *
     * @return list<string>
     */
    public function abilitiesOfAgent(Agent $agent): array
    {
        /** @var list<string> $always */
        $always = $this->config->get('pandora.tools.always_available', []);

        $granted = array_merge($agent->allowedTools(), $always);
        $denied = $agent->deniedTools();

        $names = array_map(
            static fn (Tool $tool): string => $tool->name(),
            array_filter(
                $this->registry->all(),
                static fn (Tool $tool): bool => ToolReference::matches($tool, $granted)
                    && ! ToolReference::matches($tool, $denied),
            ),
        );

        return array_values(array_unique($names));
    }

    /**
     * Abilities the child agent is configured with and did NOT receive,
     * because the parent did not have them to give.
     *
     * This direction, and not the other one. "The parent held X and the child
     * agent does not do X" is unremarkable -- it is just two agents being
     * different. "The child agent is configured for X and was refused it" is
     * T8 being enforced, and it is the line an operator needs when a delegate
     * behaves as though it were less capable than its own configuration says.
     *
     * Recorded on the delegation trace and audit entry, on ALLOWED delegations
     * as much as refused ones. "The child could not do X" is the question asked
     * after an incident, and answering it from two allowlists and a config file
     * some weeks later is how a wrong answer gets given confidently.
     *
     * @return list<string>
     */
    public function withheld(Run $parent, Agent $parentAgent, Agent $childAgent): array
    {
        $withheld = array_values(array_diff(
            $this->abilitiesOfAgent($childAgent),
            $this->abilitiesOf($parent, $parentAgent),
        ));

        sort($withheld);

        return $withheld;
    }
}
