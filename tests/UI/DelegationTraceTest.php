<?php

declare(strict_types=1);

use Livewire\Livewire;
use Pandora\Tests\Fixtures\Tools\LookupOrderTool;
use Pandora\Tests\Support\MakesDelegations;
use Pandora\UI\Livewire\RunDetail;

/**
 * Delegation, visible in the control center.
 *
 * A child run reached from the runs list was a run with no visible reason to
 * exist: nothing named the parent, nothing named the agent that asked, and the
 * intersection that decided what it was allowed to do was on the row and on no
 * page. The question an incident actually asks is "what was this run allowed to
 * do, and who asked for it", and the answer was reachable only from a database
 * client.
 */
uses(MakesDelegations::class);

beforeEach(function (): void {
    $this->registerDelegationTools([LookupOrderTool::class]);
    $this->actingAsUser($this->toolUser());
});

it('names the parent, the agent that asked, and links back to it', function (): void {
    $this->makeDelegationPair(
        parentTools: ['delegate_to_agent', 'lookup_order'],
        childTools: ['lookup_order'],
    );

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall()])
        ->willRespondWith('ORD-7788 is shipped.')
        ->willRespondWith('The specialist says it shipped.');

    $parent = $this->runParent('Find out about ORD-7788.');
    $child = $this->childOf($parent);

    $html = Livewire::test(RunDetail::class, ['run' => (string) $child->getKey()])->html();

    expect($html)->toContain('Delegation')
        ->and($html)->toContain('Delegated by')
        ->and($html)->toContain(route('pandora.runs.show', $parent->getKey()));
});

it('shows the child what it was allowed to call, frozen at delegation time', function (): void {
    $this->makeDelegationPair(
        parentTools: ['delegate_to_agent', 'lookup_order'],
        childTools: ['lookup_order'],
    );

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall()])
        ->willRespondWith('ORD-7788 is shipped.')
        ->willRespondWith('The specialist says it shipped.');

    $parent = $this->runParent('Find out about ORD-7788.');
    $child = $this->childOf($parent);

    $html = Livewire::test(RunDetail::class, ['run' => (string) $child->getKey()])->html();

    expect($html)->toContain('Allowed to call')
        ->and($html)->toContain('lookup_order')
        // The parent held `delegate_to_agent`; the child agent does not, so the
        // intersection must not carry it down.
        ->and($html)->not->toContain('delegate_to_agent');
});

it('lists the children on the parent, with their state', function (): void {
    $this->makeDelegationPair(
        parentTools: ['delegate_to_agent', 'lookup_order'],
        childTools: ['lookup_order'],
    );

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall()])
        ->willRespondWith('ORD-7788 is shipped.')
        ->willRespondWith('The specialist says it shipped.');

    $parent = $this->runParent('Find out about ORD-7788.');
    $child = $this->childOf($parent);

    $html = Livewire::test(RunDetail::class, ['run' => (string) $parent->getKey()])->html();

    expect($html)->toContain('Delegated to')
        ->and($html)->toContain(route('pandora.runs.show', $child->getKey()));
});

it('says nothing about delegation on a run that has none', function (): void {
    $this->registerDelegationTools();
    $this->agentAllows(['lookup_order']);

    $this->fakeProvider()->willRespondWith('Nothing to hand over.');

    $run = $this->runToolAgent('Hello.');

    $html = Livewire::test(RunDetail::class, ['run' => (string) $run->getKey()])->html();

    expect($html)->not->toContain('Delegated by')
        ->and($html)->not->toContain('Delegated to');
});
