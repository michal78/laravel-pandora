<?php

declare(strict_types=1);

namespace Pandora\Audit;

use Illuminate\Http\Request;
use Pandora\Core\Actor\ActorManager;
use Pandora\Core\Tenancy\TenantManager;
use Pandora\Support\CorrelationId;
use Pandora\Support\Redactor;

/**
 * Writes audit records.
 *
 * Request metadata (IP, user agent) is captured only when a request actually
 * exists -- in a queue worker it does not, and inventing values would make the
 * log lie about where an action came from.
 */
final class AuditLogger
{
    public function __construct(
        private readonly TenantManager $tenants,
        private readonly ActorManager $actors,
        private readonly CorrelationId $correlation,
        private readonly Redactor $redactor,
        private readonly ?Request $request = null,
    ) {}

    /**
     * @param array<string, mixed> $metadata
     */
    public function record(
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        ?string $runId = null,
        string $severity = 'info',
        array $metadata = [],
    ): AuditLog {
        $actor = $this->actors->current();

        /** @var AuditLog $log */
        $log = AuditLog::query()->create([
            'tenant_id' => $this->tenants->currentId(),
            'correlation_id' => $this->correlation->current(),
            'actor_type' => $actor?->type,
            'actor_id' => $actor?->id,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'run_id' => $runId,
            'severity' => $severity,
            'ip' => $this->request?->ip(),
            'user_agent' => $this->truncate($this->request?->userAgent()),
            'metadata' => $metadata === [] ? null : $this->redactor->redact($metadata),
        ]);

        return $log;
    }

    private function truncate(?string $value, int $length = 500): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }
}
