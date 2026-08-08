<?php

declare(strict_types=1);

use Pandora\Messages\Enums\MessageRole;
use Pandora\Providers\Data\ChatMessage;
use Pandora\Providers\Data\ToolCall;
use Pandora\Runs\Enums\RunState;
use Pandora\Tests\Fixtures\Tools\LookupOrderTool;
use Pandora\Tests\Support\MakesDelegations;
use Pandora\Tools\ToolExecution;

/**
 * A delegated child must be able to see its own tool loop.
 *
 * A child run has no conversation, deliberately -- its working notes are not
 * threaded into the thread a human is reading. But the model's history came
 * from those messages and from nowhere else, so a child rebuilt its context
 * from scratch on every iteration: it never saw the call it had just made, nor
 * the answer it got.
 *
 * The failure that produces is the expensive kind. The child calls a tool,
 * cannot see the result, makes the identical call again, is refused as a
 * duplicate, cannot see that refusal either, and repeats until the iteration
 * budget ends the run -- with every guard along the way behaving exactly as
 * designed. The parent is then told the tree budget is exhausted, which is
 * true, and completely misleading about why.
 *
 * Found by driving Phase 6 in a browser against a live model, not by the
 * suite: every delegation test until now scripted a child that answered on its
 * first turn, so nothing ever asked what the child could remember.
 */
uses(MakesDelegations::class);

beforeEach(function (): void {
    $this->registerDelegationTools([LookupOrderTool::class]);
});

it('shows the child its own tool result on the next iteration', function (): void {
    // The intersection is what the child ends up holding, so the parent must
    // hold the lookup tool too -- delegation narrows, it never widens.
    $this->makeDelegationPair(
        parentTools: ['delegate_to_agent', 'lookup_order'],
        childTools: ['lookup_order'],
    );

    $this->fakeProvider()
        // 1. The parent hands the work over.
        ->willRequestTools([$this->delegateCall()])
        // 2. The child looks the order up.
        ->willRequestTools([$this->lookupCall('ORD-7788')])
        // 3. The child answers -- and this is the request under test.
        ->willRespondWith('ORD-7788 is shipped.')
        // 4. The parent reports what came back.
        ->willRespondWith('The specialist says ORD-7788 is shipped.');

    $parentRun = $this->runParent('Find out about ORD-7788.');

    $requests = $this->fakeProvider()->receivedRequests();

    // The child's SECOND turn: the one that has to carry the loop.
    $messages = $requests[2]->messages;

    $toolResults = array_values(array_filter(
        $messages,
        static fn (ChatMessage $m): bool => $m->role === MessageRole::Tool,
    ));

    $toolRequests = array_values(array_filter(
        $messages,
        static fn (ChatMessage $m): bool => $m->requestsTools(),
    ));

    expect($toolResults)->toHaveCount(1)
        ->and($toolResults[0]->content)->toContain('ORD-7788 is shipped')
        ->and($toolResults[0]->toolCallId)->toBe('call_1')
        // The result never travels without the call that asked for it: every
        // provider rejects the orphan.
        ->and($toolRequests)->toHaveCount(1)
        ->and($toolRequests[0]->toolCalls[0]->name)->toBe('lookup_order')
        ->and($toolRequests[0]->toolCalls[0]->id)->toBe('call_1')
        ->and($parentRun->state)->toBe(RunState::Completed);
});

it('does not repeat a call it has already made', function (): void {
    $this->makeDelegationPair(
        parentTools: ['delegate_to_agent', 'lookup_order'],
        childTools: ['lookup_order'],
    );

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall()])
        ->willRequestTools([$this->lookupCall('ORD-7788')])
        ->willRespondWith('ORD-7788 is shipped.')
        ->willRespondWith('Shipped.');

    $parentRun = $this->runParent('Find out about ORD-7788.');
    $child = $this->childOf($parentRun);

    // One call, one execution row. A child that could not see its own history
    // produced a row per iteration until the budget ran out, each after the
    // first refused as a duplicate.
    expect($child?->state)->toBe(RunState::Completed)
        ->and(ToolExecution::query()->where('run_id', $child?->getKey())->count())
        ->toBe(1);
});

it('tells the child why a call was refused, not just that it was', function (): void {
    // A refusal decided before the tool ran has no result and no error -- only
    // the sentence on the row. If that sentence does not reach the model, the
    // model is told "No result." and tries again.
    $this->makeDelegationPair(
        parentTools: ['delegate_to_agent', 'lookup_order'],
        childTools: ['lookup_order'],
    );

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall()])
        ->willRequestTools([$this->lookupCall('ORD-7788')])
        // The same call again, under a new id -- which is how a model repeats
        // itself. The arguments are what the duplicate guard matches on.
        ->willRequestTools([new ToolCall('call_2', 'lookup_order', ['reference' => 'ORD-7788'])])
        ->willRespondWith('Shipped, as I already found.')
        ->willRespondWith('Shipped.');

    $this->runParent('Find out about ORD-7788.');

    $requests = $this->fakeProvider()->receivedRequests();
    $messages = $requests[3]->messages;

    $contents = array_map(
        static fn (ChatMessage $m): string => $m->content,
        array_values(array_filter(
            $messages,
            static fn (ChatMessage $m): bool => $m->role === MessageRole::Tool,
        )),
    );

    expect(implode("\n", $contents))->toContain('already made in this run')
        ->and(implode("\n", $contents))->not->toContain('No result.');
});
