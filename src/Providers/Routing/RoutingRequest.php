<?php

declare(strict_types=1);

namespace Pandora\Providers\Routing;

use Pandora\Agents\Agent;
use Pandora\Providers\Data\ProviderCapabilities;

/**
 * Everything the router is allowed to consider.
 *
 * Deliberately explicit rather than reaching for ambient state: a router is
 * the kind of component whose behaviour has to be reproducible from its
 * inputs, or nobody can explain a routing decision after the fact.
 */
final readonly class RoutingRequest
{
    /**
     * @param list<string> $excluded `provider/model` references already tried
     *                               and failed in this iteration.
     */
    public function __construct(
        public Agent $agent,
        public ?string $explicitProvider = null,
        public ?string $explicitModel = null,
        public ?string $runProvider = null,
        public ?string $runModel = null,
        public ?string $conversationProvider = null,
        public ?string $conversationModel = null,
        public ProviderCapabilities $required = new ProviderCapabilities,
        public array $excluded = [],
        public ?string $tenantId = null,
        /**
         * Set after a context overflow: the next candidate must be BIGGER,
         * not merely different, or the chain just overflows again.
         */
        public ?int $minimumContextTokens = null,
    ) {}

    /**
     * @param list<string> $excluded
     */
    public function excluding(array $excluded): self
    {
        return $this->copy($excluded, $this->minimumContextTokens);
    }

    public function needingContext(?int $tokens): self
    {
        return $this->copy($this->excluded, $tokens);
    }

    /**
     * @param list<string> $excluded
     */
    private function copy(array $excluded, ?int $minimumContextTokens): self
    {
        return new self(
            $this->agent,
            $this->explicitProvider,
            $this->explicitModel,
            $this->runProvider,
            $this->runModel,
            $this->conversationProvider,
            $this->conversationModel,
            $this->required,
            $excluded,
            $this->tenantId,
            $minimumContextTokens,
        );
    }
}
