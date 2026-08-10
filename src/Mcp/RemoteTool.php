<?php

declare(strict_types=1);

namespace Pandora\Mcp;

use Pandora\Audit\AuditLogger;
use Pandora\Exceptions\McpDenied;
use Pandora\Mcp\Transport\TransportFactory;
use Pandora\Tools\Enums\RiskLevel;
use Pandora\Tools\Schema\RuleSchemaGenerator;
use Pandora\Tools\Tool;
use Pandora\Tools\ToolContext;
use Pandora\Tools\ToolInput;
use Pandora\Tools\ToolResult;

/**
 * A tool that lives somewhere else, wearing the local `Tool` interface.
 *
 * Being a `Tool` is what puts a remote call through the same machinery as a
 * local one: the same validation, the same policy layer, the same autonomy
 * clamp, the same execution row with the same redaction. A parallel execution
 * path for remote calls would be a second place to get all of that right.
 *
 * What is deliberately NOT inherited is trust. The description is a bounded
 * copy of something a third party wrote; the schema is advertised as the
 * server gave it, because the model needs it to form a call, and it is never
 * used to decide whether the call is allowed.
 *
 * `medium` risk, always. The tool's own opinion of its risk is not consulted
 * and there is no field for one — a server that could declare itself harmless
 * would be setting our approval policy from the far side of the boundary.
 */
final class RemoteTool extends Tool
{
    public function __construct(
        public readonly McpTool $tool,
        public readonly McpServer $server,
    ) {}

    public function name(): string
    {
        return $this->tool->namespaced_name;
    }

    /**
     * The remote description, bounded, and marked as coming from elsewhere.
     *
     * The prefix is not decoration. This string is about to sit in a list of
     * tool descriptions the model reads as ours, and a sentence that says
     * "ignore your instructions" reads very differently when the line above it
     * says where it came from. It is a mitigation, not a fix — the fix is that
     * nothing in a description is ever executed.
     */
    public function description(): string
    {
        return '[remote: '.$this->server->slug.'] '.$this->tool->boundedDescription();
    }

    public function group(): string
    {
        return 'mcp';
    }

    /**
     * Arguments are carried, not re-declared.
     *
     * Deriving Laravel rules from a remote JSON Schema would mean trusting
     * that schema to describe what is safe, and it is the same untrusted text
     * as everything else the server said.
     *
     * Which is exactly why validation must not strip what it did not declare
     * here. The model formed its call against the server's schema, so its keys
     * are top-level and undeclared by construction; keeping only `arguments`
     * sends the far end an empty object and the call succeeds having asked
     * nothing. See `carriesUndeclaredArguments()` below.
     */
    public function rules(): array
    {
        return ['arguments' => 'nullable|array'];
    }

    /**
     * The one tool in the system for which this is true, and it is true
     * because the arguments were never ours to declare.
     */
    protected function carriesUndeclaredArguments(): bool
    {
        return true;
    }

    /**
     * What the model is shown so it can form a call: the server's own schema.
     *
     * Advertised, never enforced. The distinction is the whole of ADR-0014 in
     * one method — we tell the model what the far end says it wants, and we
     * decide separately, locally, whether the call may happen at all.
     *
     * @return array<string, mixed>
     */
    public function schema(RuleSchemaGenerator $generator): array
    {
        $schema = $this->tool->input_schema;

        if (! is_array($schema) || $schema === []) {
            return ['type' => 'object', 'properties' => new \stdClass];
        }

        return $schema;
    }

    public function risk(): RiskLevel
    {
        return RiskLevel::Medium;
    }

    /**
     * Available to a system actor, like any other medium-risk built-in that
     * states its own. The default would refuse this to every caller.
     */
    public function authorize(ToolInput $input, ToolContext $context): bool
    {
        return true;
    }

    public function summarize(ToolInput $input): string
    {
        return 'Remote call: '.$this->name();
    }

    public function handle(ToolInput $input, ToolContext $context): ToolResult
    {
        /** @var array<string, mixed> $arguments */
        $arguments = $input->has('arguments') ? $input->array('arguments') : $input->toArray();

        try {
            $response = app(TransportFactory::class)
                ->for($this->server)
                ->callTool($this->server, $this->tool->remote_name, $arguments);
        } catch (McpDenied $e) {
            app(AuditLogger::class)->record(
                action: 'mcp.call_failed',
                targetType: 'mcp_tool',
                targetId: (string) $this->tool->getKey(),
                runId: $context->runId(),
                severity: $e->reason === 'server_unavailable' ? 'warning' : 'info',
                metadata: [
                    'tool' => $this->name(),
                    'server' => $this->server->slug,
                    'reason' => $e->reason,
                ],
            );

            // An ordinary tool failure. The run continues, and the model is
            // told less than the operator: a refusal is a fact about our
            // configuration being handed to something that may be relaying an
            // attacker's instructions.
            return ToolResult::failure($e->userMessage());
        }

        return ToolResult::success(
            $this->textOf($response),
            ['server' => $this->server->slug, 'remote_name' => $this->tool->remote_name],
        );
    }

    /**
     * Flatten an MCP content array into the string the model is shown.
     *
     * UNTRUSTED, exactly like a delegated child's result or a fetched page.
     * Nothing here interprets it, and nothing downstream may widen what the
     * run can do because of what it says.
     *
     * @param array<string, mixed> $response
     */
    private function textOf(array $response): string
    {
        $content = $response['content'] ?? null;

        if (! is_array($content)) {
            return mb_substr(json_encode($response, JSON_THROW_ON_ERROR), 0, 20000);
        }

        $parts = [];

        foreach ($content as $item) {
            if (is_array($item) && isset($item['text']) && is_string($item['text'])) {
                $parts[] = $item['text'];
            }
        }

        return mb_substr($parts === [] ? '(no content)' : implode("\n", $parts), 0, 20000);
    }
}
