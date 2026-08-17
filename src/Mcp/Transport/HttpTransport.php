<?php

declare(strict_types=1);

namespace Pandora\Mcp\Transport;

use Illuminate\Http\Client\Factory as HttpFactory;
use Pandora\Exceptions\McpDenied;
use Pandora\Mcp\McpServer;
use Pandora\Providers\Credentials\CredentialManager;

/**
 * MCP over HTTP, speaking JSON-RPC 2.0.
 *
 * Four things this class owes the layers above, and they are all about what
 * happens when the other end misbehaves rather than when it works:
 *
 * **A fixed destination.** The endpoint comes from the server row an operator
 * wrote and from nowhere else — not from a tool argument, not from model
 * output, and not from a `Location` header the far end sent back.
 *
 * **A timeout.** A remote server that hangs must cost one tool call, not one
 * worker. The bound comes from the server row, falling back to configuration.
 *
 * **A size cap.** A response is refused at the byte level before it is
 * decoded, because `json_decode` on a hostile 2GB body is the failure the cap
 * exists to prevent — checking the size after parsing is checking it too late.
 *
 * **No leaking exceptions.** Any transport failure becomes `McpDenied`, so a
 * connection reset arrives at the run as an ordinary tool error rather than as
 * a client library's exception thrown from the middle of a tool loop.
 *
 * The credential is resolved from the Phase 3 encrypted store by key. It is
 * never on the server row and never in a log line here.
 */
final readonly class HttpTransport implements McpTransportContract
{
    public function __construct(
        private HttpFactory $http,
        private CredentialManager $credentials,
    ) {}

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
        $endpoint = (string) $server->endpoint;

        if ($endpoint === '') {
            throw McpDenied::serverUnavailable($server->slug, 'no endpoint configured');
        }

        $maxBytes = (int) config('pandora.mcp.client.max_response_bytes', 262144);

        try {
            $response = $this->http
                ->timeout(max(1, $server->timeout_seconds))
                // The destination is the operator's, and it stays the
                // operator's. Guzzle follows redirects by default, so before
                // this line a server answering `302 Location:
                // http://169.254.169.254/` had our POST re-sent to the cloud
                // metadata endpoint and its body returned to the model as tool
                // output — an HTTPS-to-HTTP downgrade into the private range,
                // chosen entirely by the far end. This is the one outbound
                // surface the package has, and T6b is the reason it now has a
                // fixed destination.
                ->withOptions(['allow_redirects' => false])
                ->withHeaders($this->headers($server))
                ->asJson()
                ->acceptJson()
                ->post($endpoint, [
                    'jsonrpc' => '2.0',
                    'id' => bin2hex(random_bytes(8)),
                    'method' => $method,
                    'params' => $params,
                ]);
        } catch (\Throwable $e) {
            // A timeout, a DNS failure, a reset connection. All the same thing
            // from here: the server did not answer.
            throw McpDenied::serverUnavailable($server->slug, $e->getMessage());
        }

        // Refused rather than followed, and named rather than folded into the
        // failure below: with redirects off a 3xx is a well-formed answer with
        // an empty body, which would otherwise arrive as "malformed response"
        // and read like a broken server instead of a server steering us.
        if ($response->redirect()) {
            throw McpDenied::redirected(
                $server->slug,
                mb_substr($response->header('Location'), 0, 200),
            );
        }

        if ($response->failed()) {
            throw McpDenied::serverUnavailable(
                $server->slug,
                'HTTP '.$response->status(),
            );
        }

        $body = $response->body();

        // Measured before decoding. `json_decode` on a hostile body is the
        // thing the cap exists to prevent.
        if (strlen($body) > $maxBytes) {
            throw McpDenied::responseTooLarge($server->slug, $maxBytes);
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
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

    /**
     * @return array<string, string>
     */
    private function headers(McpServer $server): array
    {
        $headers = ['MCP-Protocol-Version' => '2025-06-18'];

        if ($server->credential_key === null || $server->credential_key === '') {
            return $headers;
        }

        $credential = $this->credentials->resolve($server->credential_key);

        if ($credential === null) {
            return $headers;
        }

        // Read here and nowhere else. The secret is not logged, not returned,
        // and not put on the server row.
        $headers['Authorization'] = 'Bearer '.$credential->secret();

        return $headers;
    }
}
