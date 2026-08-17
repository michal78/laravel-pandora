<?php

declare(strict_types=1);

namespace Pandora\Tools;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Pandora\Contracts\ToolPolicy;
use Pandora\Exceptions\McpDenied;
use Pandora\Exceptions\ToolInputInvalid;
use Pandora\Mcp\Namespacing;
use Pandora\Mcp\RemoteTool;
use Pandora\Mcp\RemoteToolResolver;
use Pandora\Providers\Data\ToolCall;
use Pandora\Providers\Data\ToolDefinition;
use Pandora\Runs\Enums\AutonomyLevel;
use Pandora\Tools\Enums\AuthorizationLayer;
use Pandora\Tools\Enums\PolicyOutcome;

/**
 * The layers a tool call must clear, in order.
 *
 *   1. registry   — is this tool installed at all?
 *   2. agent      — is it in this agent's allowlist and not its denylist?
 *   3. tenant     — is it permitted for this tenant?
 *   -  autonomy   — is anybody watching this run, and may it change anything?
 *   -  validation — do the model's arguments satisfy the tool's rules?
 *   4. policy     — allow / deny / approve / confirm / modify arguments
 *   5. authorize  — the tool's own check against the ACTING USER
 *
 * All of them must pass, and no layer can widen what an earlier one narrowed.
 * A policy that says `allow` has not authorized anything; it has only declined
 * to object.
 *
 * Validation sits between 3 and 4 so a policy reasons about clean values, and
 * runs again after argument modification so a policy cannot write something
 * past the tool's own rules.
 *
 * The autonomy clamp (Phase 4, ADR-0009) is unnumbered because it applies only
 * to a run nobody is watching. It lives here rather than in `ToolPolicy`
 * precisely because the policy is the layer a host replaces, and a host that
 * bound its own must not silently lose the leash.
 *
 * See docs/architecture/security-model.md §4.
 */
