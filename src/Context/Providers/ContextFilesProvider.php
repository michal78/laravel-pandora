<?php

declare(strict_types=1);

namespace Pandora\Context\Providers;

use Pandora\Context\ContextFiles;
use Pandora\Context\ContextRequest;
use Pandora\Context\ContextSection;
use Pandora\Contracts\ContextProvider;
use Pandora\Providers\Data\ChatMessage;

/**
 * Files an operator attached to an agent -- a style guide, a product glossary,
 * an escalation policy.
 *
 * The paths come from the agent's metadata, which is edited in a browser, so
 * they are treated as untrusted input all the way down. `ContextFiles` does
 * the resolving and the refusing; this provider's only job is to not get
 * clever about it.
 *
 * A refused path is skipped rather than fatal. One wrong line in an agent's
 * configuration should cost that file, not the agent.
 */
final class ContextFilesProvider implements ContextProvider
{
    public function key(): string
    {
        return 'context_files';
    }

    public function provide(ContextRequest $request): ?ContextSection
    {
        $paths = $this->configuredPaths($request);

        if ($paths === []) {
            return null;
        }

        $files = ContextFiles::fromConfig()->readAll($paths);

        if ($files === []) {
            return null;
        }

        $blocks = [];

        foreach ($files as $path => $contents) {
            $blocks[] = '<file path="'.basename($path).'">'.PHP_EOL.$contents.PHP_EOL.'</file>';
        }

        return ContextSection::of($this->key(), [
            ChatMessage::system("<context_files>\n".implode("\n", $blocks)."\n</context_files>"),
        ]);
    }

    /**
     * @return list<string>
     */
    private function configuredPaths(ContextRequest $request): array
    {
        $metadata = $request->agent->metadata ?? [];
        $paths = $metadata['context_files'] ?? null;

        if (! is_array($paths)) {
            return [];
        }

        return array_values(array_filter($paths, is_string(...)));
    }
}
