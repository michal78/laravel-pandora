<?php

declare(strict_types=1);

namespace Pandora\Pandora\Providers\Data;

final readonly class ProviderHealth implements \JsonSerializable
{
    public function __construct(
        public string $status,
        public ?int $latencyMs = null,
        public ?string $message = null,
        public ?\DateTimeImmutable $checkedAt = null,
    ) {}

    public static function healthy(?int $latencyMs = null): self
    {
        return new self('healthy', $latencyMs, checkedAt: new \DateTimeImmutable);
    }

    public static function degraded(string $message): self
    {
        return new self('degraded', null, $message, new \DateTimeImmutable);
    }

    public static function unknown(): self
    {
        return new self('unknown');
    }

    public function isUsable(): bool
    {
        return $this->status !== 'down';
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'status' => $this->status,
            'latency_ms' => $this->latencyMs,
            'message' => $this->message,
            'checked_at' => $this->checkedAt?->format(\DATE_ATOM),
        ];
    }
}
