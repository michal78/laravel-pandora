<?php

declare(strict_types=1);

namespace Pandora\Pandora\Core\Tenancy;

/**
 * The tenant that owns a piece of work.
 *
 * Deliberately a value object rather than a model reference: Pandora never
 * assumes your tenant table exists, what its key type is, or that it is in the
 * same database.
 */
final readonly class TenantContext implements \JsonSerializable
{
    public function __construct(
        public string $id,
        public ?string $name = null,
    ) {}

    /**
     * @param array{id: string, name?: string|null} $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['id'], $data['name'] ?? null);
    }

    /**
     * @return array{id: string, name: string|null}
     */
    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'name' => $this->name];
    }
}
