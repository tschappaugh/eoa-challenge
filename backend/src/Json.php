<?php

declare(strict_types=1);

namespace App;

use Psr\Http\Message\ResponseInterface as Response;

final class Json
{
    public static function respond(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }

    public static function error(Response $response, string $code, string $message, int $status): Response
    {
        return self::respond($response, ['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
