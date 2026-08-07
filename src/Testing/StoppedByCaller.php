<?php

declare(strict_types=1);

namespace Pandora\Testing;

/**
 * Thrown by the contract suite from inside a stream callback, to stand in for
 * a cancelled run.
 *
 * It exists so the suite can assert what matters: an adapter must let a
 * caller's exception travel back out untouched. Wrapping it in a provider
 * error would turn "the user pressed stop" into "the model failed".
 */
final class StoppedByCaller extends \RuntimeException
{
    public function __construct(string $message = 'The caller stopped consuming the stream.')
    {
        parent::__construct($message);
    }
}
