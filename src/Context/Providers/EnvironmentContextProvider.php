<?php

declare(strict_types=1);

namespace Pandora\Pandora\Context\Providers;

use Pandora\Pandora\Context\ContextRequest;
use Pandora\Pandora\Context\ContextSection;
use Pandora\Pandora\Contracts\ContextProvider;
use Pandora\Pandora\Providers\Data\ChatMessage;

/**
 * Time, locale and application name.
 *
 * Deliberately minimal. Anything identifying a user or tenant belongs to a
 * provider that is scoped and redacted, not to a general environment block --
 * and nothing here is serialised from a model.
 */
final class EnvironmentContextProvider implements ContextProvider
{
    public function key(): string
    {
        return 'environment';
    }

    public function provide(ContextRequest $request): ContextSection
    {
        /** @var string $appName */
        $appName = config('app.name', 'Application');

        $lines = [
            'Application: '.$appName,
            'Current time: '.now()->toIso8601String(),
            'Timezone: '.config('app.timezone'),
            'Locale: '.config('app.locale'),
            'Agent: '.$request->agent->name,
        ];

        return ContextSection::of(
            $this->key(),
            [ChatMessage::system("<environment>\n".implode("\n", $lines)."\n</environment>")],
        );
    }
}
