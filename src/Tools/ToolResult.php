<?php

declare(strict_types=1);

namespace Pandora\Pandora\Tools;

/**
 * What a tool returns.
 *
 * `content` is what the model is shown. `data` is the structured record kept
 * on the execution row for the UI and the audit trail. They are separate
 * because the model does not need the whole payload, and the smaller the
 * surface returned to it, the smaller the injection surface.
 */
final readonly class ToolResult implements \JsonSerializable
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $metadata
     */
    private function __construct(
        public bool $ok,
        public string $content,
        public array $data = [],
        public array $metadata = [],
        public bool $awaitsUser = false,
    ) {}

    /**
     * The tool asked the user something and cannot finish until they answer.
     *
     * The run parks at `waiting_for_user` holding no job, exactly as it does
     * for an approval. A tool cannot transition a run itself -- only the state
     * machine may -- so it says so in its result and the executor acts on it.
     */
    public static function awaitingUser(string $question): self
    {
        return new self(true, $question, awaitsUser: true);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $metadata
     */
    public static function success(string $content, array $data = [], array $metadata = []): self
    {
        return new self(true, $content, $data, $metadata);
    }

    /**
     * A tool that could not do its job.
     *
     * This is returned to the model, not thrown: "that order does not exist"
     * is information the agent should act on, not a run-ending failure.
     *
     * @param array<string, mixed> $data
     */
    public static function failure(string $message, array $data = []): self
    {
        return new self(false, $message, $data);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array_filter([
            'ok' => $this->ok,
            'content' => $this->content,
            'data' => $this->data === [] ? null : $this->data,
            'metadata' => $this->metadata === [] ? null : $this->metadata,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
