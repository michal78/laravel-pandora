<?php

declare(strict_types=1);

namespace Pandora\Exceptions;

/**
 * An MCP operation was refused.
 *
 * The messages here are written for an operator reading a log, not for the
 * model: a refusal that says "not approved" without saying which agent, which
 * tool and what to do about it is a refusal somebody will work around by
 * approving everything.
 */
final class McpDenied extends PandoraException
{
    public readonly string $reason;

    private function __construct(string $message, string $reason)
    {
        parent::__construct($message);

        $this->reason = $reason;
    }

    /**
     * The transport exists and this deployment has not turned it on.
     *
     * Names the configuration key, because the alternative is an operator
     * guessing which of several switches governs stdio, and a guess here ends
     * with somebody enabling more than they meant to.
     */
    public static function transportDisabled(string $transport, string $configKey): self
    {
        return new self(
            "The [{$transport}] MCP transport is disabled. Enable it at [{$configKey}] if this "
                .'deployment should execute local processes named by a database row.',
            'transport_disabled',
        );
    }

    public static function invalidNamespace(string $namespace): self
    {
        return new self(
            "[{$namespace}] is not a usable MCP namespace. Use lowercase letters, digits, "
                .'underscore and dash, starting with a letter.',
            'invalid_namespace',
        );
    }

    public static function unpublishableToolName(string $name): self
    {
        return new self(
            "The remote tool name [{$name}] cannot be published here, so it was skipped.",
            'unpublishable_tool_name',
        );
    }

    public static function notApproved(string $namespacedName, string $agentSlug): self
    {
        return new self(
            "[{$namespacedName}] is not approved for agent [{$agentSlug}].",
            'not_approved',
        );
    }

    /**
     * The tool changed after somebody approved it.
     *
     * Its own reason rather than folding into `notApproved`, because the two
     * ask different things of an operator: one is "nobody has approved this
     * yet", the other is "the thing you approved is not the thing that is
     * there now, and here is when it changed".
     */
    public static function schemaChanged(string $namespacedName): self
    {
        return new self(
            "[{$namespacedName}] has changed since it was approved. Approval was cleared and the "
                .'tool fails closed until a human approves the new version.',
            'schema_changed',
        );
    }

    public static function serverUnavailable(string $server, string $detail = ''): self
    {
        return new self(
            "The MCP server [{$server}] is not available.".($detail === '' ? '' : " ({$detail})"),
            'server_unavailable',
        );
    }

    public static function callFailed(string $namespacedName, string $detail): self
    {
        return new self(
            "The remote tool [{$namespacedName}] failed: {$detail}",
            'call_failed',
        );
    }

    public static function responseTooLarge(string $namespacedName, int $limit): self
    {
        return new self(
            "The remote tool [{$namespacedName}] returned more than {$limit} bytes and was refused.",
            'response_too_large',
        );
    }

    /**
     * The server tried to send the call somewhere else.
     *
     * Its own reason, and a loud one. Every other transport failure is the far
     * end being broken; this one is the far end choosing a destination, which
     * is the whole of the SSRF threat in a single response header. An operator
     * reading `server_unavailable` in the audit log would conclude the server
     * was down.
     */
    public static function redirected(string $server, string $location): self
    {
        return new self(
            "The MCP server [{$server}] answered with a redirect to [{$location}] and the call was "
                .'refused. An endpoint is operator-configured; a server that could redirect it '
                .'would be choosing where this application connects.',
            'redirected',
        );
    }

    public static function notExposed(string $tool): self
    {
        return new self(
            "[{$tool}] is not exposed by this MCP server.",
            'not_exposed',
        );
    }

    /**
     * What the model is told, which is deliberately less than the above.
     *
     * A refusal is a fact about our configuration and it is being handed to
     * something that may be relaying an attacker's instructions. It learns
     * that the call did not happen and not why.
     */
    public function userMessage(): string
    {
        return match ($this->reason) {
            'not_approved', 'schema_changed' => 'That tool is not approved for this agent.',
            'server_unavailable' => 'That tool is not available right now.',
            'response_too_large' => 'The tool returned too much data and the result was discarded.',
            default => 'That tool call could not be completed.',
        };
    }
}
