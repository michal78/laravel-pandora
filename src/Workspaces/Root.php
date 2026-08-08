<?php

declare(strict_types=1);

namespace Pandora\Workspaces;

/**
 * One place an operator has said a workspace may live.
 *
 * A disk and a base prefix, named by a key. This is configuration and it is
 * the ONLY source a `root_path` ever comes from: the control center offers
 * these by key, and the key is the entire vocabulary a request has for saying
 * where a workspace should be. There is no spelling of a root that is not one
 * of these, which is the property the whole creation surface rests on.
 */
final readonly class Root
{
    public function __construct(
        public string $key,
        public string $label,
        public string $disk,
        public string $basePrefix,
    ) {}

    /**
     * How this root reads to a human choosing between them.
     *
     * The disk and prefix are shown because an operator picking a root is
     * choosing which bucket the bytes land in, and a list of friendly names
     * alone would hide the only part of the choice that matters.
     */
    public function describe(): string
    {
        return $this->label.' ('.$this->disk.':'.trim($this->basePrefix, '/').')';
    }
}
