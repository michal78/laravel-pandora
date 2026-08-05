<?php

declare(strict_types=1);

namespace Pandora\Pandora\Contracts;

use Pandora\Pandora\Exceptions\Provider\ProviderException;
use Pandora\Pandora\Providers\Data\ChatRequest;
use Pandora\Pandora\Providers\Data\ChatResponse;

interface ChatProvider extends Provider
{
    /**
     * @throws ProviderException
     */
    public function chat(ChatRequest $request): ChatResponse;
}
