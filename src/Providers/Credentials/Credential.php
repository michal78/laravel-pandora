<?php

declare(strict_types=1);

namespace Pandora\Pandora\Providers\Credentials;

/**
 * A resolved secret, on its way to an HTTP header and nowhere else.
 *
 * The class exists to make the wrong thing hard. Every route by which a value
 * usually escapes -- var_dump, json_encode, string interpolation, a queue
 * payload, an exception's stack trace -- is either masked or made to throw,
 * so a leak is a loud failure at development time instead of a quiet one in
 * production.
 *
 * @see docs/architecture/security-model.md
 */
final class Credential implements \JsonSerializable
{
    public function __construct(
        private readonly string $secret,
        public readonly string $providerKey,
        public readonly CredentialSource $source,
        public readonly ?string $id = null,
        public readonly ?int $version = null,
    ) {}

    /**
     * The only accessor. Deliberately a method rather than a property so it is
     * greppable: every place a secret is read is one `->secret()` call.
     */
    public function secret(): string
    {
        return $this->secret;
    }

    /**
     * What an operator may be shown: enough to identify the key, never enough
     * to use it.
     */
    public function fingerprint(): string
    {
        return self::fingerprintOf($this->secret);
    }

    public static function fingerprintOf(string $secret): string
    {
        return substr(hash('sha256', $secret), 0, 12);
    }

    /**
     * A masked hint for the UI: the last four characters, which is what every
     * provider's own dashboard shows and what an operator recognises.
     */
    public function hint(): string
    {
        return mb_strlen($this->secret) <= 4
            ? '****'
            : '****'.mb_substr($this->secret, -4);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->safeDescription();
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return $this->safeDescription();
    }

    /**
     * A credential on a queue payload would be written to the jobs table, and
     * from there to any database backup. There is no legitimate reason to
     * serialise one: resolve it again on the other side.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException(
            'A Pandora credential may not be serialised. Resolve it at the point of use instead.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function safeDescription(): array
    {
        return [
            'provider_key' => $this->providerKey,
            'source' => $this->source->value,
            'version' => $this->version,
            'fingerprint' => $this->fingerprint(),
            'secret' => '[redacted]',
        ];
    }
}
