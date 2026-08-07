<?php

declare(strict_types=1);

namespace Pandora\Contracts;

use Pandora\Exceptions\Provider\ProviderException;
use Pandora\Providers\Data\ChatRequest;
use Pandora\Providers\Data\ChatResponse;

interface ChatProvider extends Provider
{
    /**
     * @throws ProviderException
     */
    public function chat(ChatRequest $request): ChatResponse;
}
