<?php

declare(strict_types=1);

namespace Pandora\UI\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Pandora\Tools\Tool;
use Pandora\Tools\ToolRegistry;
use Pandora\UI\PandoraGate;

/**
 * What this application has installed, and what an agent could ask for.
 *
 * Answers the question an operator actually has: what can these agents reach?
 * The schema is behind `pandora.tools.io.view` because it describes the shape
 * of the application's own data.
 */
final class ToolsIndex extends Component
{
    #[Url(as: 'group', except: '')]
    public string $groupFilter = '';

    public ?string $expanded = null;

    public function mount(): void
    {
        PandoraGate::authorize('access');
    }

    public function toggle(string $reference): void
    {
        $this->expanded = $this->expanded === $reference ? null : $reference;
    }

    public function render(ToolRegistry $registry): View
    {
        $tools = $this->groupFilter === ''
            ? $registry->allVersions()
            : $registry->group($this->groupFilter);

        $schemas = [];

        if (PandoraGate::allows('tools.io.view')) {
            foreach ($tools as $tool) {
                $schemas[$this->reference($tool)] = (string) json_encode(
                    $registry->schema($tool),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                );
            }
        }

        return view('pandora::livewire.tools-index', [
            'tools' => $tools,
            'groups' => $registry->groups(),
            'schemas' => $schemas,
            'canViewSchemas' => PandoraGate::allows('tools.io.view'),
        ])->layout('pandora::layouts.app', ['title' => 'Tools']);
    }

    public function reference(Tool $tool): string
    {
        return $tool->name().'@'.$tool->version();
    }
}
