<?php

declare(strict_types=1);

namespace Pandora\Providers\Credentials;

/**
 * Where a resolved credential came from. Recorded so an operator debugging
 * "which key did that run actually use?" gets an answer instead of a guess.
 *
 * The order of the cases IS the resolution order.
 */
enum CredentialSource: string
{
    case Agent = 'agent';
    case Tenant = 'tenant';
    case Deployment = 'deployment';

    /**
     * The connection's configured `api_key`, which is where `pandora.php`
     * reads the environment. There is no separate environment case: reading
     * env() outside the config directory returns null the moment a deployment
     * caches its config, which is to say in production.
     */
    case Config = 'config';

    public function label(): string
    {
        return match ($this) {
            self::Agent => 'Agent credential',
            self::Tenant => 'Tenant credential',
            self::Deployment => 'Deployment credential',
            self::Config => 'Configuration file',
        };
    }

    /**
     * Stored credentials are rotatable and revocable; configured ones are not.
     */
    public function isStored(): bool
    {
        return match ($this) {
            self::Agent, self::Tenant, self::Deployment => true,
            self::Config => false,
        };
    }
}
