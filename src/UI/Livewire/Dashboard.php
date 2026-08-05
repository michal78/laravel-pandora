<?php

declare(strict_types=1);

namespace Pandora\Pandora\UI\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use Pandora\Pandora\Agents\AgentRegistry;
use Pandora\Pandora\Conversations\Conversation;
use Pandora\Pandora\Pandora;
use Pandora\Pandora\Runs\Enums\RunState;
use Pandora\Pandora\Runs\Run;
use Pandora\Pandora\UI\PandoraGate;

/**
 * Phase 1 dashboard: status, counts and recent activity.
 *
 * Deliberately small. The remaining panels described in the architecture
 * overview arrive with the features they report on -- a dashboard tile for a
 * subsystem that does not exist yet would be a placeholder that silently
 * succeeds, which this project does not ship.
 */
final class Dashboard extends Component
{
    public function mount(): void
    {
        PandoraGate::authorize('access');
    }

    public function render(): View
    {
        $registry = app(AgentRegistry::class);

        return view('pandora::livewire.dashboard', [
            'version' => app(Pandora::class)->version(),
            'agentCount' => $registry->all()->count(),
            'enabledAgentCount' => $registry->enabled()->count(),
            'activeRuns' => Run::query()->active()->count(),
            'failedRuns' => Run::query()->whereIn('state', [
                RunState::Failed->value,
                RunState::TimedOut->value,
            ])->count(),
            'completedRuns' => Run::query()->where('state', RunState::Completed->value)->count(),
            'conversationCount' => Conversation::query()->active()->count(),
            'recentRuns' => Run::query()->latest('created_at')->limit(10)->get(),
            'recentConversations' => Conversation::query()
                ->active()
                ->orderByDesc('last_activity_at')
                ->limit(8)
                ->get(),
            'realtimeEnabled' => (bool) config('pandora.realtime.enabled', true),
            'defaultProvider' => (string) config('pandora.providers.default'),
            'defaultModel' => (string) config('pandora.models.default'),
        ])->layout('pandora::layouts.app', ['title' => 'Dashboard']);
    }
}
