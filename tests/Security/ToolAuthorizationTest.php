<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Pandora\Core\Actor\ActorContext;
use Pandora\Tests\Fixtures\TestUser;
use Pandora\Tests\Fixtures\Tools\GatedTool;
use Pandora\Tests\Fixtures\Tools\LookupOrderTool;
use Pandora\Tests\Fixtures\Tools\RefundOrderTool;
use Pandora\Tests\Support\MakesTools;
use Pandora\Tools\Enums\AuthorizationLayer;
use Pandora\Tools\ToolContext;

/**
 * Phase 2 acceptance criteria 9 and 10 — layer 5, and the single most
 * important safety property in the system.
 *
 * An agent acts for a person. It must not be able to do something that person
 * could not do themselves, no matter what the agent is configured to reach,
 * what the tenant permits, or what a policy says. Every other layer can be
 * misconfigured by an operator; this one is written in the host's own code,
 * against the host's own gates.
 *
 * See docs/adr/0007-tools-are-classes-with-laravel-authorization.md.
 */
uses(MakesTools::class);

beforeEach(function (): void {
    $this->registerTools([GatedTool::class, LookupOrderTool::class, RefundOrderTool::class]);
    // Every other layer allows, throughout this file. What remains is layer 5.
    $this->agentAllows(['gated_action', 'lookup_order', 'refund_order']);
    $this->agentApprovalPolicy(['auto_approve' => ['gated_action', 'refund_order']]);
});

it('denies a tool the acting user gate refuses', function (): void {
    Gate::define('manage-orders', static fn (): bool => false);

    $decision = $this->decide(GatedTool::call());

    expect($decision->isDenied())->toBeTrue()
        ->and($decision->layer)->toBe(AuthorizationLayer::Tool);
});

it('allows the same tool for a user the gate permits', function (): void {
    Gate::define('manage-orders', static fn (): bool => true);

    expect($this->decide(GatedTool::call())->isAllowed())->toBeTrue();
});

it('authorizes the actor, not the agent — two users, one agent, two answers', function (): void {
    /** @var TestUser $permitted */
    $permitted = TestUser::create([
        'name' => 'Manager', 'email' => 'manager@example.test', 'password' => 'secret',
    ]);
    /** @var TestUser $refused */
    $refused = TestUser::create([
        'name' => 'Intern', 'email' => 'intern@example.test', 'password' => 'secret',
    ]);

    Gate::define('manage-orders', static fn (TestUser $user): bool => $user->email === 'manager@example.test');

    expect($this->decide(GatedTool::call(), $this->toolContext(ActorContext::forUser($permitted)))->isAllowed())
        ->toBeTrue()
        ->and($this->decide(GatedTool::call(), $this->toolContext(ActorContext::forUser($refused)))->isDenied())
        ->toBeTrue();
});

it('denies a system actor any tool whose authorization needs a user', function (): void {
    // An automation, a webhook, a delegating run: no Authorizable, therefore
    // no authority. Silence must not read as permission.
    Gate::define('manage-orders', static fn (): bool => true);

    $context = $this->toolContext(ActorContext::system('scheduled-automation'));

    expect($this->decide(GatedTool::call(), $context)->isDenied())->toBeTrue()
        ->and($this->decide($this->refundCall(), $context)->layer)->toBe(AuthorizationLayer::Tool);
});

it('denies a run with no actor at all', function (): void {
    Gate::define('manage-orders', static fn (): bool => true);

    $context = new ToolContext(
        run: $this->makeRun(['agent_id' => $this->agent()->getKey()]),
        agent: $this->agent(),
        session: $this->makeSession($this->agent()),
        actor: null,
        toolCallId: 'call_1',
    );

    expect($this->decide(GatedTool::call(), $context)->isDenied())->toBeTrue();
});

it('defaults to denial when a tool author forgets to write authorize()', function (): void {
    // The base implementation permits only a low-risk tool acting for a real
    // user, so an omission is a visibly broken tool rather than a quiet hole.
    expect($this->decide($this->lookupCall())->isAllowed())->toBeTrue()
        ->and($this->decide($this->refundCall(), $this->toolContext(
            ActorContext::system('automation'),
        ))->isDenied())->toBeTrue();
});

it('checks authorization against the modified arguments, not the requested ones', function (): void {
    // A policy that widens a call must not thereby widen what was authorized.
    Gate::define('manage-orders', static fn (): bool => true);

    $decision = $this->decide(GatedTool::call(reference: 'FORBIDDEN'));

    expect($decision->isDenied())->toBeTrue()
        ->and($decision->layer)->toBe(AuthorizationLayer::Tool);
});

it('records the denial without leaking why the gate said no', function (): void {
    Gate::define('manage-orders', static fn (): bool => false);

    $decision = $this->decide(GatedTool::call());

    expect($decision->modelMessage())->toContain('gated_action')
        // The model learns it may not; it never learns the rule it tripped.
        ->and($decision->modelMessage())->not->toContain('manage-orders');
});
