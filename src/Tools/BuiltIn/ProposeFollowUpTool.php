<?php

declare(strict_types=1);

namespace Pandora\Pandora\Tools\BuiltIn;

use Pandora\Pandora\Audit\AuditLogger;
use Pandora\Pandora\Automation\Enums\ObservationStatus;
use Pandora\Pandora\Automation\Observation;
use Pandora\Pandora\Tools\Enums\RiskLevel;
use Pandora\Pandora\Tools\Tool;
use Pandora\Pandora\Tools\ToolContext;
use Pandora\Pandora\Tools\ToolInput;
use Pandora\Pandora\Tools\ToolResult;

/**
 * The goal queue, from the agent's side.
 *
 * An agent that notices the weekly reconciliation would be worth running on
 * Mondays may say so. It may not put that in the scheduler. This tool writes a
 * pending `Observation` and stops; promotion into an automation is a human act
 * behind `pandora.automations.manage`, and the automation it produces starts
 * disabled.
 *
 * That asymmetry is the whole point, and it is why the tool is `low` risk
 * despite being about scheduling work. It changes nothing: it writes a
 * suggestion into a table whose only reader is a person. An agent at
 * `observe_only` can therefore still propose, which is exactly what
 * `observe_only` should mean -- watch, and tell me.
 *
 * The parity matrix classes autonomous promotion as Future for the same reason
 * ADR-0009 exists: an agent that can schedule itself has no leash.
 */
final class ProposeFollowUpTool extends Tool
{
    public function name(): string
    {
        return 'propose_follow_up';
    }

    public function description(): string
    {
        return 'Propose work that should happen later or repeatedly, for a human to review. '
            .'This schedules nothing and changes nothing -- it records your suggestion. '
            .'Use it when you notice something worth doing that is outside what you were asked.';
    }

    public function group(): string
    {
        return 'automation';
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|min:3|max:120',
            'proposal' => 'required|string|min:10|max:4000',
            'rationale' => 'nullable|string|max:2000',
            'suggested_schedule' => 'nullable|string|max:120',
        ];
    }

    public function descriptions(): array
    {
        return [
            'title' => 'A short name for the work, as a person would file it.',
            'proposal' => 'Exactly what should be asked of an agent when this runs. Write it as an instruction, not as a description.',
            'rationale' => 'Why you think this is worth doing. The person deciding has not seen what you have seen.',
            'suggested_schedule' => 'A cron expression, if you have a view on when. Advisory only -- a human decides whether, and when.',
        ];
    }

    public function risk(): RiskLevel
    {
        return RiskLevel::Low;
    }

    public function summarize(ToolInput $input): string
    {
        return 'Propose: '.$input->string('title');
    }

    /**
     * Available to any run, including a system-actor one.
     *
     * The base class denies a system actor by default, and rightly: an
     * automation acting for nobody must not reach a tool whose authorization
     * depends on a user. This tool has no such dependency -- it writes a row
     * nobody acts on -- and an autonomous run is precisely the one most likely
     * to notice something worth proposing.
     */
    public function authorize(ToolInput $input, ToolContext $context): bool
    {
        return true;
    }

    public function handle(ToolInput $input, ToolContext $context): ToolResult
    {
        /** @var int $days */
        $days = config('pandora.automation.observations.expire_after_days', 30);

        /** @var Observation $observation */
        $observation = Observation::query()->create([
            'tenant_id' => $context->tenantId(),
            'agent_id' => $context->agent->getKey(),
            // Provenance. An observation nobody can trace back to a run is an
            // anonymous instruction, and nobody should promote one of those.
            'run_id' => $context->runId(),
            'title' => $input->string('title'),
            'proposal' => $input->string('proposal'),
            'rationale' => $input->string('rationale') ?: null,
            'suggested_cron' => $input->string('suggested_schedule') ?: null,
            'status' => ObservationStatus::Pending->value,
            'expires_at' => now()->addDays($days),
        ]);

        app(AuditLogger::class)->record(
            action: 'observation.proposed',
            targetType: 'observation',
            targetId: $observation->id,
            runId: $context->runId(),
            metadata: ['agent' => $context->agent->slug, 'title' => $observation->title],
        );

        // The model is told plainly that nothing is scheduled, so it does not
        // report to the user that it has set something up.
        return ToolResult::success(
            'Recorded as a proposal for a human to review. Nothing has been scheduled.',
            ['observation_id' => $observation->id, 'status' => 'pending'],
        );
    }
}
