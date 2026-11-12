<?php

declare(strict_types=1);

namespace App;

use App\Domain\Clock;
use App\Eoa\EoaApi;
use App\Http\Routes;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Exception\HttpException;
use Slim\Factory\AppFactory as SlimFactory;
use Closure;
use Throwable;

final class AppFactory
{
    /**
     * @param callable(): PDO $pdoFactory Lazily creates the DB connection.
     */
    public static function create(
        callable $pdoFactory,
        EoaApi $eoa,
        ?Clock $clock = null,
        bool $debug = false,
    ): App {
        $app = SlimFactory::create();
        $app->addBodyParsingMiddleware();

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
                    return Json::error(
                        $response,
                        $exception->getCode() === 404 ? 'NOT_FOUND' : 'HTTP_ERROR',
                        $exception->getMessage(),
                        $exception->getCode()
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
