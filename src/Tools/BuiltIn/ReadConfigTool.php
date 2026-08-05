<?php

declare(strict_types=1);

namespace Pandora\Pandora\Tools\BuiltIn;

use Pandora\Pandora\Tools\Enums\RiskLevel;
use Pandora\Pandora\Tools\Tool;
use Pandora\Pandora\Tools\ToolContext;
use Pandora\Pandora\Tools\ToolInput;
use Pandora\Pandora\Tools\ToolResult;

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
        return ['key' => 'required|string|max:128'];
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
     * @return list<string>
     */
    private function readable(): array
    {
        /** @var list<string> $keys */
        $keys = config('pandora.tools.readable_config', []);

        return $keys;
    }
}
