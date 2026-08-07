<?php

declare(strict_types=1);

namespace Pandora\Console\Commands;

use Illuminate\Console\Command;
use Pandora\Memory\Enums\MemoryScope;
use Pandora\Memory\MemoryExporter;

/**
 * Export one scope's memory as versioned JSON.
 *
 * One scope per invocation, with no "all" switch. Every legitimate use of this
 * command is one subject at a time; the use that is not legitimate is the one
 * that would want a flag to dump everything.
 */
final class MemoryExportCommand extends Command
{
    protected $signature = 'pandora:memory:export
        {scope : global|tenant|user|agent|conversation|workspace}
        {--id= : The scope id, required for every scope except global and tenant}
        {--include-inactive : Include suggested, rejected and expired items}
        {--path= : Write to a file instead of standard output}';

    protected $description = 'Export the memory held in one scope, as JSON.';

    public function handle(MemoryExporter $exporter): int
    {
        /** @var string $scopeName */
        $scopeName = $this->argument('scope');

        $scope = MemoryScope::tryFrom($scopeName);

        if ($scope === null) {
            $this->components->error("Unknown scope [{$scopeName}].");

            return self::FAILURE;
        }

        /** @var string|null $id */
        $id = $this->option('id');

        if ($scope->requiresScopeId() && ($id === null || $id === '')) {
            $this->components->error("Scope [{$scope->value}] needs --id.");

            return self::FAILURE;
        }

        $export = $exporter->export($scope, $scope->requiresScopeId() ? $id : null, (bool) $this->option('include-inactive'));

        $json = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        /** @var string|null $path */
        $path = $this->option('path');

        if ($path === null) {
            $this->line($json);

            return self::SUCCESS;
        }

        file_put_contents($path, $json);

        $this->components->info(sprintf('Exported %d memory item(s) to %s.', $export['count'], $path));

        return self::SUCCESS;
    }
}