final class ToolGatekeeper
{
    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly ToolPolicy $policy,
        private readonly ValidationFactory $validator,
        private readonly Config $config,
    ) {}

    public function evaluate(ToolCall $call, ToolContext $context): ToolDecision
    {
        // ---- Layer 1: the registry.
        //
        // Split by ORIGIN before anything looks the name up. A namespaced name
        // never reaches the core registry and a core name never reaches the
        // remote resolver, so a remote tool cannot be resolved where a core
        // tool is expected whatever it has called itself (ADR-0014). The
        // separator is reserved -- no core tool may contain it -- so these two
        // worlds cannot overlap by construction rather than by comparison.
        $tool = Namespacing::looksRemote($call->name)
            ? $this->resolveRemote($call, $context)
            : $this->registry->find($call->name);

        if ($tool === null) {
            return ToolDecision::deny(
                $call,
                AuthorizationLayer::Registry,
                "No tool named [{$call->name}] is available.",
            );
        }

        // ---- Layer 2: the agent's own allowlist.
        if (! $this->agentAllows($context, $tool)) {
            return ToolDecision::deny(
                $call,
                AuthorizationLayer::Agent,
                "Agent [{$context->agent->slug}] may not use [{$tool->name()}].",
                $tool,
            );
        }

        // ---- Layer 3: the tenant.
        if (! $this->tenantAllows($context, $tool)) {
            return ToolDecision::deny(
                $call,
                AuthorizationLayer::Tenant,
                "[{$tool->name()}] is not permitted for this tenant.",
                $tool,
            );
        }

        // ---- Autonomy. Only for a run nobody is watching.
        if ($this->autonomyForbids($context, $tool)) {
            return ToolDecision::deny(
                $call,
                AuthorizationLayer::Autonomy,
                sprintf(
                    'This run is autonomous at level [%s] and [%s] changes something. Report instead.',
                    $context->run->autonomy_level?->value ?? '',
                    $tool->name(),
                ),
                $tool,
            );
        }

        // ---- Validation. Model output is untrusted input; nothing reaches a
        //      policy or a tool until it satisfies the declared rules.
        try {
            $input = $tool->validate($call->arguments, $this->validator);
        } catch (ToolInputInvalid $e) {
            return ToolDecision::deny(
                $call,
                AuthorizationLayer::Validation,
                $e->modelMessage(),
                $tool,
            );
        }

        // ---- Layer 4: the deployment's policy.
        $decision = $this->policy->evaluate($tool, $input, $context);
        $diff = null;
        $modification = null;

        if ($decision->outcome === PolicyOutcome::Deny) {
            return ToolDecision::deny(
                $call,
                AuthorizationLayer::Policy,
                $decision->reason ?? 'Refused by policy.',
                $tool,
            );
        }

        if ($decision->outcome === PolicyOutcome::ModifyArguments && $decision->arguments !== null) {
            $diff = ArgumentDiff::between($input->toArray(), $decision->arguments);
            $modification = $decision->reason;

            // Re-validated: a policy may narrow a call, never smuggle a value
            // past the rules the tool declared.
            try {
                $input = $tool->validate($decision->arguments, $this->validator);
            } catch (ToolInputInvalid $e) {
                return ToolDecision::deny(
                    $call,
                    AuthorizationLayer::Policy,
                    'A policy rewrote the arguments into something the tool rejects. '.$e->modelMessage(),
                    $tool,
                );
            }
        }

        // ---- Layer 5: the tool, against the ACTOR. The property that makes an
        //      agent unable to do what the person it acts for could not.
        if (! $tool->authorize($input, $context)) {
            return ToolDecision::deny(
                $call,
                AuthorizationLayer::Tool,
                'You are not authorized to use ['.$tool->name().'].',
                $tool,
            );
        }

        // ---- Approval. Risk sets a floor a policy may raise and may not
        //      lower except by saying `allow` explicitly.
        if ($decision->outcome === PolicyOutcome::RequireApproval) {
            return ToolDecision::requiresApproval(
                $call,
                $tool,
                $input,
                $this->reason($decision->reason ?? 'This tool requires approval.', $modification),
                $diff,
            );
        }

        if ($decision->outcome === PolicyOutcome::RequireConfirmation) {
            return ToolDecision::requiresConfirmation(
                $call,
                $tool,
                $input,
                $this->reason($decision->reason ?? 'This tool requires confirmation.', $modification),
                $diff,
            );
        }

        // An `act_with_approval` run pauses for a human on anything mutating,
        // whatever its risk level and whatever the policy waived. That is the
        // entire meaning of the level, so a policy must not be able to say
        // otherwise -- unlike the risk-based rule below, which it may.
        if ($this->autonomyRequiresApproval($context, $tool)) {
            return ToolDecision::requiresApproval(
                $call,
                $tool,
                $input,
                $this->reason(
                    'This run is autonomous and every action it takes requires approval.',
                    $modification,
                ),
                $diff,
            );
        }

        if (! $decision->waivesApproval && $this->riskRequiresApproval($tool)) {
            return ToolDecision::requiresApproval(
                $call,
                $tool,
                $input,
                $this->reason(
                    sprintf('%s risk tools require approval.', $tool->risk()->label()),
                    $modification,
                ),
                $diff,
            );
        }

        if ($diff !== null && ! $diff->isEmpty()) {
            return ToolDecision::modified(
                $call,
                $tool,
                $input,
                $diff,
                $decision->reason ?? 'Arguments were modified by policy.',
            );
        }

        return ToolDecision::allow($call, $tool, $input);
    }

    /**
     * The tools this agent and tenant may ask for, as a provider sees them.
     *
     * Only the argument-independent layers (1-3) can be applied here: a
     * policy and a tool's authorize() both need a specific call. Advertising
     * a tool is therefore not a promise it will be permitted -- the model is
     * told what it may ASK for, and every request is judged on arrival.
     *
     * @return list<ToolDefinition>
     */
    public function advertise(ToolContext $context): array
    {
        $available = array_values(array_filter(
            $this->registry->all(),
            fn (Tool $tool): bool => $this->agentAllows($context, $tool)
                && $this->tenantAllows($context, $tool),
        ));

        $definitions = $this->registry->describe($available);

        // Remote tools are appended rather than merged into the registry, for
        // the same reason resolution is split: the core registry never holds a
        // namespaced name. An unapproved tool is not here at all -- it is not
        // advertised and then refused, it is never offered, which is what
        // criterion 17 asks for.
        $remote = app(RemoteToolResolver::class)->available($context->agent);

        return [...$definitions, ...$this->registry->describe($remote)];
    }

    /**
     * Why a call paused, including why its arguments were changed.
     *
     * A human asked to approve a modified call must be told about the
     * modification in the same breath, or the diff on the card is the first
     * they hear of it.
     */
    private function reason(string $primary, ?string $modification): string
    {
        return $modification === null
            ? $primary
            : $primary.' Arguments were modified: '.$modification;
    }

    /**
     * Layer 2. An agent with no allowlist can use nothing: implicit access is
     * how a support agent ends up with a shell nobody remembers granting.
     *
     * A DELEGATED run is narrowed twice. Its agent's allowlist is consulted as
     * for any run, and then the intersection frozen on the run at delegation
     * time is applied on top -- because the agent's allowlist alone is exactly
     * the thing the parent was not entitled to hand over. Both must pass, and
     * the intersection can only ever take away: it is computed as a subset of
     * the agent's own abilities, so a run cannot reach a tool by being
     * delegated to that it could not reach by being started directly.
     *
     * This is the point at which T8 stops being a claim and becomes a
     * behaviour. Everything else about delegation -- the allowlist, the depth
     * limit, the cycle check -- decides whether a child run exists. This
     * decides what it can do once it does.
     */
    /**
     * A remote tool, if this agent may have it.
     *
     * Null rather than an exception, so an unapproved remote tool is refused
     * at the registry layer exactly as an unknown tool is -- the model learns
     * that no such tool is available to it, and not whether it exists,
     * whether somebody else may call it, or whether its schema changed. Those
     * are operator facts and they go to the audit trail instead.
     */
    private function resolveRemote(ToolCall $call, ToolContext $context): ?Tool
    {
        try {
            return app(RemoteToolResolver::class)->resolve($call->name, $context->agent);
        } catch (McpDenied) {
            return null;
        }
    }

    private function agentAllows(ToolContext $context, Tool $tool): bool
    {
        // A remote tool's allowlist IS its approval: per agent, per tool, of a
        // specific hash, checked when it was resolved a moment ago. Consulting
        // `allowedTools()` as well would mean granting the same thing twice in
        // two places, and the second one would drift.
        if ($tool instanceof RemoteTool) {
            return true;
        }

        /** @var list<string> $always */
        $always = $this->config->get('pandora.tools.always_available', []);

        if (ToolReference::matches($tool, $context->agent->deniedTools())) {
            return false;
        }

        if (! $this->intersectionAllows($context, $tool)) {
            return false;
        }

        return ToolReference::matches($tool, $context->agent->allowedTools())
            || ToolReference::matches($tool, $always);
    }

    /**
     * The delegation intersection, for a run that carries one.
     *
     * NULL means the run is not delegated and no intersection applies. An empty
     * array is a different fact entirely: a delegation that intersected down to
     * nothing, whose child may call no tools at all. Conflating the two would
     * turn the most restrictive possible delegation into an unrestricted one,
     * which is the failure mode worth being pedantic about.
     *
     * Matched on the tool's name rather than by reference, because the stored
     * list IS resolved names, frozen at delegation time on purpose, so that
     * an operator widening a group afterwards cannot widen a run in flight.
     */
    private function intersectionAllows(ToolContext $context, Tool $tool): bool
    {
        $effective = $context->run->effective_tools;

        if ($effective === null) {
            return true;
        }

        return in_array($tool->name(), $effective, true);
    }

    /**
     * Layer 3. A tenant absent from the configuration is unrestricted; a
     * tenant present may use only what it names.
     */
    private function tenantAllows(ToolContext $context, Tool $tool): bool
    {
        $tenantId = $context->tenantId();

        if ($tenantId === null) {
            return true;
        }

        /** @var array<string, array{allow?: list<string>, deny?: list<string>}> $tenants */
        $tenants = $this->config->get('pandora.tools.tenants', []);

        if (! isset($tenants[$tenantId])) {
            return true;
        }

        $rules = $tenants[$tenantId];

        if (ToolReference::matches($tool, $rules['deny'] ?? [])) {
            return false;
        }

        return ! isset($rules['allow']) || ToolReference::matches($tool, $rules['allow']);
    }

    /**
     * The autonomy clamp -- ADR-0009's leash, at the point it bites.
     *
     * A run with no `autonomy_level` is an interactive one: a human is right
     * there, and the tool policy and approvals are the boundary. A run that
     * carries a level was started by a clock, an event or a remote system, and
     * that level is the ceiling.
     *
     * Deliberately here and not in `ToolPolicy`. A policy is the layer a host
     * REPLACES, and a host that bound its own would silently lose autonomy
     * enforcement -- which is the one guarantee that must not be losable by
     * writing a class. The gatekeeper cannot be replaced.
     *
     * The level maps onto the layers that already exist rather than inventing
     * a parallel mechanism:
     *
     *   observe_only      mutating tools denied outright
     *   suggest           same, but the agent is told to propose instead
     *   act_with_approval mutating tools proceed to the approval machinery
     *   act_within_policy no additional constraint; the policy decides
     */
    private function autonomyForbids(ToolContext $context, Tool $tool): bool
    {
        $level = $context->run->autonomy_level;

        return $level !== null
            && ! $level->allowsMutation()
            && $tool->risk()->isMutating();
    }

    /** The `act_with_approval` half of the same clamp. */
    private function autonomyRequiresApproval(ToolContext $context, Tool $tool): bool
    {
        return $context->run->autonomy_level === AutonomyLevel::ActWithApproval
            && $tool->risk()->isMutating();
    }

    private function riskRequiresApproval(Tool $tool): bool
    {
        /** @var list<string> $levels */
        $levels = $this->config->get('pandora.approvals.required_for', ['high', 'critical']);

        // Kept here rather than delegated to RiskLevel::requiresApprovalByDefault(),
        // because the gatekeeper reads configuration through its injected
        // repository and the enum cannot. The two now consult the same key, and
        // `Security/ApprovalFloorAgreementTest` fails if they ever stop.
        return in_array($tool->risk()->value, $levels, true);
    }
}
