<?php

declare(strict_types=1);

namespace Tests;

use App\AppFactory;
use App\Domain\Clock;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Tests\Support\DatabaseTestCase;

final class HttpTest extends DatabaseTestCase
{
    /** @var App<\Psr\Container\ContainerInterface|null> */
    private App $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = AppFactory::create(fn () => $this->pdo, $this->eoa, new Clock(fn () => $this->now));
    }

    /**
     * Writes get the CSRF header and a JSON content type by default, like
     * the real frontend sends.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     */
    private function call(
        string $method,
        string $path,
        ?array $body = null,
        ?string $uid = null,
        array $headers = [],
        bool $guard = true,
    ): ResponseInterface {
        // Built like production: absolute URI plus the raw HTTP_HOST server
        // param (Slim then rewrites the Host header to drop the port).
        $request = (new ServerRequestFactory())->createServerRequest(
            $method,
            'http://localhost:8080' . $path,
            ['HTTP_HOST' => 'localhost:8080']
        );

        if ($guard && $method !== 'GET') {
            $request = $request->withHeader('X-Requested-With', 'XMLHttpRequest');
        }
        if ($uid !== null) {
            $request = $request->withCookieParams(['uid' => $uid]);
        }
        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody((new StreamFactory())->createStream((string) json_encode($body)));
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->app->handle($request);
    }

    /** @return array<string, mixed> */
    private function data(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($data);

        return $data;
    }

    private function errorCode(ResponseInterface $response): string
    {
        $error = $this->data($response)['error'] ?? null;
        $this->assertIsArray($error);

        return (string) $error['code'];
    }

    private function uid(ResponseInterface $response): string
    {
        $this->assertSame(1, preg_match('/^uid=([0-9a-f-]{36});/', $response->getHeaderLine('Set-Cookie'), $m));

        return $m[1] ?? '';
    }

    private function userCount(): int
    {
        return $this->rowCount('users');
    }

    public function testReadsNeverCreateAnIdentity(): void
    {
        $me = $this->call('GET', '/api/me', headers: ['X-Timezone' => 'America/Chicago']);
        $games = $this->call('GET', '/api/games');

        $this->assertSame('', $me->getHeaderLine('Set-Cookie'));
        $this->assertSame('', $games->getHeaderLine('Set-Cookie'));
        $this->assertSame(0, $this->userCount());
        $this->assertTrue($this->data($me)['actionAvailable']);
        $this->assertSame('America/Chicago', $this->data($me)['timezone']);
    }

    public function testFirstWriteCreatesAnHttpOnlyCookieAndLocksTheTimezone(): void
    {
        $created = $this->call(
            'POST',
            '/api/games',
            ['title' => 'Zelda'],
            headers: ['X-Timezone' => 'America/Chicago']
        );
        $cookie = $created->getHeaderLine('Set-Cookie');
        $uid = $this->uid($created);

        $this->assertStringContainsString('HttpOnly', $cookie);
        $this->assertStringContainsString('SameSite=Lax', $cookie);
        $this->assertStringNotContainsString('Secure', $cookie);

        $me = $this->call('GET', '/api/me', uid: $uid, headers: ['X-Timezone' => 'Pacific/Auckland']);
        $this->assertSame('', $me->getHeaderLine('Set-Cookie'));
        $this->assertSame('America/Chicago', $this->data($me)['timezone']);
    }

    public function testCookieIsSecureBehindHttps(): void
    {
        $response = $this->call('POST', '/api/games', ['title' => 'Zelda'], headers: ['X-Forwarded-Proto' => 'https']);

        $this->assertStringContainsString('; Secure', $response->getHeaderLine('Set-Cookie'));
    }

    public function testInvalidTimezoneFallsBackToUtc(): void
    {
        $response = $this->call('GET', '/api/me', headers: ['X-Timezone' => 'Not/AZone']);

        $this->assertSame('UTC', $this->data($response)['timezone']);
    }

    public function testForgedCookieIsIgnoredAndReplacedOnWrite(): void
    {
        $read = $this->call('GET', '/api/me', uid: 'not-a-uuid');
        $write = $this->call('POST', '/api/games', ['title' => 'Zelda'], uid: 'not-a-uuid');

        $this->assertSame('', $read->getHeaderLine('Set-Cookie'));
        $this->assertNotSame('not-a-uuid', $this->uid($write));
    }

    public function testWritesWithoutTheCsrfHeaderAreRejected(): void
    {
        $response = $this->call('POST', '/api/games', ['title' => 'Zelda'], guard: false);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('REQUEST_REJECTED', $this->errorCode($response));
        $this->assertSame([], $this->eoa->games);
        $this->assertSame(0, $this->userCount());
    }

    public function testWritesFromAnotherOriginAreRejected(): void
    {
        foreach (['https://evil.example', 'http://localhost:9999', 'null'] as $origin) {
            $response = $this->call('POST', '/api/games', ['title' => 'Zelda'], headers: ['Origin' => $origin]);
            $this->assertSame(403, $response->getStatusCode(), $origin);
        }

        $same = $this->call(
            'POST',
            '/api/games',
            ['title' => 'Metroid'],
            headers: ['Origin' => 'http://localhost:8080']
        );
        $this->assertSame(201, $same->getStatusCode());
    }

    public function testSameOriginCheckUsesTheRealHostIncludingPort(): void
    {
        $response = $this->call(
            'POST',
            '/api/games',
            ['title' => 'Zelda'],
            headers: ['Origin' => 'http://localhost:8080']
        );

        $this->assertSame(201, $response->getStatusCode());
    }

    public function testOnlyJsonBodiesAreAccepted(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/games')
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody((new StreamFactory())->createStream('title=Zelda'));

        $response = $this->app->handle($request);

        $this->assertSame(415, $response->getStatusCode());
        $this->assertSame('UNSUPPORTED_MEDIA_TYPE', $this->errorCode($response));
    }

    public function testMalformedJsonIsAValidationError(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/games')
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream('{"title":'));

        $this->assertSame(422, $this->app->handle($request)->getStatusCode());
    }

    public function testAddListAndOwnershipEndToEnd(): void
    {
        $created = $this->call('POST', '/api/games', ['title' => 'Zelda']);
        $this->assertSame(201, $created->getStatusCode());
        $uid = $this->uid($created);

        $mine = $this->data($this->call('GET', '/api/games', uid: $uid))['games'];
        $anonymous = $this->data($this->call('GET', '/api/games'))['games'];

        $this->assertTrue($mine[0]['canRemove']);
        $this->assertFalse($anonymous[0]['canRemove']);
        $this->assertFalse($anonymous[0]['votedByMe']);
    }

    public function testValidationErrorsUseTheStandardShape(): void
    {
        $response = $this->call('POST', '/api/games', ['title' => '   ']);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('VALIDATION_FAILED', $this->errorCode($response));
    }

    public function testVoteAndUnvoteOverHttp(): void
    {
        $owner = $this->uid($this->call('POST', '/api/games', ['title' => 'Zelda']));
        $id = $this->gameId('Zelda');

        $voted = $this->call('POST', "/api/games/$id/vote");
        $voter = $this->uid($voted);
        $this->assertSame(200, $voted->getStatusCode());
        $this->assertSame(409, $this->call('POST', "/api/games/$id/vote", uid: $voter)->getStatusCode());

        $this->call('POST', '/api/games', ['title' => 'Metroid']);
        $other = $this->gameId('Metroid');
        $limited = $this->call('POST', "/api/games/$other/vote", uid: $voter);
        $this->assertSame(429, $limited->getStatusCode());
        $this->assertSame('DAILY_LIMIT_REACHED', $this->errorCode($limited));

        $this->assertSame(200, $this->call('DELETE', "/api/games/$id/vote", uid: $voter)->getStatusCode());
        $this->assertSame(403, $this->call('DELETE', "/api/games/$id", uid: $voter)->getStatusCode());
        $this->assertSame(200, $this->call('DELETE', "/api/games/$id", uid: $owner)->getStatusCode());
    }

    public function testDailyLimitTellsTheClientWhenToRetry(): void
    {
        $uid = $this->uid($this->call('POST', '/api/games', ['title' => 'Zelda']));

        $limited = $this->call('POST', '/api/games', ['title' => 'Metroid'], uid: $uid);

        // Test clock is 15:00 UTC, so midnight UTC is 9 hours away.
        $this->assertSame('32400', $limited->getHeaderLine('Retry-After'));
    }

    public function testAnonymousDeletesFailWithoutCreatingAUser(): void
    {
        $this->call('POST', '/api/games', ['title' => 'Zelda']);
        $id = $this->gameId('Zelda');
        $before = $this->userCount();

        $this->assertSame(404, $this->call('DELETE', "/api/games/$id/vote")->getStatusCode());
        $this->assertSame(403, $this->call('DELETE', "/api/games/$id")->getStatusCode());
        $this->assertSame($before, $this->userCount());
    }

    public function testBadIdsAreNotFound(): void
    {
        foreach (['abc', '0', '1.5', '-3'] as $id) {
            $response = $this->call('POST', "/api/games/$id/vote");
            $this->assertSame(404, $response->getStatusCode(), $id);
            $this->assertSame('NOT_FOUND', $this->errorCode($response));
        }
    }

    public function testApiResponsesAreNotCached(): void
    {
        $read = $this->call('GET', '/api/games');
        $failedWrite = $this->call('POST', '/api/games', ['title' => '']);

        $this->assertSame('no-store', $read->getHeaderLine('Cache-Control'));
        $this->assertSame('no-store', $failedWrite->getHeaderLine('Cache-Control'));
    }

    public function testUpstreamOutageIsAFriendly502(): void
    {
        $this->eoa->down = true;

        $response = $this->call('GET', '/api/games');

        $this->assertSame(502, $response->getStatusCode());
        $this->assertSame('UPSTREAM_UNAVAILABLE', $this->errorCode($response));
        $this->assertStringNotContainsString('fake upstream', (string) $response->getBody());
    }

    public function testMeReportsAvailabilityAndReset(): void
    {
        $response = $this->call('POST', '/api/games', ['title' => 'Zelda']);
        $me = $this->data($this->call('GET', '/api/me', uid: $this->uid($response)));

        $this->assertFalse($me['actionAvailable']);
        $this->assertSame('add', $me['action']['type']);
        $this->assertSame('2026-11-13T00:00:00+00:00', $me['resetsAt']);
    }
}
