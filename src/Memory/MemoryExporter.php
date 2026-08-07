<?php

declare(strict_types=1);

namespace Pandora\Memory;

use Illuminate\Support\Facades\Date;
use Pandora\Audit\AuditLogger;
use Pandora\Exceptions\AuthorizationDenied;
use Pandora\Memory\Enums\MemoryScope;
use Pandora\UI\PandoraGate;

/**
 * Exports what an agent knows about somebody, as versioned JSON.
 *
 * This is a subject access request in one method, and it is also the single
 * most dangerous read in the system: one call returns everything remembered
 * about a person, in plain text, in a file that leaves the application. So it
 * is gated, it is audited at `warning`, and it exports exactly one scope --
 * there is no "export everything", because the legitimate uses are all
 * one-subject-at-a-time and the illegitimate one is not.
 *
 * Vectors are not exported. They are derived, they are enormous, and they are
 * meaningless outside the model that produced them.
 */
final class MemoryExporter
{
    public const VERSION = 1;

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{version: int, exported_at: string, scope: string, scope_id: string|null, count: int, items: list<array<string, mixed>>}
     *
     * @throws AuthorizationDenied
     */
    public function export(MemoryScope $scope, ?string $scopeId = null, bool $includeInactive = false): array
    {
        PandoraGate::authorize('memory.manage');

        $query = MemoryItem::query()
            ->where('scope', $scope->value)
            ->orderBy('created_at')
            ->orderBy('id');

        if ($scopeId === null) {
            $query->whereNull('scope_id');
        } else {
            $query->where('scope_id', $scopeId);
        }

        if (! $includeInactive) {
            $query->retrievable();
        }

        $items = $query->get();

        /** @var list<array<string, mixed>> $exported */
        $exported = array_values($items->map(static fn (MemoryItem $item): array => [
            'id' => $item->getKey(),
            'type' => $item->type->value,
            'title' => $item->title,
            'content' => $item->content,
            'structured' => $item->structured,
            'source' => $item->source->value,
            'provenance' => $item->provenance,
            'confidence' => $item->confidence,
            'sensitivity' => $item->sensitivity->value,
            'status' => $item->status->value,
            'expires_at' => $item->expires_at?->toIso8601String(),
            'created_at' => $item->created_at?->toIso8601String(),
            'updated_at' => $item->updated_at?->toIso8601String(),
        ])->all());

        // Warning severity, deliberately. An export is not a page view: it is
        // a bulk read of everything an agent believes about a person, and it
        // should stand out in the audit log at a glance.
        $this->audit->record(
            action: 'memory.exported',
            severity: 'warning',
            metadata: [
                'scope' => $scope->value,
                'scope_id' => $scopeId,
                'count' => $items->count(),
                'included_inactive' => $includeInactive,
            ],
        );

        return [
            'version' => self::VERSION,
            'exported_at' => Date::now()->toIso8601String(),
            'scope' => $scope->value,
            'scope_id' => $scopeId,
            'count' => $items->count(),
            'items' => $exported,
        ];
    }
}
