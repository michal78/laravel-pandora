<?php

declare(strict_types=1);

namespace Pandora\Pandora\Core\Actor;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;

/**
 * The entity a run acts on behalf of.
 *
 * Tool authorization is checked against the ACTOR, never the agent. That is
 * the property that makes an agent unable to do something the person it acts
 * for could not do themselves.
 */
final readonly class ActorContext implements \JsonSerializable
{
    private function __construct(
        public string $type,
        public ?string $id,
        public ?string $name,
        public ?Authorizable $authorizable,
    ) {}

    public static function forUser(Authorizable $user): self
    {
        /** @var Model&Authorizable $user */
        return new self(
            type: $user::class,
            id: (string) $user->getKey(),
            name: $user->getAttribute('name'),
            authorizable: $user,
        );
    }

    /**
     * A non-human actor: a scheduled automation, a webhook, a delegating run.
     *
     * System actors carry no Authorizable, so any tool whose authorization
     * depends on a user is denied rather than silently allowed.
     */
    public static function system(string $label): self
    {
        return new self(type: 'system', id: $label, name: $label, authorizable: null);
    }

    public function isSystem(): bool
    {
        return $this->type === 'system';
    }

    /**
     * @return array{type: string, id: string|null, name: string|null}
     */
    public function jsonSerialize(): array
    {
        return ['type' => $this->type, 'id' => $this->id, 'name' => $this->name];
    }
}
