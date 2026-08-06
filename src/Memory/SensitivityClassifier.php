<?php

declare(strict_types=1);

namespace Pandora\Pandora\Memory;

use Pandora\Pandora\Memory\Enums\MemorySensitivity;
use Pandora\Pandora\Memory\Enums\MemoryType;

/**
 * Decides whether a memory is ordinary, needs a human, or must not be kept.
 *
 * Keyword matching, and openly so. A model-based classifier would be more
 * accurate and would also mean a paid API call on every write, an unavailable
 * dependency on the write path, and a decision nobody can reproduce from the
 * row afterwards. This is a filter that errs toward asking a human, which is
 * the direction to err in.
 *
 * It is replaceable: bind your own implementation of this class in a service
 * provider. What must not change is that the ANSWER gates the write --
 * `Restricted` content is never stored anywhere, and `Sensitive` content is
 * never active until somebody says so.
 *
 * The word lists are English. That is a real limitation for an installation
 * operating in another language, and the honest mitigation is that the default
 * for a `UserFact` is already "ask a human" regardless of what words it
 * contains.
 */
class SensitivityClassifier
{
    /**
     * Content that must never be kept, in any status.
     *
     * `Redactor` has already removed credential-shaped strings from the text
     * by the time this runs. This catches the case where the whole memory was
     * about a secret -- "the admin password is on the whiteboard" survives
     * redaction intact and should still not be filed.
     *
     * @var list<string>
     */
    private const RESTRICTED = [
        'password', 'passphrase', 'api key', 'api_key', 'secret key',
        'private key', 'access token', 'refresh token', 'credit card',
        'card number', 'cvv', 'sort code', 'account number', 'seed phrase',
        'recovery phrase', 'social security', 'national insurance',
    ];

    /**
     * Content a human should see before an agent repeats it.
     *
     * @var list<string>
     */
    private const SENSITIVE = [
        'diagnosis', 'diagnosed', 'medication', 'prescription', 'therapy',
        'mental health', 'depression', 'anxiety', 'disability', 'pregnan',
        'salary', 'compensation', 'debt', 'bankrupt', 'divorce', 'custody',
        'religion', 'religious', 'immigration', 'visa status', 'criminal',
        'arrest', 'lawsuit', 'sexual', 'orientation', 'ethnicity',
        'home address', 'date of birth', 'passport',
    ];

    public function classify(string $content, MemoryType $type): MemorySensitivity
    {
        $haystack = mb_strtolower($content);

        foreach (self::RESTRICTED as $needle) {
            if (str_contains($haystack, $needle)) {
                return MemorySensitivity::Restricted;
            }
        }

        foreach (self::SENSITIVE as $needle) {
            if (str_contains($haystack, $needle)) {
                return MemorySensitivity::Sensitive;
            }
        }

        // A claim about a person defaults to needing a human, whatever words
        // it happens to contain. This is the case the keyword lists cannot
        // cover and the one that matters most: an agent asserting something
        // about someone, which will then be repeated back to them as fact.
        if ($type === MemoryType::UserFact) {
            return MemorySensitivity::Sensitive;
        }

        return MemorySensitivity::Normal;
    }
}
