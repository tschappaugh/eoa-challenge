<?php

declare(strict_types=1);

namespace App\Http;

use App\Exception\ApiException;
use App\Json;
use App\Services;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

final class Routes
{
    /** @param App<\Psr\Container\ContainerInterface|null> $app */
    public static function register(App $app, Services $services): void
    {
        $app->group('/api', function (RouteCollectorProxy $api) use ($services): void {
            $api->get('/me', function (Request $request, Response $response) use ($services) {
                $identity = self::identity($request);

                return Json::respond($response, $services->limits()->status($identity->user(), $identity->timezone()));
            });

            $api->get('/games', function (Request $request, Response $response) use ($services) {
                $games = $services->games()->list(self::identity($request)->user());

                return Json::respond($response, ['games' => $games]);
            });

            $api->post('/games', function (Request $request, Response $response) use ($services) {
                $title = Json::body($request)['title'] ?? null;
                $game = $services->games()->add(self::identity($request)->require(), $title);

                return Json::respond($response, $game, 201);
            });

            $api->delete('/games/{id}', function (
                Request $request,
                Response $response,
                array $args
            ) use ($services) {
                $services->games()->remove(self::identity($request)->user(), self::id($args));

                return Json::respond($response, ['success' => true]);
            });

            $api->post('/games/{id}/vote', function (
                Request $request,
                Response $response,
                array $args
            ) use ($services) {
                $services->votes()->vote(self::identity($request)->require(), self::id($args));

                return Json::respond($response, ['success' => true]);
            });

            $api->delete('/games/{id}/vote', function (
                Request $request,
                Response $response,
                array $args
            ) use ($services) {
                $services->votes()->removeVote(self::identity($request)->user(), self::id($args));

                return Json::respond($response, ['success' => true]);
            });
        })
            // Added innermost-first: Identity wraps ApiError wraps the guard,
            // so guard and service errors are rendered as JSON and a newly
            // created identity still gets its cookie on an error response.
            ->add(new RequestGuardMiddleware())
            ->add(new ApiErrorMiddleware($app->getResponseFactory()))
            ->add(new IdentityMiddleware($services));
    }

    private static function identity(Request $request): Identity
    {
        $identity = $request->getAttribute(Identity::class);
        assert($identity instanceof Identity);

        return $identity;
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
