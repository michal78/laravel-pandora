<?php

declare(strict_types=1);

namespace Pandora\Pandora\Contracts;

use Pandora\Pandora\Exceptions\Provider\ProviderException;
use Pandora\Pandora\Providers\Data\ChatRequest;
use Pandora\Pandora\Providers\Data\ChatResponse;
use Pandora\Pandora\Providers\Data\StreamDelta;

interface StreamingProvider extends ChatProvider
{
    /**
     * Stream a response, invoking $onDelta as increments arrive, and returning
     * the assembled response when the stream ends.
     *
     * Implementations MUST call $onDelta with StreamDelta::done() last, and
     * MUST return a complete ChatResponse -- callers rely on the return value
     * rather than accumulating deltas themselves.
     *
     * @param callable(StreamDelta): void $onDelta
     *
     * @throws ProviderException
     */
    public function stream(ChatRequest $request, callable $onDelta): ChatResponse;
}
