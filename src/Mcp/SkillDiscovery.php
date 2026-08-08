<?php

declare(strict_types=1);

namespace Pandora\Mcp;

use Illuminate\Support\Str;
use Pandora\Audit\AuditLogger;
use Pandora\Skills\Skill;

/**
 * Skills offered by a remote server.
 *
 * The second source Phase 5 anticipated, and exactly the case ADR-0008 exists
 * for: **a skill is instructions, never code.** There is no column here that
 * could hold something executable and no loader that would run one if there
 * were, so a skill arriving from a machine we do not own is a body of text
 * somebody has to read and enable — not a capability that installs itself.
 *
 * Everything lands `enabled = false`. A remote server that could ship an
 * enabled skill could write an agent's instructions from the far side of the
 * boundary, which is a more direct version of the description attack the whole
 * phase is about: no tool call needed, the text simply becomes part of what
 * the agent was told.
 */
final readonly class SkillDiscovery
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param list<array<string, mixed>> $descriptors
     * @return array{discovered: int, skipped: int}
     */
    public function ingest(McpServer $server, array $descriptors): array
    {
        $discovered = 0;
        $skipped = 0;

        foreach ($descriptors as $descriptor) {
            $name = is_string($descriptor['name'] ?? null) ? trim($descriptor['name']) : '';
            $instructions = is_string($descriptor['instructions'] ?? null) ? $descriptor['instructions'] : '';

            if ($name === '' || $instructions === '') {
                $skipped++;

                continue;
            }

            $slug = $server->namespace.'-'.Str::slug($name);

            if (Str::slug($name) === '') {
                $skipped++;

                continue;
            }

            Skill::query()->updateOrCreate(
                ['slug' => $slug, 'version' => '1.0.0'],
                [
                    'name' => mb_substr($name, 0, 191),
                    'description' => mb_substr(
                        is_string($descriptor['description'] ?? null) ? $descriptor['description'] : '',
                        0,
                        2000,
                    ),
                    // The whole payload, as text. Nothing parses this looking
                    // for something to execute, and nothing ever will.
                    'instructions' => mb_substr($instructions, 0, 100000),
                    'source' => 'mcp',
                    // Unapproved. A remote server that could ship an enabled
                    // skill could write an agent's instructions directly.
                    'enabled' => false,
                    'metadata' => ['mcp_server' => $server->slug],
                ],
            );

            $discovered++;
        }

        $this->audit->record(
            action: 'mcp.discovery_completed',
            targetType: 'mcp_server',
            targetId: (string) $server->getKey(),
            metadata: [
                'server' => $server->slug,
                'skills_discovered' => $discovered,
                'skills_skipped' => $skipped,
                'skills_enabled' => 0,
            ],
        );

        return ['discovered' => $discovered, 'skipped' => $skipped];
    }
}
