<?php

declare(strict_types=1);

namespace Pandora\Pandora\Memory\Lexical;

/**
 * Splits text into the tokens lexical retrieval matches on.
 *
 * Deliberately unsophisticated. No stemming, no language detection, no
 * inflection: those need a per-language model, and a package that guesses
 * wrong at the tokeniser produces retrieval nobody can debug. What is here is
 * portable across the four engines and identical in PHP and in SQL, which
 * matters more than recall -- a vector store is the answer to recall.
 */
final class Tokeniser
{
    /**
     * Words that match nearly everything and therefore rank nothing.
     *
     * English only, and that is a stated limitation rather than an oversight:
     * a stop list for a language the installation does not use costs a few
     * false positives, whereas stemming the wrong language corrupts the index.
     *
     * @var list<string>
     */
    private const STOP_WORDS = [
        'the', 'and', 'for', 'are', 'but', 'not', 'you', 'all', 'any', 'can',
        'her', 'was', 'one', 'our', 'out', 'has', 'had', 'his', 'she', 'him',
        'they', 'them', 'this', 'that', 'with', 'have', 'from', 'were', 'what',
        'when', 'your', 'their', 'would', 'there', 'about', 'which', 'been',
        'into', 'than', 'then', 'some', 'will', 'does', 'did', 'how', 'why',
        'who', 'its', 'it', 'is', 'in', 'of', 'to', 'a', 'an', 'on', 'at',
        'as', 'by', 'or', 'be', 'do', 'if', 'so', 'we', 'me', 'my',
    ];

    private const MIN_LENGTH = 2;

    /**
     * @return list<string> distinct, lowercased, order preserved
     */
    public static function tokenise(string $text): array
    {
        $lowered = mb_strtolower($text);

        // Unicode-aware: a package that split on [a-z0-9] would tokenise
        // "café" as "caf" and drop every non-Latin script entirely.
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $lowered, -1, PREG_SPLIT_NO_EMPTY);

        if ($parts === false) {
            return [];
        }

        $tokens = [];

        foreach ($parts as $part) {
            if (mb_strlen($part) < self::MIN_LENGTH) {
                continue;
            }

            if (in_array($part, self::STOP_WORDS, true)) {
                continue;
            }

            $tokens[$part] = true;
        }

        // Back to string explicitly: PHP casts a numeric-looking array key to
        // int, so a token like "42" would come out of `array_keys()` as an
        // integer and quietly break every strict comparison downstream.
        return array_map(strval(...), array_keys($tokens));
    }
}
