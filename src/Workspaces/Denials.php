<?php

declare(strict_types=1);

namespace Pandora\Workspaces;

use Pandora\Audit\AuditLogger;
use Pandora\Exceptions\WorkspaceDenied;

/**
 * Refusing a workspace operation, and saying so in the audit log.
 *
 * Shared by `WorkspaceFiles` and by every storage adapter, because a refusal
 * has to look identical whichever of them produced it. An adapter that threw
 * without recording would make containment violations visible on one disk and
 * silent on another -- and the silent one would be the newer, less understood
 * one, which is exactly backwards.
 */
final readonly class Denials
{
    public function __construct(
        private Workspace $workspace,
        private AuditLogger $audit,
    ) {}

    /**
     * @param array<string, mixed> $extra
     */
    public function deny(string $relative, string $reason, array $extra = []): WorkspaceDenied
    {
        // Critical, not warning. A path that resolved outside its root is
        // either a bug in this class or somebody probing, and both deserve to
        // wake somebody up.
        $severity = $reason === 'outside_root' || $reason === 'null_byte' ? 'critical' : 'info';

        $this->audit->record(
            action: $severity === 'critical' ? 'workspace.containment_violation' : 'workspace.access_denied',
            targetType: 'workspace',
            targetId: (string) $this->workspace->getKey(),
            severity: $severity,
            metadata: array_merge(['path' => $relative, 'reason' => $reason], $extra),
        );

        return WorkspaceDenied::path($relative, $reason);
    }
}
