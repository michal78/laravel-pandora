<?php

declare(strict_types=1);

namespace Pandora\Pandora\Support\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * ULID primary keys stored as char(26).
 *
 * Time-sortable (good index locality on write-heavy tables like run_steps),
 * URL-safe, and portable across all four supported database engines without a
 * vendor-specific column type. See docs/adr/0004-ulid-identifiers.md.
 */
trait HasPandoraUlids
{
    use HasUlids;

    public function getIncrementing(): bool
    {
        return false;
    }

    public function getKeyType(): string
    {
        return 'string';
    }
}
