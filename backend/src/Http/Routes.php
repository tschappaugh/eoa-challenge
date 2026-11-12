<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\User;
use App\Exception\ApiException;
use App\Json;
use App\Services;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

final class Routes
{
    public static function register(App $app, Services $services): void
    {
        $app->group('/api', function (RouteCollectorProxy $api) use ($services): void {
            $api->get('/me', function (Request $request, Response $response) use ($services) {
                return Json::respond($response, $services->limits()->status(self::user($request)));
            });

            $api->get('/games', function (Request $request, Response $response) use ($services) {
                return Json::respond($response, ['games' => $services->games()->list(self::user($request))]);
            });

            $api->post('/games', function (Request $request, Response $response) use ($services) {
                $body = $request->getParsedBody();
                $title = is_array($body) ? ($body['title'] ?? null) : null;

                return Json::respond($response, $services->games()->add(self::user($request), $title), 201);
            });

            $api->delete('/games/{id}', function (Request $request, Response $response, array $args) use ($services) {
                $services->games()->remove(self::user($request), self::id($args));

                return Json::respond($response, ['success' => true]);
            });

            $api->post('/games/{id}/vote', function (Request $request, Response $response, array $args) use ($services) {
                $services->votes()->vote(self::user($request), self::id($args));

                return Json::respond($response, ['success' => true]);
            });

            $api->delete('/games/{id}/vote', function (Request $request, Response $response, array $args) use ($services) {
                $services->votes()->removeVote(self::user($request), self::id($args));

                return Json::respond($response, ['success' => true]);
            });
        })
            ->add(new ApiErrorMiddleware($app->getResponseFactory()))
            ->add(new UserMiddleware($services));
    }

    private static function user(Request $request): User
    {
        $user = $request->getAttribute('user');
        assert($user instanceof User);

        return $user;
    }

    /** @param array<string, string> $args */
    private static function id(array $args): int
    {
        $raw = $args['id'] ?? '';

        if (!ctype_digit($raw) || (int) $raw < 1) {
            throw ApiException::notFound();
        }

        return (int) $raw;
    }
}
