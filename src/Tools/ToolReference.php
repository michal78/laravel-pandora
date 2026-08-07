<?php

declare(strict_types=1);

namespace Pandora\Tools;

/**
 * How a tool reference in an allowlist resolves to a tool.
 *
 * A reference is a name, an alias, a `name@version`, or `group:name` for a
 * whole group. There is deliberately no wildcard: "all tools" is a thing an
 * operator should have to write out.
 *
 * Extracted from `ToolGatekeeper` when delegation arrived, and the extraction
 * is the point rather than tidiness. The ability intersection has to answer the
 * same question the gatekeeper answers -- "does this reference cover this
 * tool?" -- and two implementations of that question would eventually disagree.
 * The disagreement would not look like a bug. It would look like a child run
 * holding an ability its parent's allowlist was written to withhold.
 */
final class ToolReference
{
    /**
     * @param list<string> $references
     */
    public static function matches(Tool $tool, array $references): bool
    {
        foreach ($references as $reference) {
            if (str_starts_with($reference, 'group:')) {
                if ($tool->group() === substr($reference, 6)) {
                    return true;
                }

                continue;
            }

            if ($reference === $tool->name()
                || $reference === $tool->name().'@'.$tool->version()
                || in_array($reference, $tool->aliases(), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The subset of `$tools` covered by `$references`.
     *
     * @param list<Tool> $tools
     * @param list<string> $references
     * @return list<Tool>
     */
    public static function filter(array $tools, array $references): array
    {
        return array_values(array_filter(
            $tools,
            static fn (Tool $tool): bool => self::matches($tool, $references),
        ));
    }
}
