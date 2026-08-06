<?php

declare(strict_types=1);

namespace Pandora\Pandora\UI\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;
use Pandora\Pandora\Exceptions\PandoraException;
use Pandora\Pandora\Memory\Enums\MemoryScope;
use Pandora\Pandora\Memory\Enums\MemorySensitivity;
use Pandora\Pandora\Memory\Enums\MemoryStatus;
use Pandora\Pandora\Memory\Enums\MemoryType;
use Pandora\Pandora\Memory\MemoryCurator;
use Pandora\Pandora\Memory\MemoryItem;
use Pandora\Pandora\UI\PandoraGate;

/**
 * Everything the agents believe, and the queue of things they would like to.
 *
 * The review queue is first on the page, not behind a filter. A suggestion
 * nobody looks at is a memory that never becomes useful, and an approval
 * workflow whose queue is hard to find is an approval workflow that gets
 * clicked through in bulk.
 *
 * Content is shown in full rather than truncated. Truncating the one column
 * that says what an agent will repeat about somebody makes the review
 * meaningless -- the reviewer would be approving a preview.
 *
 * Reading needs `pandora.access`; approving, rejecting and forgetting need
 * `pandora.memory.manage`, checked here and again in `MemoryCurator`.
 */
final class MemoryIndex extends Component
{
    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'scope', except: '')]
    public string $scopeFilter = '';

    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    public ?string $notice = null;

    public ?string $error = null;

    public function mount(): void
    {
        PandoraGate::authorize('access');
    }

    public function approve(string $id): void
    {
        $this->act($id, static fn (MemoryCurator $curator, MemoryItem $item) => $curator->approve($item), 'Approved.');
    }

    public function reject(string $id): void
    {
        $this->act($id, static fn (MemoryCurator $curator, MemoryItem $item) => $curator->reject($item), 'Rejected.');
    }

    public function forget(string $id): void
    {
        $this->act($id, static fn (MemoryCurator $curator, MemoryItem $item) => $curator->forget($item), 'Forgotten.');
    }

    /**
     * @param callable(MemoryCurator, MemoryItem): mixed $action
     */
    private function act(string $id, callable $action, string $success): void
    {
        PandoraGate::authorize('memory.manage');

        $this->notice = null;
        $this->error = null;

        // Through the tenant-scoped query, so an id belonging to another
        // tenant resolves to nothing and gets the same answer as an id that
        // never existed.
        /** @var MemoryItem|null $item */
        $item = MemoryItem::query()->find($id);

        if ($item === null) {
            $this->error = 'That memory is no longer available.';

            return;
        }

        try {
            $action(app(MemoryCurator::class), $item);
            $this->notice = $success;
        } catch (PandoraException $e) {
            $this->error = $e->userMessage();
        }
    }

    public function render(): View
    {
        return view('pandora::livewire.memory-index', [
            'awaitingReview' => $this->awaitingReview(),
            'items' => $this->items(),
            'canManage' => PandoraGate::allows('memory.manage'),
            'scopes' => MemoryScope::cases(),
            'statuses' => MemoryStatus::cases(),
            'counts' => $this->counts(),
        ])->layout('pandora::layouts.app', ['title' => 'Memory']);
    }

    /** @return Collection<int, MemoryItem> */
    private function awaitingReview(): Collection
    {
        /** @var Collection<int, MemoryItem> $items */
        $items = MemoryItem::query()
            ->awaitingReview()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return $items;
    }

    /** @return Collection<int, MemoryItem> */
    private function items(): Collection
    {
        $query = MemoryItem::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(100);

        if ($this->statusFilter === '') {
            // The default view is what the agents will actually say. Suggested
            // items have their own section above and would otherwise appear
            // twice.
            $query->where('status', MemoryStatus::Active->value);
        } else {
            $query->where('status', $this->statusFilter);
        }

        if ($this->scopeFilter !== '') {
            $query->where('scope', $this->scopeFilter);
        }

        if ($this->search !== '') {
            $term = '%'.addcslashes(mb_strtolower($this->search), '%_\\').'%';

            // `lower(...)` for the same reason retrieval uses it: PostgreSQL's
            // LIKE is case-sensitive and the other three engines' are not.
            $query->where(function (Builder $q) use ($term): void {
                $q->whereRaw('lower(content) like ?', [$term])
                    ->orWhereRaw('lower(coalesce(title, \'\')) like ?', [$term]);
            });
        }

        /** @var Collection<int, MemoryItem> $items */
        $items = $query->get();

        return $items;
    }

    /**
     * @return array{active: int, suggested: int, expired: int, sensitive: int}
     */
    private function counts(): array
    {
        return [
            'active' => MemoryItem::query()->where('status', MemoryStatus::Active->value)->count(),
            'suggested' => MemoryItem::query()->where('status', MemoryStatus::Suggested->value)->count(),
            'expired' => MemoryItem::query()->where('status', MemoryStatus::Expired->value)->count(),
            'sensitive' => MemoryItem::query()
                ->where('sensitivity', MemorySensitivity::Sensitive->value)
                ->where('status', MemoryStatus::Active->value)
                ->count(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function typeLabels(): array
    {
        $labels = [];

        foreach (MemoryType::cases() as $type) {
            $labels[$type->value] = $type->label();
        }

        return $labels;
    }
}
