<?php

declare(strict_types=1);

namespace App\Domain;

use App\Exception\ApiException;

final class Title
{
    public const MAX_LENGTH = 100;

    /** Trim, drop control characters, and collapse inner whitespace. */
    public static function clean(string $raw): string
    {
        $noControl = preg_replace('/[\p{Cc}\p{Cf}]/u', ' ', $raw);
        $collapsed = $noControl === null ? null : preg_replace('/\s+/u', ' ', $noControl);

        return trim($collapsed ?? '');
    }

    /** The value used for case-insensitive duplicate detection. */
    public static function normalize(string $raw): string
    {
        return mb_strtolower(self::clean($raw));
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
