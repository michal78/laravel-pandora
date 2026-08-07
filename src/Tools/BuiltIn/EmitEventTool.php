<?php

declare(strict_types=1);

namespace Pandora\Tools\BuiltIn;

use Illuminate\Contracts\Events\Dispatcher;
use Pandora\Tools\Enums\RiskLevel;
use Pandora\Tools\Tool;
use Pandora\Tools\ToolContext;
use Pandora\Tools\ToolInput;
use Pandora\Tools\ToolResult;

/**
 * Fire one of the application events a deployment has opened to agents.
 *
 * `medium` risk for the same reason as dispatching a job, and more so: an
 * event's listeners are invisible from here, and one of them may do anything.
 * The allowlist names events, and the tool never accepts a class name.
 */
final class EmitEventTool extends Tool
{
    public function __construct(
        private readonly Dispatcher $events,
    ) {}

    public function name(): string
    {
        return 'emit_event';
    }

    public function description(): string
    {
        return 'Fire one of the application events that have been made available.';
    }

    public function group(): string
    {
        return 'actions';
    }

    public function rules(): array
    {
        return [
            'event' => 'required|string|max:64',
            'payload' => 'nullable|array',
        ];
    }

    public function descriptions(): array
    {
        return [
            'event' => 'The configured event name, not a class name.',
            'payload' => 'Named arguments for the event, as declared in its configuration.',
        ];
    }

    public function risk(): RiskLevel
    {
        return RiskLevel::Medium;
    }

    public function summarize(ToolInput $input): string
    {
        return 'Emit event '.$input->string('event', '');
    }

    public function authorize(ToolInput $input, ToolContext $context): bool
    {
        $event = $this->event((string) $input->string('event', ''));
        $user = $context->user();

        if ($event === null || $user === null) {
            return false;
        }

        $gate = $event['authorize'] ?? null;

        return is_callable($gate) && $gate($user, $input->array('payload')) === true;
    }

    public function handle(ToolInput $input, ToolContext $context): ToolResult
    {
        $name = (string) $input->string('event', '');
        $event = $this->event($name);

        if ($event === null) {
            return ToolResult::failure("There is no event named [{$name}].");
        }

        /** @var class-string $class */
        $class = $event['class'];
        /** @var list<string> $accepted */
        $accepted = $event['payload'] ?? [];

        $arguments = [];

        foreach ($input->array('payload') as $key => $value) {
            if (! is_string($key) || ! in_array($key, $accepted, true)) {
                return ToolResult::failure("[{$name}] does not accept a field called [{$key}].");
            }

            $arguments[$key] = $value;
        }

        $this->events->dispatch(new $class(...$arguments));

        return ToolResult::success("Emitted [{$name}].", ['event' => $name, 'payload' => $arguments]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function event(string $name): ?array
    {
        /** @var array<string, array<string, mixed>> $events */
        $events = config('pandora.tools.events', []);

        return $events[$name] ?? null;
    }
}
