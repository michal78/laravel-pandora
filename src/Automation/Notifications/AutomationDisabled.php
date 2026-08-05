<?php

declare(strict_types=1);

namespace Pandora\Pandora\Automation\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Pandora\Pandora\Automation\Automation;

/**
 * An automation turned itself off.
 *
 * ADR-0009 requires this: an automation that merely skipped when it ran out of
 * budget would keep trying forever and nobody would learn it was broken. The
 * notification is the difference between a leash and a silence.
 *
 * Deliberately plain and overridable through `pandora.automation.autonomy.
 * notification` -- most deployments will want this in Slack or PagerDuty, and
 * Pandora should not have an opinion about which.
 */
class AutomationDisabled extends Notification
{
    /**
     * Not final, because the point of `pandora.automation.autonomy.notification`
     * is that a deployment substitutes its own. The constructor signature is
     * the contract a substitute has to honour.
     */
    public function __construct(
        public readonly string $automationSlug,
        public readonly string $automationName,
        public readonly string $reason,
    ) {}

    public static function forAutomation(Automation $automation, string $reason): self
    {
        return new self($automation->slug, $automation->name, $reason);
    }

    /**
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Pandora disabled the automation \"{$this->automationName}\"")
            ->line("The automation \"{$this->automationName}\" ({$this->automationSlug}) has been disabled.")
            ->line($this->reason)
            ->line('It will not run again until somebody enables it.');
    }

    /**
     * @return array<string, string>
     */
    public function toArray(mixed $notifiable): array
    {
        return [
            'automation_slug' => $this->automationSlug,
            'automation_name' => $this->automationName,
            'reason' => $this->reason,
        ];
    }
}
