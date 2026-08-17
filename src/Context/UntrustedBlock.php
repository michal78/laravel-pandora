<?php

declare(strict_types=1);

namespace Pandora\Context;

/**
 * A labelled region of the prompt that untrusted text cannot end early.
 *
 * Three providers wrapped their content in a tag -- `<file>`, `<memory>`,
 * `<environment>` -- and interpolated the content straight between the
 * markers. A delimiter the content can write is not a delimiter. A document
 * containing `</file></context_files>`, or a remembered note containing
 * `</memory>`, closed the region and continued *outside* it, in a message the
 * model has every reason to read as ours.
 *
 * The security model's T1 says untrusted content is "delimited and labelled".
 * This is the part of that sentence which can be made structural: after this
 * class has wrapped a string, no input can produce the closing marker, so the
 * region ends where we say it ends. Whether the model then *respects* the
 * region is a matter of instruction and cannot be guaranteed here -- that is
 * the framework preamble's job, and it is a mitigation rather than a fix.
 *
 * Neutralising rather than stripping. A style guide that legitimately contains
 * `</file>` in a code sample should still be readable; it arrives as
 * `<\/file>`, which is visibly the same text and is not a tag. Deleting it
 * would silently change a document, which is the failure mode this repository
 * has already rejected for truncation.
 */
final readonly class UntrustedBlock
{
    /**
     * Wrap untrusted content in a tag it cannot close.
     *
     * @param array<string, string> $attributes label attributes -- values are
     *                                          neutralised the same way
     */
    public static function wrap(string $tag, string $content, array $attributes = []): string
    {
        $open = '<'.$tag;

        foreach ($attributes as $name => $value) {
            $open .= ' '.$name.'="'.self::attribute($value).'"';
        }

        return $open.'>'.PHP_EOL.self::contain($tag, $content).PHP_EOL.'</'.$tag.'>';
    }

    /**
     * Neutralise every closing marker for `$tag` inside `$content`.
     *
     * Matches `</tag` with any whitespace and any casing, because a tag is not
     * case-sensitive to a model reading it and `</FILE >` closes the region as
     * convincingly as `</file>` does.
     */
    public static function contain(string $tag, string $content): string
    {
        return (string) preg_replace(
            '#</\s*'.preg_quote($tag, '#').'#i',
            '<\\/'.$tag,
            $content,
        );
    }

    /**
     * Neutralise a value going into a label attribute.
     *
     * A filename is chosen by whoever edits the agent, which is a browser
     * form. One containing a quote would end the attribute and start writing
     * markup of its own.
     */
    private static function attribute(string $value): string
    {
        return str_replace(['"', '<', '>', PHP_EOL], ['\'', '', '', ' '], $value);
    }
}
