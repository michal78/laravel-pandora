<?php

declare(strict_types=1);

namespace Pandora\UI\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use Pandora\Extensions\ExtensionInspector;
use Pandora\UI\Feature;
use Pandora\UI\PandoraGate;

/**
 * What Composer installed, and what it turned out to register.
 *
 * An inventory, and only an inventory. There is no install button, no update
 * badge, no "browse available extensions" and no version check — those are
 * excluded rather than deferred, because a UI that can install code is a UI
 * whose authorization bug is arbitrary execution (ADR-0016). Extensions arrive
 * through `composer require`, which means they arrive through a lockfile, a
 * review and a deploy, and nothing here should route around those.
 *
 * Nothing is booted to render this. The list comes from Composer's own
 * `installed.json`, so an extension that would fatal on load still appears with
 * its name and its declared capabilities — which is the case an operator most
 * needs the page for.
 */
final class ExtensionsIndex extends Component
{
    public function mount(): void
    {
        $this->guard();
    }

    public function render(): View
    {
        return view('pandora::livewire.extensions-index', [
            'extensions' => app(ExtensionInspector::class)->all(),
        ])->layout('pandora::layouts.app', ['title' => 'Extensions']);
    }

    private function guard(): void
    {
        if (Feature::disabled('extensions')) {
            abort(404);
        }

        PandoraGate::authorize('extensions.view');
    }
}
