<?php

declare(strict_types=1);

namespace App\Http;

use App\Exception\ApiException;
use App\Json;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/** Turns ApiException into the standard JSON error shape. */
final class ApiErrorMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ResponseFactoryInterface $responses)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        try {
            return $handler->handle($request);
        } catch (ApiException $e) {
            if ($e->getPrevious() !== null) {
                error_log('Upstream problem: ' . $e->getPrevious()->getMessage());
            }

            return Json::error($this->responses->createResponse(), $e->errorCode, $e->getMessage(), $e->status);
        }
    }
}
