<?php

declare(strict_types=1);

namespace Pandora\Pandora\Memory;

use Illuminate\Database\Eloquent\Builder;
use Pandora\Pandora\Memory\Enums\MemoryScope;

/**
 * The exact set of (tenant, scope, scope_id) triples a retrieval may see.
 *
 * This object is the answer to the only question memory asks: *whose was this,
 * and who is standing here now?* It is produced by `ScopeResolver` from the
 * run's session, and there is no other constructor a caller would reach for
 * with attacker-influenced input -- `of()` is explicit, verbose, and greppable
 * for exactly that reason.
 *
 * Nothing the model emits reaches this class. If a scope could be named in a
 * tool argument, the injection is one sentence long.
 *
 * The tenant predicate lives here rather than being left to the model's global
 * scope, because installation-wide memory is tenant-less and an AND-ed tenant
 * filter could never match it. Owning both halves of the constraint is what
 * keeps "global" from quietly meaning "nothing" in a multi-tenant install.
 */
final readonly class MemoryScopeSet
{
    /**
     * @param list<array{scope: MemoryScope, scope_id: string|null}> $pairs
     * @param bool $includeGlobal whether installation-wide, tenant-less memory is visible
     */
    private function __construct(
        public array $pairs,
        public ?string $tenantId,
        public bool $includeGlobal,
    ) {}

    /**
     * @param list<array{scope: MemoryScope, scope_id: string|null}> $pairs
     */
    public static function of(array $pairs, ?string $tenantId = null, bool $includeGlobal = true): self
    {
        return new self($pairs, $tenantId, $includeGlobal);
    }

    public static function empty(): self
    {
        return new self([], null, false);
    }

    public function isEmpty(): bool
    {
        return $this->pairs === [] && ! $this->includeGlobal;
    }

    public function includes(MemoryScope $scope, ?string $scopeId = null): bool
    {
        if ($scope === MemoryScope::Global) {
            return $this->includeGlobal;
        }

        foreach ($this->pairs as $pair) {
            if ($pair['scope'] === $scope && $pair['scope_id'] === $scopeId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Constrain a query to this set.
     *
     * Applied as a single nested `where` so that a caller who adds their own
     * conditions afterwards cannot accidentally `orWhere` their way outside
     * the constraint -- which is the classic form of this bug.
     *
     * The query MUST have been built with `MemoryItem::acrossAllTenants()`:
     * this method reapplies the tenant predicate itself, per branch.
     *
     * @param Builder<MemoryItem> $query
     */
    public function constrain(Builder $query): void
    {
        if ($this->isEmpty()) {
            // Not "no filter". An empty scope set means this runner may see
            // nothing, and the query must return nothing rather than
            // everything.
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $outer): void {
            if ($this->pairs !== []) {
                $outer->orWhere(function (Builder $tenanted): void {
                    $this->whereTenant($tenanted, $this->tenantId);

                    $tenanted->where(function (Builder $scoped): void {
                        foreach ($this->pairs as $pair) {
                            $scoped->orWhere(function (Builder $q) use ($pair): void {
                                $q->where('scope', $pair['scope']->value);

                                if ($pair['scope_id'] === null) {
                                    $q->whereNull('scope_id');
                                } else {
                                    $q->where('scope_id', $pair['scope_id']);
                                }
                            });
                        }
                    });
                });
            }

            if ($this->includeGlobal) {
                // Installation-wide memory belongs to no tenant. A "global"
                // row carrying a tenant id would be one tenant's memory
                // wearing a global label, and this predicate refuses it --
                // which is also why the write path forbids creating one.
                $outer->orWhere(function (Builder $q): void {
                    $q->where('scope', MemoryScope::Global->value)
                        ->whereNull('tenant_id');
                });
            }
        });
    }

    /**
     * @param Builder<MemoryItem> $query
     */
    private function whereTenant(Builder $query, ?string $tenantId): void
    {
        if ($tenantId === null) {
            $query->whereNull('tenant_id');

            return;
        }

        $query->where('tenant_id', $tenantId);
    }

    /**
     * @return list<array{scope: string, scope_id: string|null}>
     */
    public function toTrace(): array
    {
        $pairs = array_map(
            static fn (array $pair): array => [
                'scope' => $pair['scope']->value,
                'scope_id' => $pair['scope_id'],
            ],
            $this->pairs,
        );

        if ($this->includeGlobal) {
            $pairs[] = ['scope' => MemoryScope::Global->value, 'scope_id' => null];
        }

        return $pairs;
    }
}
