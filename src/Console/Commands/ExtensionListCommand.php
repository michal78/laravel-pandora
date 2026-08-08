<?php

declare(strict_types=1);

namespace Pandora\Console\Commands;

use Illuminate\Console\Command;
use Pandora\Extensions\ExtensionInspector;
use Pandora\Extensions\InstalledExtension;

/**
 * An inventory of what Composer installed, and what it turned out to do.
 *
 * Read-only, and there is no sibling command that installs, updates or removes
 * anything. Extensions arrive through `composer require` and nothing else
 * (ADR-0016), so an `--install` flag here would be a way around the lockfile,
 * the review and the deploy that make that acceptable.
 *
 * The interesting output is the mismatch lines. "Declared, not registered" is
 * usually a typo and occasionally a package that has quietly stopped providing
 * something; "registered, not declared" is a package doing more than its
 * manifest admits. Neither is an error here, because only a person can tell
 * which of those it is looking at.
 */
final class ExtensionListCommand extends Command
{
    protected $signature = 'pandora:extension:list {--mismatched : Only extensions whose manifest disagrees with the registries}';

    protected $description = 'List the installed Pandora extensions and what they register';

    public function handle(ExtensionInspector $inspector): int
    {
        $extensions = $inspector->all();

        if ($this->option('mismatched')) {
            $extensions = array_values(array_filter(
                $extensions,
                static fn (InstalledExtension $e): bool => ! $e->matchesItsManifest(),
            ));
        }

        if ($extensions === []) {
            $this->components->warn($this->option('mismatched')
                ? 'Every installed extension registers exactly what it declares.'
                : 'No installed package declares an [extra.pandora] manifest.');

            return self::SUCCESS;
        }

        foreach ($extensions as $extension) {
            $manifest = $extension->manifest;

            $this->line('');
            $this->components->twoColumnDetail(
                '<options=bold>'.$manifest->name.'</>',
                $manifest->version,
            );
            $this->components->twoColumnDetail('  package', $manifest->package);

            if ($manifest->description !== null) {
                $this->components->twoColumnDetail('  description', $manifest->description);
            }

            foreach ($manifest->provides as $type => $items) {
                $this->components->twoColumnDetail('  declares '.$type, implode(', ', $items));
            }

            foreach ($extension->registered as $type => $items) {
                $this->components->twoColumnDetail('  registers '.$type, implode(', ', $items));
            }

            foreach ($extension->missing as $type => $items) {
                $this->components->twoColumnDetail(
                    '  <fg=yellow>declared, not registered</>',
                    $type.': '.implode(', ', $items),
                );
            }

            foreach ($extension->undeclared as $type => $items) {
                $this->components->twoColumnDetail(
                    '  <fg=yellow>registered, not declared</>',
                    $type.': '.implode(', ', $items),
                );
            }

            if ($manifest->documentation !== null) {
                $this->components->twoColumnDetail('  documentation', $manifest->documentation);
            }
        }

        $this->line('');

        return self::SUCCESS;
    }
}
