<?php

declare(strict_types=1);

namespace Pandora\Pandora\Context;

use Illuminate\Database\Eloquent\Model;

/**
 * The only supported way to put a model's data into a prompt.
 *
 * `ContextProvider` has always instructed implementers to allowlist the
 * attributes they expose. An instruction in a docblock is a suggestion; this
 * class is the version that holds. There is no `all()`, no `except()` and no
 * way to pass a model straight through -- the caller names every field, and
 * a field nobody named does not appear.
 *
 * The failure this prevents is dull and total: `$user->toArray()` in a context
 * provider puts the password hash, the remember token, the API key column and
 * whatever the host added last week into a prompt, which is then sent to a
 * third party and stored in their logs. No exception is thrown and nothing
 * looks wrong.
 *
 * Attributes are read through `getAttribute()`, so an accessor the host
 * defined is honoured and a column that does not exist reads as null rather
 * than fataling mid-run.
 */
final readonly class AttributeAllowlist
{
    /**
     * @param list<string> $attributes
     */
    private function __construct(
        public array $attributes,
    ) {}

    /**
     * @param list<string> $attributes
     */
    public static function of(array $attributes): self
    {
        return new self(array_values(array_unique($attributes)));
    }

    /**
     * Project a model down to the allowlisted attributes.
     *
     * Values that are not scalar are stringified defensively rather than
     * nested: a relation or a cast object reached through an allowlisted name
     * would otherwise smuggle a whole second model into the prompt, which is
     * the exact hole this class exists to close.
     *
     * @return array<string, string>
     */
    public function project(Model $model): array
    {
        $projected = [];

        foreach ($this->attributes as $attribute) {
            $value = $model->getAttribute($attribute);

            if ($value === null) {
                continue;
            }

            $projected[$attribute] = $this->stringify($value);
        }

        return $projected;
    }

    /**
     * @param list<Model> $models
     * @return list<array<string, string>>
     */
    public function projectAll(array $models): array
    {
        return array_map($this->project(...), $models);
    }

    private function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof \Stringable || (is_object($value) && method_exists($value, '__toString'))) {
            return (string) $value;
        }

        // An array, a relation, a cast object. Refused rather than serialised:
        // whatever it is, nobody allowlisted its contents.
        return '[not exposed]';
    }
}
