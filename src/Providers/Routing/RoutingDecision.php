<?php

declare(strict_types=1);

namespace Pandora\Providers\Routing;

/**
 * Where one model request is going, and why.
 */
final readonly class RoutingDecision
{
    /**
     * @param list<string> $skipped Candidates passed over, with the reason.
     */
    public function __construct(
        public string $providerKey,
        public string $modelKey,
        public RoutingSource $source,
        public int $attempt = 1,
        public array $skipped = [],
    ) {}

    public function reference(): string
    {
        return $this->providerKey.'/'.$this->modelKey;
    }

    /**
     * @return array<string, mixed>
     */
    public function toTrace(): array
    {
        return [
            'provider' => $this->providerKey,
            'model' => $this->modelKey,
            'source' => $this->source->value,
            'attempt' => $this->attempt,
            // Present precisely when it is interesting: an empty list on a
            // first-choice route would be noise on every single run.
            'skipped' => $this->skipped,
        ];
    }
}
