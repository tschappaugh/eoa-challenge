<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\User;
use App\Services;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * Identifies the caller by an anonymous UUID in an httpOnly cookie. A new
 * visitor gets a users row and their time zone (from the X-Timezone header)
 * is saved once; after that the saved zone is always used, so changing zones
 * can't earn extra daily actions.
 */
final class UserMiddleware implements MiddlewareInterface
{
    public const COOKIE = 'uid';
    private const ONE_YEAR = 31536000;
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function __construct(private readonly Services $services)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $users = $this->services->users();
        $cookie = $request->getCookieParams()[self::COOKIE] ?? null;

        $user = is_string($cookie) && preg_match(self::UUID_PATTERN, $cookie) === 1
            ? $users->find($cookie)
            : null;

        $isNew = $user === null;
        if ($user === null) {
            $user = $users->create(self::uuid(), $this->validZone($request->getHeaderLine('X-Timezone')));
        }

        $response = $handler->handle($request->withAttribute('user', $user));

        return $isNew ? $response->withAddedHeader('Set-Cookie', $this->cookie($user)) : $response;
    }

    private function cookie(User $user): string
    {
        return sprintf(
            '%s=%s; Max-Age=%d; Path=/; HttpOnly; SameSite=Lax',
            self::COOKIE,
            $user->id,
            self::ONE_YEAR
        );
    }

    private function validZone(string $zone): string
    {
        return in_array($zone, DateTimeZone::listIdentifiers(), true) ? $zone : 'UTC';
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
