<?php

declare(strict_types=1);

namespace Pandora\Tests\Support;

use Pandora\Agents\Agent;
use Pandora\Agents\AgentRunner;
use Pandora\Providers\Data\ToolCall;
use Pandora\Runs\Run;
use Pandora\Tools\BuiltIn\DelegateToAgentTool;
use Pandora\Tools\Tool;

/**
 * Scaffolding for delegation tests.
 *
 * Builds real agents, real runs and a real tool registry rather than mocks,
 * for the reason the rest of the suite does: the properties under test are
 * about what is written on rows and read back by other components, and a mock
 * would happily agree with a wrong column name.
 *
 * The queue is `sync` in tests, so a delegation runs its child inline. That
 * makes the tests read like the sequence they describe -- and it exercises the
 * hardest ordering in the feature, where the child finishes before the parent's
 * tool call has finished returning.
 */
trait MakesDelegations
{
    use MakesTools;

    /**
     * A parent agent that may delegate, and a child agent it may delegate to.
     *
     * @param list<string> $parentTools tool references the parent may request
     * @param list<string> $childTools tool references the child agent may request
     * @return array{0: Agent, 1: Agent}
     */
    public function makeDelegationPair(
        array $parentTools = ['delegate_to_agent'],
        array $childTools = [],
        ?string $childSlug = 'specialist',
    ): array {
        $child = $this->makeAgent([
            'name' => 'Specialist',
            'slug' => $childSlug,
            'tool_policy' => ['allow' => $childTools],
        ]);

        $parent = $this->agent();
        $parent->forceFill([
            'tool_policy' => ['allow' => $parentTools],
            'delegation_policy' => ['allow' => [$childSlug]],
        ])->save();

        return [$parent, $child];
    }

    /**
     * Register the delegation tool alongside whatever else a test needs.
     *
     * @param list<Tool|class-string<Tool>> $tools
     */
    public function registerDelegationTools(array $tools = []): void
    {
        $this->registerTools([DelegateToAgentTool::class, ...$tools]);
    }

    /**
     * The tool call a model makes to delegate.
     */
    public function delegateCall(
        string $agent = 'specialist',
        string $instruction = 'Please look into this and report back.',
        string $id = 'call_delegate',
    ): ToolCall {
        return new ToolCall($id, 'delegate_to_agent', [
            'agent' => $agent,
            'instruction' => $instruction,
        ]);
    }

    /**
     * Run the parent agent in a conversation, synchronously.
     */
    public function runParent(string $input = 'Handle this for me.'): Run
    {
        return app(AgentRunner::class)
            ->agent($this->agent())
            ->forUser($this->toolUser())
            ->inConversation($this->makeConversation($this->agent()))
            ->run($input);
    }

    /**
     * The single child run produced by a delegation.
     */
    public function childOf(Run $parent): ?Run
    {
        /** @var Run|null $child */
        $child = Run::query()->where('parent_run_id', $parent->getKey())->first();

        return $child;
    }
}
