<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\User;
use App\Services;

/**
 * The caller's anonymous identity for one request. Reading never creates a
 * user; the users row (and cookie) are created on the first write, so
 * crawlers and link previews don't fill the users table.
 */
final class Identity
{
    private bool $created = false;

    public function __construct(
        private readonly Services $services,
        private ?User $user,
        private readonly string $browserZone,
    ) {
    }

    public function user(): ?User
    {
        return $this->user;
    }

    /** The saved zone for known users, otherwise the browser-reported one. */
    public function timezone(): string
    {
        return $this->user !== null ? $this->user->timezone : $this->browserZone;
    }

    /** Returns the user, creating them (zone locked from now on) if needed. */
    public function require(): User
    {
        if ($this->user === null) {
            $this->user = $this->services->users()->create(self::uuid(), $this->browserZone);
            $this->created = true;
        }

        return $this->user;
    }

    public function wasCreated(): bool
    {
        return $this->created;
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
