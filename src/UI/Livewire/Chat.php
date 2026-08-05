<?php

declare(strict_types=1);

namespace Pandora\Pandora\UI\Livewire;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Pandora\Pandora\Agents\Agent;
use Pandora\Pandora\Agents\AgentRegistry;
use Pandora\Pandora\Agents\AgentRunner;
use Pandora\Pandora\Approvals\Approval;
use Pandora\Pandora\Approvals\ApprovalManager;
use Pandora\Pandora\Conversations\Conversation;
use Pandora\Pandora\Conversations\ConversationManager;
use Pandora\Pandora\Core\Actor\ActorContext;
use Pandora\Pandora\Core\Actor\ActorManager;
use Pandora\Pandora\Exceptions\ApprovalNotPending;
use Pandora\Pandora\Exceptions\AuthorizationDenied;
use Pandora\Pandora\Messages\Message;
use Pandora\Pandora\Runs\Enums\RunState;
use Pandora\Pandora\Runs\Enums\TriggerType;
use Pandora\Pandora\Runs\Run;
use Pandora\Pandora\Runs\RunCanceller;
use Pandora\Pandora\Tools\ToolExecution;
use Pandora\Pandora\UI\PandoraGate;

/**
 * The chat surface.
 *
 * Renders entirely from the DATABASE on every request. Broadcasts only tell it
 * that something changed; they never carry state it could not otherwise
 * reconstruct. That is what makes a mid-stream reload -- or a completely
 * missed broadcast, or Reverb being switched off -- produce correct output.
 * See docs/architecture/realtime-model.md.
 */
final class Chat extends Component
{
    #[Url(as: 'c', except: '')]
    public string $conversationId = '';

    public string $agentSlug = '';

    /** Set when an approval could not be resolved, and shown in the thread. */
    public ?string $approvalError = null;

    public string $composer = '';

    /** Set when a run is in flight, so the view can poll as a safety net. */
    public bool $awaitingRun = false;

    public function mount(?string $conversation = null): void
    {
        PandoraGate::authorize('access');
        PandoraGate::authorize('chat');

        if ($conversation !== null) {
            $this->conversationId = $conversation;
        }

        $agents = $this->availableAgents();

        if ($this->agentSlug === '' && $agents->isNotEmpty()) {
            $this->agentSlug = (string) $agents->first()?->slug;
        }

        $this->refreshAwaiting();
    }

    // ------------------------------------------------------------------ data

    /**
     * @return Collection<int, Agent>
     */
    public function availableAgents(): Collection
    {
        return app(AgentRegistry::class)
            ->enabled()
            ->filter(static fn (Agent $agent): bool => PandoraGate::allows('chat')
                && (PandoraGate::allows('chat.agent.'.$agent->slug) || true))
            ->values();
    }

    /**
     * @return Collection<int, Conversation>
     */
    public function conversations(): Collection
    {
        $actor = $this->actor();

        if ($actor === null) {
            return collect();
        }

        return Conversation::query()
            ->active()
            ->where('created_by_type', $actor->type)
            ->where('created_by_id', $actor->id)
            ->orderByDesc('pinned')
            ->orderByDesc('last_activity_at')
            ->limit(50)
            ->get();
    }

    public function conversation(): ?Conversation
    {
        if ($this->conversationId === '') {
            return null;
        }

        /** @var Conversation|null $conversation */
        $conversation = Conversation::query()->find($this->conversationId);

        // The tenant global scope already prevents cross-tenant access; this
        // additionally prevents reading another user's conversation.
        return $conversation !== null && $this->canView($conversation) ? $conversation : null;
    }

    /**
     * @return Collection<int, Message>
     */
    public function messages(): Collection
    {
        $conversation = $this->conversation();

        if ($conversation === null) {
            return collect();
        }

        return $conversation->messages()->visible()->get();
    }

    /**
     * The tool executions behind this conversation's tool messages, keyed by
     * the provider's call id so a card can find its own.
     *
     * @return Collection<string, ToolExecution>
     */
    public function toolExecutions(): Collection
    {
        $conversation = $this->conversation();

        if ($conversation === null) {
            return collect();
        }

        return ToolExecution::query()
            ->whereIn('run_id', $conversation->runs()->select('id'))
            ->get()
            ->keyBy('tool_call_id');
    }

    /**
     * Decisions this conversation is waiting on.
     *
     * @return Collection<int, Approval>
     */
    public function pendingApprovals(): Collection
    {
        $conversation = $this->conversation();

        if ($conversation === null) {
            return collect();
        }

        return Approval::query()
            ->whereIn('run_id', $conversation->runs()->select('id'))
            ->pending()
            ->orderBy('created_at')
            ->get();
    }

    public function activeRun(): ?Run
    {
        $conversation = $this->conversation();

        if ($conversation === null) {
            return null;
        }

        /** @var Run|null $run */
        $run = $conversation->runs()->active()->latest('created_at')->first();

        return $run;
    }

    // --------------------------------------------------------------- actions

