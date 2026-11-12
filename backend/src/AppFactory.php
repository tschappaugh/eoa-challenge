<?php

declare(strict_types=1);

namespace App;

use App\Domain\Clock;
use App\Eoa\EoaApi;
use App\Http\Routes;
use Closure;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Exception\HttpException;
use Slim\Factory\AppFactory as SlimFactory;
use Throwable;

final class AppFactory
{
    /**
     * Request bodies are parsed explicitly (JSON only) in Json::body, so no
     * generic body-parsing middleware is registered.
     *
     * @param callable(): PDO $pdoFactory Lazily creates the DB connection.
     * @return App<\Psr\Container\ContainerInterface|null>
     */
    public static function create(
        callable $pdoFactory,
        EoaApi $eoa,
        ?Clock $clock = null,
        bool $debug = false,
    ): App {
        $app = SlimFactory::create();
        $services = new Services(Closure::fromCallable($pdoFactory), $eoa, $clock ?? new Clock());

        $app->get('/api/health', function (Request $request, Response $response) use ($services) {
            try {
                $services->pdo()->query('SELECT 1');
            } catch (Throwable) {
                return Json::respond($response, ['status' => 'degraded'], 503);
            }

            return Json::respond($response, ['status' => 'ok']);
        });

        Routes::register($app, $services);

        $errors = $app->addErrorMiddleware($debug, true, true);
        $errors->setDefaultErrorHandler(
            function (Request $request, Throwable $exception) use ($app, $debug): Response {
                $response = $app->getResponseFactory()->createResponse();

                if ($exception instanceof HttpException) {
                    $status = $exception->getCode();

                    return Json::error(
                        $response,
                        match ($status) {
                            404 => 'NOT_FOUND',
                            405 => 'METHOD_NOT_ALLOWED',
                            default => 'HTTP_ERROR',
                        },
                        $exception->getMessage(),
                        $status
                    );
                }

                return Json::error(
                    $response,
                    'INTERNAL_ERROR',
                    $debug ? $exception->getMessage() : 'Something went wrong. Please try again.',
                    500
                );
            }
        );

        return $app;
    }
}
