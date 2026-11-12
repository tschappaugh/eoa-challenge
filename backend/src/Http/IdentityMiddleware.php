<?php

declare(strict_types=1);

namespace App\Http;

use App\Services;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * Resolves the anonymous UUID cookie into an Identity. The user's time zone
 * (X-Timezone header) is saved when they are created; after that the saved
 * zone is always used, so changing zones can't earn extra daily actions.
 */
final class IdentityMiddleware implements MiddlewareInterface
{
    public const COOKIE = 'uid';
    private const ONE_YEAR = 31536000;
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function __construct(private readonly Services $services)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $cookie = $request->getCookieParams()[self::COOKIE] ?? null;

        $user = is_string($cookie) && preg_match(self::UUID_PATTERN, $cookie) === 1
            ? $this->services->users()->find($cookie)
            : null;

        $identity = new Identity($this->services, $user, $this->validZone($request->getHeaderLine('X-Timezone')));
        $response = $handler->handle($request->withAttribute(Identity::class, $identity));

        if (!$identity->wasCreated()) {
            return $response;
        }

        $cookie = $this->cookie($identity->require()->id, $this->isHttps($request));

        return $response->withAddedHeader('Set-Cookie', $cookie);
    }

    private function cookie(string $id, bool $secure): string
    {
        return sprintf(
            '%s=%s; Max-Age=%d; Path=/; HttpOnly; SameSite=Lax%s',
            self::COOKIE,
            $id,
            self::ONE_YEAR,
            $secure ? '; Secure' : ''
        );
    }

    private function isHttps(Request $request): bool
    {
        return $request->getUri()->getScheme() === 'https'
            || strtolower($request->getHeaderLine('X-Forwarded-Proto')) === 'https';
    }

    private function validZone(string $zone): string
    {
        return in_array($zone, DateTimeZone::listIdentifiers(), true) ? $zone : 'UTC';
    }
}
