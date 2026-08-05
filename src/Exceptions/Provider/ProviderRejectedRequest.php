<?php

declare(strict_types=1);

namespace Pandora\Pandora\Exceptions\Provider;

/**
 * A 4xx-class rejection: malformed request, unsupported parameter, content
 * filter. Retrying or failing over would repeat the same mistake.
 */
final class ProviderRejectedRequest extends ProviderException {}
