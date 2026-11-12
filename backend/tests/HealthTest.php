<?php

declare(strict_types=1);

namespace Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Slim\Psr7\Factory\ServerRequestFactory;
use App\AppFactory;

final class HealthTest extends TestCase
{
    public function testHealthIsOkWhenDatabaseResponds(): void
    {
        $app = AppFactory::create(fn () => new PDO('sqlite::memory:'));

        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/api/health'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('{"status":"ok"}', (string) $response->getBody());
    }

    public function testHealthIsDegradedWhenDatabaseFails(): void
    {
        $app = AppFactory::create(fn () => throw new RuntimeException('down'));

        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/api/health'));

        $this->assertSame(503, $response->getStatusCode());
    }

    public function testUnknownRouteReturnsJsonError(): void
    {
        $app = AppFactory::create(fn () => new PDO('sqlite::memory:'));

        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/api/nope'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('NOT_FOUND', json_decode((string) $response->getBody(), true)['error']['code']);
    }
}
