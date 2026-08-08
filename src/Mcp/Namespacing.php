<?php

declare(strict_types=1);

namespace Pandora\Mcp;

use Pandora\Exceptions\McpDenied;

/**
 * What a remote tool is called here, and what it can never be called.
 *
 * Shadowing `request_approval` — the tool that pauses for a human — is the
 * whole game. A remote server that can get its tool resolved where a core tool
 * is expected has not gained a capability; it has replaced the thing that
 * stops it.
 *
 * Two rules do the work, and only one of them is the naming convention:
 *
 * 1. A remote name is `namespace<sep>tool`, and the separator is **reserved**:
 *    no core tool name may contain it, which is asserted by a test rather than
 *    hoped for. So a namespaced name is not a valid core name by construction.
 * 2. Resolution is separated by **origin**, not by inspecting the string. The
 *    core registry is never asked about a remote tool and the remote registry
 *    is never asked about a core one. A convention enforced only by prefix
 *    matching is one normalisation bug away from being no convention at all —
 *    and normalising attacker-controlled strings is exactly where those bugs
 *    live.
 *
 * The namespace comes from the server's own database row, written by an
 * operator. It is never read from the wire: a server's idea of its name is
 * attacker input being used as an identity.
 */
final class Namespacing
{
    /**
     * A namespace an operator may give a server.
     *
     * Deliberately narrow — lowercase, digits, underscore and dash — so that a
     * namespace can never contain the separator, a path character, a quote, or
     * anything that changes meaning when it reaches JSON, a URL or a prompt.
     */
    public const NAMESPACE_PATTERN = '/^[a-z][a-z0-9_-]{0,62}$/';

    /**
     * A remote tool name we are willing to publish.
     *
     * Also narrow, and for the same reason. A server offering a tool called
     * `../../etc` or `tool name` or one containing the separator is offering
     * something that cannot be named here; the tool is skipped rather than
     * sanitised, because sanitising produces a name that no longer matches
     * what has to be sent back over the wire.
     */
    public const REMOTE_NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9_-]{0,127}$/';

    public static function separator(): string
    {
        $separator = config('pandora.mcp.client.namespace_separator', '.');

        return is_string($separator) && $separator !== '' ? $separator : '.';
    }

    public static function isValidNamespace(string $namespace): bool
    {
        return preg_match(self::NAMESPACE_PATTERN, $namespace) === 1
            && ! str_contains($namespace, self::separator());
    }

    /**
     * Whether a remote tool name can be published at all.
     */
    public static function isPublishableRemoteName(string $name): bool
    {
        return preg_match(self::REMOTE_NAME_PATTERN, $name) === 1
            && ! str_contains($name, self::separator());
    }

    /**
     * @throws McpDenied
     */
    public static function qualify(string $namespace, string $remoteName): string
    {
        if (! self::isValidNamespace($namespace)) {
            throw McpDenied::invalidNamespace($namespace);
        }

        if (! self::isPublishableRemoteName($remoteName)) {
            throw McpDenied::unpublishableToolName($remoteName);
        }

        return $namespace.self::separator().$remoteName;
    }

    /**
     * Does this name belong to the remote half of the world at all?
     *
     * Used to keep a namespaced name out of core resolution, never to decide
     * that something IS a particular remote tool — that lookup goes to the
     * database by exact match.
     */
    public static function looksRemote(string $name): bool
    {
        return str_contains($name, self::separator());
    }

    /**
     * Split a namespaced name, or null when it is not one.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function split(string $name): ?array
    {
        $separator = self::separator();
        $position = strpos($name, $separator);

        if ($position === false) {
            return null;
        }

        $namespace = substr($name, 0, $position);
        $remote = substr($name, $position + strlen($separator));

        if (! self::isValidNamespace($namespace) || ! self::isPublishableRemoteName($remote)) {
            return null;
        }

        return [$namespace, $remote];
    }
}
