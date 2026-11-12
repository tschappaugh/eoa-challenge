<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;
use Throwable;

/** A failure we want to show to the user as a structured JSON error. */
final class ApiException extends RuntimeException
{
    private function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function validation(string $message): self
    {
        return new self('VALIDATION_FAILED', $message, 422);
    }

    public static function duplicateTitle(): self
    {
        return new self('DUPLICATE_TITLE', 'That game is already on the list.', 409);
    }

    public static function dailyLimit(): self
    {
        return new self(
            'DAILY_LIMIT_REACHED',
            "You've already used today's vote or game suggestion. Come back after midnight.",
            429
        );
    }

    public static function alreadyVoted(): self
    {
        return new self('ALREADY_VOTED', "You've already voted for this game.", 409);
    }

    public static function notFound(string $message = 'That game could not be found.'): self
    {
        return new self('NOT_FOUND', $message, 404);
    }

    public static function notOwner(string $message): self
    {
        return new self('NOT_OWNER', $message, 403);
    }

    public static function upstream(?Throwable $previous = null): self
    {
        return new self(
            'UPSTREAM_UNAVAILABLE',
            'The game library is unavailable right now. Please try again in a moment.',
            502,
            $previous
        );
    }
}
