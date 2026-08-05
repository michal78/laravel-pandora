<?php

declare(strict_types=1);

namespace Pandora\Pandora\Tools\BuiltIn;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Notifications\Notification;
use Pandora\Pandora\Tools\Enums\RiskLevel;
use Pandora\Pandora\Tools\Tool;
use Pandora\Pandora\Tools\ToolContext;
use Pandora\Pandora\Tools\ToolInput;
use Pandora\Pandora\Tools\ToolResult;

/**
 * Notify the person this run is acting for.
 *
 * Deliberately narrow: the recipient is always the ACTOR, and cannot be chosen
 * by the model. A tool that let an agent pick who to email would be a spam
 * cannon one prompt injection away, and there is no configuration of it that
 * makes that safe.
 *
 * `high` risk, so it pauses for approval by default: a notification leaves the
 * application and cannot be recalled.
 */
final class SendNotificationTool extends Tool
{
    public function __construct(
        private readonly Dispatcher $notifications,
        private readonly Container $container,
    ) {}

    public function name(): string
    {
        return 'send_notification';
    }

    public function description(): string
    {
        return 'Send a notification to the user you are acting for. '
            .'You cannot choose a different recipient.';
    }

    public function group(): string
    {
        return 'actions';
    }

    public function rules(): array
    {
        return [
            'notification' => 'required|string|max:64',
            'payload' => 'nullable|array',
        ];
    }

    public function descriptions(): array
    {
        return [
            'notification' => 'The configured notification name, not a class name.',
            'payload' => 'Named arguments for the notification, as declared in its configuration.',
        ];
    }

    public function risk(): RiskLevel
    {
        return RiskLevel::High;
    }

    public function summarize(ToolInput $input): string
    {
        return 'Notify the user: '.$input->string('notification', '');
    }

    public function authorize(ToolInput $input, ToolContext $context): bool
    {
        $notification = $this->notification((string) $input->string('notification', ''));
        $user = $context->user();

        if ($notification === null || $user === null) {
            return false;
        }

        $gate = $notification['authorize'] ?? null;

        return is_callable($gate) && $gate($user, $input->array('payload')) === true;
    }

    public function handle(ToolInput $input, ToolContext $context): ToolResult
    {
        $name = (string) $input->string('notification', '');
        $notification = $this->notification($name);
        $recipient = $context->user();

        if ($notification === null) {
            return ToolResult::failure("There is no notification named [{$name}].");
        }

        if ($recipient === null) {
            return ToolResult::failure('There is nobody to notify: this run has no user.');
        }

        /** @var class-string<Notification> $class */
        $class = $notification['class'];
        /** @var list<string> $accepted */
        $accepted = $notification['payload'] ?? [];

        $arguments = [];

        foreach ($input->array('payload') as $key => $value) {
            if (! is_string($key) || ! in_array($key, $accepted, true)) {
                return ToolResult::failure("[{$name}] does not accept a field called [{$key}].");
            }

            $arguments[$key] = $value;
        }

        // Built through the container so a notification may take dependencies
        // as well as the arguments the model supplied.
        $instance = $this->container->make($class, $arguments);

        if (! $instance instanceof Notification) {
            return ToolResult::failure("[{$name}] is not a notification.");
        }

        $this->notifications->send($recipient, $instance);

        return ToolResult::success(
            'Notified the user.',
            ['notification' => $name, 'payload' => $arguments],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function notification(string $name): ?array
    {
        /** @var array<string, array<string, mixed>> $notifications */
        $notifications = config('pandora.tools.notifications', []);

        return $notifications[$name] ?? null;
    }
}
