<?php

declare(strict_types=1);

namespace Pandora\Pandora\Console\Commands;

use Illuminate\Console\Command;
use Pandora\Pandora\Tools\Tool;
use Pandora\Pandora\Tools\ToolRegistry;

/**
 * What this application has installed, and what it will let an agent ask for.
 *
 * Answers the question an operator actually has during an incident: what can
 * these agents reach?
 */
final class ToolListCommand extends Command
{
    protected $signature = 'pandora:tool:list
                            {--group= : Only tools in this group}
                            {--schema : Show the JSON schema advertised to the model}';

    protected $description = 'List the tools registered with Pandora';

    public function handle(ToolRegistry $registry): int
    {
        /** @var string|null $group */
        $group = $this->option('group');

        $tools = $group === null ? $registry->allVersions() : $registry->group($group);

        if ($tools === []) {
            $this->components->warn($group === null
                ? 'No tools are registered. Add them under `tools.registered` in config/pandora.php.'
                : "No tools are registered in group [{$group}].");

            return self::SUCCESS;
        }

        $this->table(
            ['Name', 'Version', 'Group', 'Risk', 'Approval', 'Description'],
            array_map(static fn (Tool $tool): array => [
                $tool->name().($tool->deprecated() === null ? '' : ' (deprecated)'),
                $tool->version(),
                $tool->group(),
                $tool->risk()->label(),
                $tool->risk()->requiresApprovalByDefault() ? 'required' : '-',
                str($tool->description())->limit(48)->toString(),
            ], $tools),
        );

        if ($this->option('schema') === true) {
            foreach ($tools as $tool) {
                $this->newLine();
                $this->components->twoColumnDetail('<fg=cyan>'.$tool->name().'</>', $tool->version());
                $this->line((string) json_encode(
                    $registry->schema($tool),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                ));
            }
        }

        return self::SUCCESS;
    }
}
