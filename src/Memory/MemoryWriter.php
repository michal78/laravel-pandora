<?php

declare(strict_types=1);

namespace Pandora\Memory;

use Pandora\Audit\AuditLogger;
use Pandora\Conversations\Session;
use Pandora\Exceptions\InvalidMemoryScope;
use Pandora\Memory\Enums\MemoryScope;
use Pandora\Memory\Enums\MemorySource;
use Pandora\Memory\Enums\MemoryStatus;
use Pandora\Memory\Enums\MemoryType;
use Pandora\Support\Redactor;

/**
 * The only way memory is written.
 *
 * Symmetrical with `MemoryRetriever`, and for the same reason: one place to
 * review. Three things happen here in a fixed order, and the order is the
 * design.
 *
 * **Redact first.** Before the row, before the vector, before the export. A
 * secret redacted at render is still in the database, still in the embedding,
 * still in the backup, and still one bug away from being said out loud. By the
 * time anything else looks at this content, the credential-shaped strings are
 * already gone.
 *
 * **Then classify.** Sensitivity decides whether this becomes an active memory
 * or a suggestion a human must approve. An agent that can write a claim about
 * a person and have it believed on the next turn has no supervision at all.
 *
 * **Then scope, from the session.** Never from an argument. The scope of a
 * write is derived exactly the way the scope of a read is, so a memory cannot
 * be filed somewhere its author could not have read from.
 */
final class MemoryWriter
{
    public function __construct(
        private readonly Redactor $redactor,
        private readonly AuditLogger $audit,
        private readonly SensitivityClassifier $classifier,
    ) {}

    /**
     * Write a memory on behalf of a running agent.
     *
     * @param array<string, mixed> $provenance
     *
     * @throws InvalidMemoryScope
     */
    public function remember(
        Session $session,
        string $content,
        MemoryScope $scope,
        ?string $title = null,
        MemoryType $type = MemoryType::AgentCurated,
        MemorySource $source = MemorySource::Agent,
        ?string $runId = null,
        array $provenance = [],
        int $confidence = 100,
    ): ?MemoryItem {
        if (! $scope->writableByAgent()) {
            // Installation-wide memory would let one agent teach every other
            // agent something false, once.
            throw InvalidMemoryScope::notWritableByAgent($scope);
        }

        $content = $this->redactor->redactText($content);
        $title = $title === null ? null : $this->redactor->redactText($title);

        $sensitivity = $this->classifier->classify($content, $type);

        if (! $sensitivity->storable()) {
            // Credentials, tokens, keys. Not stored, not suggested, not
            // queued for anyone to approve -- there is no version of keeping
            // this that is correct.
            $this->audit->record(
                action: 'memory.refused',
                severity: 'warning',
                runId: $runId,
                metadata: ['reason' => 'restricted_content', 'scope' => $scope->value],
            );

            return null;
        }

        $scopeId = $this->scopeIdFor($session, $scope);

        $status = $sensitivity->requiresApproval()
            ? MemoryStatus::Suggested
            : MemoryStatus::Active;

        /** @var MemoryItem $item */
        $item = MemoryItem::query()->create([
            'scope' => $scope->value,
            'scope_id' => $scopeId,
            'agent_id' => $session->agent_id,
            'type' => $type->value,
            'title' => $title,
            'content' => $content,
            'source' => $source->value,
            'source_run_id' => $runId,
            'provenance' => $provenance === [] ? null : $provenance,
            'confidence' => max(0, min(100, $confidence)),
            'sensitivity' => $sensitivity->value,
            'status' => $status->value,
            'expires_at' => $this->expiryFor($type),
        ]);

        $this->audit->record(
            action: $status === MemoryStatus::Suggested ? 'memory.suggested' : 'memory.stored',
            targetType: 'memory_item',
            targetId: (string) $item->getKey(),
            runId: $runId,
            severity: 'info',
            metadata: [
                'scope' => $scope->value,
                'type' => $type->value,
                'sensitivity' => $sensitivity->value,
            ],
        );

        return $item;
    }

    /**
     * The scope id a write must use, derived from the session.
     *
     * @throws InvalidMemoryScope
     */
    private function scopeIdFor(Session $session, MemoryScope $scope): ?string
    {
        $id = match ($scope) {
            MemoryScope::Global, MemoryScope::Tenant => null,
            MemoryScope::User => ScopeResolver::userScopeId($session->actor_type, $session->actor_id),
            MemoryScope::Agent => $session->agent_id,
            MemoryScope::Conversation => $session->conversation_id,
            MemoryScope::Workspace => null,
        };

        if ($scope->requiresScopeId() && ($id === null || $id === '')) {
            // A system actor writing user-scoped memory, or a session with no
            // conversation writing conversation-scoped memory. Refused rather
            // than silently downgraded to a wider scope, which would be the
            // leak wearing a helpful face.
            throw InvalidMemoryScope::missingScopeId($scope);
        }

        return $id;
    }

    private function expiryFor(MemoryType $type): ?\DateTimeInterface
    {
        $ttl = $type->defaultTtlSeconds();

        return $ttl === null ? null : now()->addSeconds($ttl);
    }
}
