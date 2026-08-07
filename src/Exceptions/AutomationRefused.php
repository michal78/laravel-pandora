<?php

declare(strict_types=1);

namespace Pandora\Exceptions;

/**
 * An occurrence will not become a run.
 *
 * Every constructor here corresponds to an `automation_runs` row with a
 * reason, so a refusal is always inspectable after the fact -- the point of
 * ADR-0009 is that an autonomous action, including one that did NOT happen, is
 * attributable.
 */
final class AutomationRefused extends PandoraException
{
    private function __construct(
        string $message,
        public readonly string $reason,
    ) {
        parent::__construct($message);
    }

    public static function alreadyRunning(string $slug): self
    {
        return new self(
            "Automation [{$slug}] skipped this occurrence: a previous run is still in flight.",
            'concurrency',
        );
    }

    public static function conditionFalse(string $slug, string $condition): self
    {
        return new self(
            "Automation [{$slug}] skipped this occurrence: condition [{$condition}] evaluated false.",
            'condition',
        );
    }

    public static function unknownCondition(string $slug, string $condition): self
    {
        return new self(
            "Automation [{$slug}] names condition [{$condition}], which is not registered in pandora.automation.conditions.",
            'unknown_condition',
        );
    }

    public static function autonomyBudgetExhausted(string $slug, int $limit, int $seconds): self
    {
        return new self(
            sprintf(
                'Automation [%s] has used its autonomy budget of %d run(s) per %d seconds and has been disabled.',
                $slug,
                $limit,
                $seconds,
            ),
            'autonomy_budget',
        );
    }

    public static function agentDisabled(string $slug, string $agentSlug): self
    {
        return new self(
            "Automation [{$slug}] refused: agent [{$agentSlug}] is disabled.",
            'agent_disabled',
        );
    }

    public static function agentMissing(string $slug): self
    {
        return new self(
            "Automation [{$slug}] refused: its agent no longer exists.",
            'agent_missing',
        );
    }

    public static function disabled(string $slug): self
    {
        return new self("Automation [{$slug}] is disabled.", 'disabled');
    }

    public function userMessage(): string
    {
        return 'This automation did not run. Its history explains why.';
    }
}