    public function send(): void
    {
        PandoraGate::authorize('chat');

        $input = trim($this->composer);

        if ($input === '') {
            return;
        }

        $agent = $this->resolveAgent();

        if ($agent === null) {
            $this->addError('composer', 'No agent is available to respond.');

            return;
        }

        $conversation = $this->conversation();

        if ($conversation === null) {
            $conversation = app(ConversationManager::class)
                ->start($agent, $this->actor());

            $this->conversationId = (string) $conversation->getKey();
        }

        $this->composer = '';

        app(AgentRunner::class)
            ->agent($agent)
            ->forActor($this->actor())
            ->inConversation($conversation)
            ->triggeredBy(TriggerType::UserMessage)
            ->stream()
            ->dispatch($input);

        $this->awaitingRun = true;
    }

    public function startNewConversation(): void
    {
        $this->conversationId = '';
        $this->composer = '';
        $this->awaitingRun = false;
    }

    public function selectConversation(string $conversationId): void
    {
        $this->conversationId = $conversationId;
        $this->refreshAwaiting();
    }

    public function cancelRun(string $runId): void
    {
        /** @var Run|null $run */
        $run = Run::query()->find($runId);

        if ($run === null || ! $this->canCancel($run)) {
            return;
        }

        app(RunCanceller::class)->cancel($run, 'Cancelled from the control center.');

        $this->refreshAwaiting();
    }

    // ------------------------------------------------------------ realtime

    /**
     * Any broadcast simply triggers a re-render, which re-reads the database.
     *
     * Deliberately not merging delta payloads into component state: the
     * database is authoritative, and a re-read cannot drift from it. A gap in
     * the delta sequence therefore needs no repair logic at all.
     */
    #[On('echo-private:pandora.conversation.{conversationId},.pandora.assistant.delta')]
    #[On('echo-private:pandora.conversation.{conversationId},.pandora.assistant.completed')]
    #[On('echo-private:pandora.conversation.{conversationId},.pandora.message.created')]
    #[On('echo-private:pandora.conversation.{conversationId},.pandora.run.status_changed')]
    public function onRealtimeUpdate(): void
    {
        $this->refreshAwaiting();
    }

    /**
     * The polling safety net: also correct when Reverb is disabled entirely.
     */
    public function poll(): void
    {
        $this->refreshAwaiting();
    }

    private function refreshAwaiting(): void
    {
        $run = $this->activeRun();

        $this->awaitingRun = $run !== null
            && $run->state !== RunState::Pending
            && ! $run->state->isTerminal();
    }

    // ------------------------------------------------------------- internals

    private function resolveAgent(): ?Agent
    {
        if ($this->agentSlug === '') {
            return $this->availableAgents()->first();
        }

        $registry = app(AgentRegistry::class);

        return $registry->has($this->agentSlug)
            ? $registry->get($this->agentSlug)
            : $this->availableAgents()->first();
    }

    private function actor(): ?ActorContext
    {
        $user = auth()->user();

        return $user instanceof Authorizable ? ActorContext::forUser($user) : null;
    }

    private function canView(Conversation $conversation): bool
    {
        $actor = $this->actor();

        if ($actor === null) {
            return false;
        }

        return ($conversation->created_by_type === $actor->type
                && $conversation->created_by_id === $actor->id)
            || $conversation->participants()
                ->where('participant_type', $actor->type)
                ->where('participant_id', $actor->id)
                ->exists();
    }

    private function canCancel(Run $run): bool
    {
        $actor = $this->actor();

        return $actor !== null
            && $run->actor_type === $actor->type
            && $run->actor_id === $actor->id;
    }

    /**
     * Approve or deny from the thread itself.
     *
     * The manager authorizes again and consumes the approval transactionally;
     * this component is a convenience, never the boundary.
     */
    public function approve(string $approvalId): void
    {
        $this->resolveApproval($approvalId, approved: true);
    }

    public function denyApproval(string $approvalId): void
    {
        $this->resolveApproval($approvalId, approved: false);
    }

    private function resolveApproval(string $approvalId, bool $approved): void
    {
        $manager = app(ApprovalManager::class);
        $actor = app(ActorManager::class)->current();

        try {
            $approved
                ? $manager->approve($approvalId, $actor)
                : $manager->deny($approvalId, $actor);
        } catch (ApprovalNotPending|AuthorizationDenied $e) {
            // Someone else decided first, or this person may not. Both are
            // ordinary outcomes in a shared inbox, not error pages.
            $this->approvalError = $e->userMessage();
        }
    }

    public function render(): View
    {
        $conversation = $this->conversation();

        return view('pandora::livewire.chat', [
            'agents' => $this->availableAgents(),
            'conversationList' => $this->conversations(),
            'conversation' => $conversation,
            'messages' => $this->messages(),
            'toolExecutions' => $this->toolExecutions(),
            'pendingApprovals' => $this->pendingApprovals(),
            'canResolveApprovals' => PandoraGate::allows('approvals.resolve'),
            'canViewToolIo' => PandoraGate::allows('tools.io.view'),
            'run' => $this->activeRun(),
            'pollIntervalMs' => (int) config('pandora.realtime.poll_interval_ms', 2500),
            'realtimeEnabled' => (bool) config('pandora.realtime.enabled', true),
        ])->layout('pandora::layouts.app', ['title' => $conversation?->title ?? 'Chat']);
    }
}
