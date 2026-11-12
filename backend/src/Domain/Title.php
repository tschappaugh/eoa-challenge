<?php

declare(strict_types=1);

namespace App\Domain;

use App\Exception\ApiException;
use Normalizer;

final class Title
{
    public const MAX_LENGTH = 100;

    /**
     * Punctuation that looks alike in titles ("Baldur’s Gate" vs "Baldur's
     * Gate"), folded to one ASCII form for duplicate detection only. Kept in
     * sync with LOOKALIKES in frontend/src/lib/title.ts.
     */
    private const LOOKALIKES = [
        "\u{2018}" => "'", // left single quote
        "\u{2019}" => "'", // right single quote / curly apostrophe
        "\u{201B}" => "'", // single high-reversed-9 quote
        "\u{02BC}" => "'", // modifier letter apostrophe
        "\u{2032}" => "'", // prime
        '`' => "'",
        "\u{00B4}" => "'", // acute accent
        "\u{201C}" => '"', // left double quote
        "\u{201D}" => '"', // right double quote
        "\u{201E}" => '"', // double low-9 quote
        "\u{201F}" => '"', // double high-reversed-9 quote
        "\u{2033}" => '"', // double prime
        "\u{2010}" => '-', // hyphen
        "\u{2011}" => '-', // non-breaking hyphen
        "\u{2012}" => '-', // figure dash
        "\u{2013}" => '-', // en dash
        "\u{2014}" => '-', // em dash
        "\u{2015}" => '-', // horizontal bar
        "\u{2212}" => '-', // minus sign
    ];

    /**
     * Canonical (NFC) form with control/format characters removed and
     * whitespace trimmed and collapsed. This is what we store and display.
     */
    public static function clean(string $raw): string
    {
        $nfc = Normalizer::normalize($raw, Normalizer::FORM_C);
        if ($nfc === false) {
            return ''; // invalid UTF-8
        }

        // Invisible format characters (zero-width space, joiners, bidi marks)
        // are removed outright; control characters (tabs, newlines) become
        // spaces and are then collapsed.
        $noFormat = preg_replace('/\p{Cf}/u', '', $nfc);
        $noControl = $noFormat === null ? null : preg_replace('/\p{Cc}/u', ' ', $noFormat);
        $collapsed = $noControl === null ? null : preg_replace('/\s+/u', ' ', $noControl);

        return trim($collapsed ?? '');
    }

    /**
     * Key for duplicate detection: look-alike quotes and dashes folded to
     * ASCII, compatibility-normalized (NFKC, so full-width letters and
     * ligatures match their plain forms) and case-folded (so "ß" matches
     * "ss"). Folding runs first because NFKC would otherwise rewrite some of
     * these (a double prime becomes two primes).
     */
    public static function normalize(string $raw): string
    {
        $folded = strtr(self::clean($raw), self::LOOKALIKES);
        $nfkc = Normalizer::normalize($folded, Normalizer::FORM_KC);

        return mb_substr(mb_convert_case($nfkc === false ? '' : $nfkc, MB_CASE_FOLD, 'UTF-8'), 0, self::MAX_LENGTH);
    }

    /** @throws ApiException */
    public static function validate(mixed $raw): string
    {
        if (!is_string($raw)) {
            throw ApiException::validation('Enter a game title.');
        }

        $title = self::clean($raw);

        if ($title === '') {
            throw ApiException::validation('Enter a game title.');
        }

        if (mb_strlen($title) > self::MAX_LENGTH) {
            throw ApiException::validation('Titles can be at most ' . self::MAX_LENGTH . ' characters.');
        }

        return $title;
    }
}
