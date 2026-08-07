<?php

declare(strict_types=1);

namespace Pandora\Tools\BuiltIn;

use Illuminate\Contracts\Bus\Dispatcher;
use Pandora\Tools\Enums\RiskLevel;
use Pandora\Tools\Tool;
use Pandora\Tools\ToolContext;
use Pandora\Tools\ToolInput;
use Pandora\Tools\ToolResult;

/**
 * Queue one of the jobs the deployment has made available to agents.
 *
 * `medium` risk: the job's own effect may be anything, and unlike a tool call
 * it happens later, out of sight of this run's trace. The allowlist is
 * class-exact, and constructor arguments are passed positionally by name from
 * the configured signature -- a model never chooses which class runs.
 */
final class DispatchJobTool extends Tool
{
    public function __construct(
        private readonly Dispatcher $bus,
    ) {}

    public function name(): string
    {
        return 'dispatch_job';
    }

    public function description(): string
    {
        return 'Queue one of the background jobs that have been made available. '
            .'The job runs after this conversation continues, so you will not see its result.';
    }

    public function group(): string
    {
        return 'actions';
    }

    public function rules(): array
    {
        return [
            'job' => 'required|string|max:64',
            'arguments' => 'nullable|array',
        ];
    }

    public function descriptions(): array
    {
        return [
            'job' => 'The configured job name, not a class name.',
            'arguments' => 'Named arguments for the job, as declared in its configuration.',
        ];
    }

    public function risk(): RiskLevel
    {
        return RiskLevel::Medium;
    }

    public function summarize(ToolInput $input): string
    {
        return 'Queue job '.$input->string('job', '');
    }

    public function authorize(ToolInput $input, ToolContext $context): bool
    {
        $job = $this->job((string) $input->string('job', ''));
        $user = $context->user();

        if ($job === null || $user === null) {
            return false;
        }

        $gate = $job['authorize'] ?? null;

        return is_callable($gate) && $gate($user, $input->array('arguments')) === true;
    }

    public function handle(ToolInput $input, ToolContext $context): ToolResult
    {
        $name = (string) $input->string('job', '');
        $job = $this->job($name);

        if ($job === null) {
            return ToolResult::failure("There is no job named [{$name}].");
        }

        /** @var class-string $class */
        $class = $job['class'];
        /** @var list<string> $accepted */
        $accepted = $job['arguments'] ?? [];

        $arguments = [];

        foreach ($input->array('arguments') as $key => $value) {
            if (! is_string($key) || ! in_array($key, $accepted, true)) {
                return ToolResult::failure(
                    "[{$name}] does not accept an argument called [{$key}].",
                );
            }

            $arguments[$key] = $value;
        }

        $this->bus->dispatch(new $class(...$arguments));

        return ToolResult::success(
            "Queued [{$name}].",
            ['job' => $name, 'arguments' => $arguments],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function job(string $name): ?array
    {
        /** @var array<string, array<string, mixed>> $jobs */
        $jobs = config('pandora.tools.jobs', []);

        return $jobs[$name] ?? null;
    }
}
