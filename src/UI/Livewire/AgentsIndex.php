<?php

declare(strict_types=1);

namespace Pandora\UI\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;
use Pandora\Agents\Agent;
use Pandora\Agents\AgentRegistry;
use Pandora\Audit\AuditLogger;
use Pandora\Runs\Enums\AutonomyLevel;
use Pandora\UI\PandoraGate;

/**
 * Every agent this deployment can run, and where each one came from.
 *
 * Two levels. `pandora.access` may read the roster -- who exists, on which
 * model, at which autonomy level -- because that is what somebody looking at a
 * run wants to know about the thing that produced it. Creating an agent needs
 * `pandora.agents.manage`, because an agent row decides which tools a language
 * model can reach.
 *
 * Only database-defined agents can be created here. A class definition lives
 * in the host application's version control, and inventing one from a web form
 * would produce a row that the next deploy has no idea about.
 */
final class AgentsIndex extends Component
{
    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'source', except: '')]
    public string $sourceFilter = '';

    public bool $creating = false;

    public string $newName = '';

    public string $newDescription = '';

    public ?string $error = null;

    public function mount(): void
    {
        PandoraGate::authorize('access');
    }

    public function startCreating(): void
    {
        PandoraGate::authorize('agents.manage');

        $this->creating = true;
        $this->newName = '';
        $this->newDescription = '';
        $this->error = null;
        $this->resetValidation();
    }

    public function cancelCreating(): void
    {
        $this->creating = false;
        $this->error = null;
        $this->resetValidation();
    }

    /**
     * Create a database-defined agent.
     *
     * Deliberately minimal: a name, and nothing else. Everything that decides
     * what the agent can actually do -- instructions, model, tools, autonomy --
     * is set on the detail page, where each field carries the explanation it
     * needs. A create form that asked for all of it at once would be answered
     * by guessing.
     *
     * The new agent starts disabled and at the most restrictive autonomy the
     * configuration allows. An agent that could act the moment it was named
     * would make a typo into an incident.
     */
    public function create(AuditLogger $audit): void
    {
        PandoraGate::authorize('agents.manage');

        $this->validate([
            'newName' => ['required', 'string', 'min:2', 'max:120'],
            'newDescription' => ['nullable', 'string', 'max:500'],
        ], attributes: [
            'newName' => 'name',
            'newDescription' => 'description',
        ]);

        $slug = $this->uniqueSlug(Str::slug($this->newName));

        if ($slug === null) {
            $this->error = 'That name does not produce a usable slug. Try one with letters or digits in it.';

            return;
        }

        $agent = Agent::query()->create([
            'name' => trim($this->newName),
            'slug' => $slug,
            'description' => $this->newDescription === '' ? null : trim($this->newDescription),
            'enabled' => false,
            'autonomy_level' => AutonomyLevel::ObserveOnly->value,
        ]);

        $audit->record(
            action: 'agent.created',
            targetType: 'agent',
            targetId: $agent->id,
            metadata: ['slug' => $agent->slug, 'name' => $agent->name],
        );

        $this->creating = false;
        $this->redirectRoute('pandora.agents.show', ['agent' => $agent->slug], navigate: true);
    }

    public function render(AgentRegistry $registry): View
    {
        // Through the registry rather than the model: this is the moment a
        // newly deployed class definition should appear, and syncing on read
        // is what makes the page tell the truth after a deploy.
        $agents = $registry->all();

        if ($this->sourceFilter !== '') {
            $wantsClass = $this->sourceFilter === 'class';

            $agents = $agents->filter(
                static fn (Agent $agent): bool => $agent->isClassDefined() === $wantsClass,
            )->values();
        }

        if ($this->search !== '') {
            $needle = Str::lower($this->search);

            $agents = $agents->filter(static fn (Agent $agent): bool => str_contains(Str::lower($agent->name), $needle)
                || str_contains(Str::lower($agent->slug), $needle)
                || str_contains(Str::lower($agent->description ?? ''), $needle))->values();
        }

        return view('pandora::livewire.agents-index', [
            'agents' => $agents,
            'runCounts' => $this->runCounts($agents),
            'canManage' => PandoraGate::allows('agents.manage'),
            'registry' => $registry,
        ])->layout('pandora::layouts.app', ['title' => 'Agents']);
    }

    /**
     * @param Collection<int, Agent> $agents
     * @return array<string, int>
     */
    private function runCounts(mixed $agents): array
    {
        $ids = $agents->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        /** @var array<string, int> $counts */
        $counts = Agent::query()
            ->whereIn('id', $ids)
            ->withCount('runs')
            ->pluck('runs_count', 'id')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();

        return $counts;
    }

    /**
     * A slug nobody in this tenant is using, suffixed if the obvious one is
     * taken. Two people naming an agent "Support" should both succeed.
     */
    private function uniqueSlug(string $base): ?string
    {
        if ($base === '') {
            return null;
        }

        $slug = $base;

        for ($suffix = 2; $suffix < 100; $suffix++) {
            if (! Agent::query()->withTrashed()->where('slug', $slug)->exists()) {
                return $slug;
            }

            $slug = $base.'-'.$suffix;
        }

        return null;
    }
}
