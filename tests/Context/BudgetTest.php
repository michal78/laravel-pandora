<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Pandora\Agents\Agent;
use Pandora\Context\ContextBuilder;
use Pandora\Context\ContextRequest;
use Pandora\Context\ContextSection;
use Pandora\Contracts\ContextProvider;
use Pandora\Conversations\Session;
use Pandora\Providers\Data\ChatMessage;
use Pandora\Runs\Enums\RunState;
use Pandora\Runs\Run;
use Pandora\Tests\Fixtures\AgentFactory;

/**
 * Phase 5, criterion 21 -- a section that does not fit is dropped, never cut.
 *
 * Truncation is the tempting behaviour and the wrong one. Half a memory reads
 * as a whole memory, half a document reads as a document that stops mid
 * sentence, and neither leaves a mark: the answer is subtly wrong and the
 * trace says everything was included. An omission is legible.
 */
final class FixedSizeProvider implements ContextProvider
{
    public function __construct(
        private readonly string $sectionKey,
        private readonly int $characters,
    ) {}

    public function key(): string
    {
        return $this->sectionKey;
    }

    public function provide(ContextRequest $request): ?ContextSection
    {
        return ContextSection::of(
            $this->sectionKey,
            [ChatMessage::system(str_repeat('x', $this->characters))],
        );
    }
}

final class SilentProvider implements ContextProvider
{
    public function key(): string
    {
        return 'silent';
    }

    public function provide(ContextRequest $request): ?ContextSection
    {
        return null;
    }
}

function contextRequest(int $budget): ContextRequest
{
    /** @var Agent $agent */
    $agent = AgentFactory::database(['slug' => 'budgeted']);

    /** @var Session $session */
    $session = Session::query()->create([
        'agent_id' => $agent->getKey(),
        'channel' => 'web',
        'origin' => 'test',
        'isolation_key' => (string) Str::ulid(),
    ]);

    /** @var Run $run */
    $run = Run::query()->create([
        'agent_id' => $agent->getKey(),
        'session_id' => $session->getKey(),
        'state' => RunState::Running->value,
        'trigger_type' => 'user_message',
        'correlation_id' => (string) Str::ulid(),
    ]);

    return new ContextRequest($run, $agent, $session, $budget);
}

it('drops a section that does not fit and records why', function (): void {
    // ~25 tokens each at 4 characters per token; budget fits the first only.
    $builder = new ContextBuilder(app(), [
        FixedSizeProvider::class,
    ]);

    app()->bind(FixedSizeProvider::class, fn () => new FixedSizeProvider('big', 400));

    $context = $builder->build(contextRequest(50));

    expect($context->omitted)->toBe([['key' => 'big', 'reason' => 'budget_exhausted']])
        ->and($context->messages)->toBe([])
        ->and($context->estimatedTokens)->toBe(0);
});

it('never truncates a section it did include', function (): void {
    app()->bind(FixedSizeProvider::class, fn () => new FixedSizeProvider('fits', 400));

    $builder = new ContextBuilder(app(), [FixedSizeProvider::class]);
    $context = $builder->build(contextRequest(1000));

    expect($context->messages)->toHaveCount(1)
        ->and(strlen($context->messages[0]->content))->toBe(400)
        ->and($context->omitted)->toBe([]);
});

it('records a provider with nothing to say as an omission, not a failure', function (): void {
    $builder = new ContextBuilder(app(), [SilentProvider::class]);
    $context = $builder->build(contextRequest(1000));

    expect($context->omitted)->toBe([['key' => 'silent', 'reason' => 'no_content']])
        ->and($context->messages)->toBe([]);
});

it('keeps spending the budget on later providers after dropping one', function (): void {
    // The ordering property that matters: a fat section is skipped, and a
    // thin one behind it still gets in. A builder that stopped at the first
    // refusal would silently discard everything downstream of it.
    app()->bind('big-provider', fn () => new FixedSizeProvider('big', 4000));
    app()->bind('small-provider', fn () => new FixedSizeProvider('small', 40));

    $builder = new ContextBuilder(app(), ['big-provider', 'small-provider']);
    $context = $builder->build(contextRequest(100));

    expect(array_column($context->included, 'key'))->toBe(['small'])
        ->and(array_column($context->omitted, 'key'))->toBe(['big']);
});

it('reports the budget and what was spent on the trace', function (): void {
    app()->bind(FixedSizeProvider::class, fn () => new FixedSizeProvider('fits', 400));

    $builder = new ContextBuilder(app(), [FixedSizeProvider::class]);
    $trace = $builder->build(contextRequest(1000))->toTrace();

    expect($trace['budget'])->toBe(1000)
        ->and($trace['estimated_tokens'])->toBe(100)
        ->and($trace['message_count'])->toBe(1)
        ->and($trace['included'])->toBe([['key' => 'fits', 'tokens' => 100, 'messages' => 1]]);
});

it('redacts a credential a user pasted into context', function (): void {
    app()->bind('leaky', fn () => new class implements ContextProvider
    {
        public function key(): string
        {
            return 'leaky';
        }

        public function provide(ContextRequest $request): ContextSection
        {
            return ContextSection::of('leaky', [
                ChatMessage::user('is sk-abcdefghijklmnopqrstuvwxyz still valid?'),
            ]);
        }
    });

    $builder = new ContextBuilder(app(), ['leaky']);
    $context = $builder->build(contextRequest(1000));

    expect($context->messages[0]->content)->not->toContain('sk-abcdefghijklmnopqrstuvwxyz')
        ->and($context->messages[0]->content)->toContain('[redacted]');
});
