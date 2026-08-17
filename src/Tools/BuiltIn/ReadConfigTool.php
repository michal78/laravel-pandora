<?php

declare(strict_types=1);

namespace Pandora\Tools\BuiltIn;

use Pandora\Tools\Enums\RiskLevel;
use Pandora\Tools\Tool;
use Pandora\Tools\ToolContext;
use Pandora\Tools\ToolInput;
use Pandora\Tools\ToolResult;

/**
 * Read a configuration value the deployment has published to agents.
 *
 * An exact-match allowlist, not a prefix one. `app.*` would hand over
 * `app.key`; `services.*` would hand over every third-party credential in the
 * application. Every readable key is written out, one at a time, by a person.
 */
final class ReadConfigTool extends Tool
{
    public function name(): string
    {
        return 'read_config';
    }

    public function description(): string
    {
        return 'Read one of the application settings that have been published to agents. '
            .'Only specific, named keys are readable.';
    }

    public function group(): string
    {
        return 'introspection';
    }

    public function rules(): array
    {
        $keys = $this->readable();

        return [
            'key' => $keys === []
                ? 'required|string|max:128'
                : 'required|string|in:'.implode(',', $keys),
        ];
    }

    public function descriptions(): array
    {
        return ['key' => 'The exact configuration key, for example "app.name".'];
    }

    public function risk(): RiskLevel
    {
        return RiskLevel::Low;
    }

    public function summarize(ToolInput $input): string
    {
        return 'Read config '.$input->string('key', '');
    }

    public function authorize(ToolInput $input, ToolContext $context): bool
    {
        return in_array((string) $input->string('key', ''), $this->readable(), true);
    }

    public function handle(ToolInput $input, ToolContext $context): ToolResult
    {
        $key = (string) $input->string('key', '');

        // Checked again here, not only in authorize(): this method is the one
        // that touches config, and it should be safe read on its own.
        if (! in_array($key, $this->readable(), true)) {
            return ToolResult::failure(
                "[{$key}] is not readable. Readable keys: ".(implode(', ', $this->readable()) ?: 'none').'.',
            );
        }

        // The allowlist is a person's judgement, and this is the case where a
        // person's judgement is not enough. `services.stripe.secret` is an
        // exact key somebody could reasonably add while wiring up a tool, and
        // publishing it hands a live credential to a model that may be
        // relaying an attacker's instructions. T4 says a credential is never in
        // context; an allowlist entry must not be able to make that false.
        //
        // Refused on the key, before the value is read, so the secret is not
        // even loaded into a variable that could end up in a stack trace.
        if ($this->isSensitiveKey($key)) {
            return ToolResult::failure(
                "[{$key}] looks like a credential and is never readable, even though it is "
                .'allowlisted. Remove it from `pandora.tools.readable_config`.',
            );
        }

        $value = config($key);

        if (is_array($value) || is_object($value)) {
            return ToolResult::failure("[{$key}] is not a single value.");
        }

        return ToolResult::success(
            $key.' = '.var_export($value, true),
            ['key' => $key, 'value' => $value],
        );
    }

    /**
     * Whether a key names something that must never be published.
     *
     * Reuses `pandora.security.redact_keys` rather than keeping a second list.
     * The two questions -- "would we mask this in a log?" and "may a model read
     * it?" -- have the same answer, and two lists would drift until one of them
     * was wrong about a key the other had.
     */
    private function isSensitiveKey(string $key): bool
    {
        /** @var list<string> $sensitive */
        $sensitive = config('pandora.security.redact_keys', []);

        $normalized = strtolower($key);

        foreach ($sensitive as $needle) {
            if (str_contains($normalized, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function readable(): array
    {
        /** @var list<string> $keys */
        $keys = config('pandora.tools.readable_config', []);

        return $keys;
    }
}
