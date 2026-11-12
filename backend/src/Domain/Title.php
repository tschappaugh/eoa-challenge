<?php

declare(strict_types=1);

namespace App\Domain;

use App\Exception\ApiException;
use Normalizer;

final class Title
{
    public const MAX_LENGTH = 100;

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
     * Key for duplicate detection: compatibility-normalized (NFKC, so
     * full-width letters and ligatures match their plain forms) and
     * case-folded (so "ß" matches "ss").
     */
    public static function normalize(string $raw): string
    {
        $nfkc = Normalizer::normalize(self::clean($raw), Normalizer::FORM_KC);

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
