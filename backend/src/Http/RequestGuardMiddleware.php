<?php

declare(strict_types=1);

namespace App\Http;

use App\Exception\ApiException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * CSRF defence in depth on top of the SameSite=Lax cookie. Every write must
 * carry X-Requested-With (a custom header forces a CORS preflight, which we
 * never approve), and if the browser sent an Origin it must be our own.
 */
final class RequestGuardMiddleware implements MiddlewareInterface
{
    public const HEADER = 'X-Requested-With';
    public const HEADER_VALUE = 'XMLHttpRequest';
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function process(Request $request, Handler $handler): Response
    {
        if (!in_array($request->getMethod(), self::SAFE_METHODS, true)) {
            if ($request->getHeaderLine(self::HEADER) !== self::HEADER_VALUE) {
                throw ApiException::requestRejected();
            }

            $origin = $request->getHeaderLine('Origin');
            if ($origin !== '' && !$this->isSameOrigin($origin, $this->rawHost($request))) {
                throw ApiException::requestRejected();
            }
        }

        return $handler->handle($request);
    }

    /**
     * The Host the browser actually sent, including any port. Slim's PSR-7
     * request rewrites the Host header from the URI without the port, so
     * the raw server value is preferred.
     */
    private function rawHost(Request $request): string
    {
        $raw = $request->getServerParams()['HTTP_HOST'] ?? null;

        return is_string($raw) && $raw !== '' ? $raw : $request->getHeaderLine('Host');
    }

    private function isSameOrigin(string $origin, string $host): bool
    {
        $parts = parse_url($origin);

        if (!is_array($parts) || !isset($parts['host']) || $host === '') {
            return false; // includes the literal "null" origin
        }

        $originHost = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return strcasecmp($originHost, $host) === 0;
    }
}
