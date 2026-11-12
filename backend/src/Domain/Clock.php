<?php

declare(strict_types=1);

namespace App\Domain;

use Closure;
use DateTimeImmutable;
use DateTimeZone;

/** Source of "now" and of each user's local calendar day. */
final class Clock
{
    public function __construct(private readonly ?Closure $source = null)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->source !== null
            ? ($this->source)()
            : new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** The user's current local date as Y-m-d, in their saved time zone. */
    public function today(User|string $zone): string
    {
        return $this->local($zone)->format('Y-m-d');
    }

    public function nextMidnight(User|string $zone): DateTimeImmutable
    {
        return $this->local($zone)->setTime(0, 0)->modify('+1 day');
    }

    public function secondsUntilMidnight(User|string $zone): int
    {
        return $this->nextMidnight($zone)->getTimestamp() - $this->now()->getTimestamp();
    }

    private function local(User|string $zone): DateTimeImmutable
    {
        $name = $zone instanceof User ? $zone->timezone : $zone;

        return $this->now()->setTimezone(new DateTimeZone($name));
    }
}
