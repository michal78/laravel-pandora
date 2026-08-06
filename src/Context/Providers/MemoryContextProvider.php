<?php

declare(strict_types=1);

namespace Pandora\Pandora\Context\Providers;

use Pandora\Pandora\Context\ContextRequest;
use Pandora\Pandora\Context\ContextSection;
use Pandora\Pandora\Contracts\ContextProvider;
use Pandora\Pandora\Memory\MemoryQuery;
use Pandora\Pandora\Memory\MemoryResult;
use Pandora\Pandora\Memory\MemoryRetriever;
use Pandora\Pandora\Memory\ScopeResolver;
use Pandora\Pandora\Providers\Data\ChatMessage;

/**
 * What the agent remembers that bears on what was just asked.
 *
 * The scope comes from `ScopeResolver`, given the run's session -- never from
 * the prompt, and never from anything a caller passed in. This provider has no
 * parameter that could widen it, which is why it is safe for it to run on
 * every request.
 *
 * The retrieval query is the latest user message rather than the whole
 * conversation. A query built from everything matches everything: token
 * overlap against a long transcript ranks by how common a word is, not by
 * what is being asked, and the agent starts volunteering unrelated facts.
 *
 * Memory is presented as a system message with provenance attached, because
 * "you told me in March" and "I inferred this" are different claims and the
 * model should be able to hedge accordingly.
 */
final class MemoryContextProvider implements ContextProvider
{
    public function __construct(
        private readonly MemoryRetriever $retriever,
        private readonly ScopeResolver $scopes,
    ) {}

    public function key(): string
    {
        return 'memory';
    }

    public function provide(ContextRequest $request): ?ContextSection
    {
        $query = $this->queryText($request);

        if ($query === null) {
            return null;
        }

        $results = $this->retriever->retrieve(
            // The agent is already loaded on the request, so its workspace
            // costs nothing to include here and would cost a query anywhere
            // else.
            $this->scopes->forSession($request->session, $request->agent->workspace_id),
            MemoryQuery::for($query),
        );

        if ($results === []) {
            return null;
        }

        $lines = array_map(
            static function (MemoryResult $result): string {
                $item = $result->item;
                $label = $item->title !== null && $item->title !== ''
                    ? $item->title.': '
                    : '';

                return '- '.$label.$item->content.' ('.$item->source->label().')';
            },
            $results,
        );

        return ContextSection::of($this->key(), [
            ChatMessage::system(
                "<memory>\nWhat you already know that may bear on this:\n".
                implode("\n", $lines).
                "\n</memory>",
            ),
        ]);
    }

    /**
     * The text a retrieval is built from: the most recent thing the person
     * actually said.
     */
    private function queryText(ContextRequest $request): ?string
    {
        $input = $request->run->input;

        if ($input !== null && trim($input) !== '') {
            return $input;
        }

        return null;
    }
}
