<?php

declare(strict_types=1);

namespace App;

use App\Exception\ApiException;
use JsonException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class Json
{
    public static function respond(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));

        // Responses are per-user (votedByMe, canRemove, limit status).
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store')
            ->withStatus($status);
    }

    public static function error(Response $response, string $code, string $message, int $status): Response
    {
        return self::respond($response, ['error' => ['code' => $code, 'message' => $message]], $status);
    }

    /**
     * Decode a JSON request body. Other content types are refused so that
     * plain HTML forms (which can't send application/json) can't post here.
     *
     * @return array<string, mixed>
     * @throws ApiException
     */
    public static function body(Request $request): array
    {
        $type = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0]));

        if ($type !== 'application/json') {
            throw ApiException::unsupportedMediaType();
        }

        try {
            $data = json_decode((string) $request->getBody(), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ApiException::validation('The request body is not valid JSON.');
        }

        if (!is_array($data)) {
            throw ApiException::validation('The request body must be a JSON object.');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }
}
