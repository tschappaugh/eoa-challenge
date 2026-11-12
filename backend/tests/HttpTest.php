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
    private App $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = AppFactory::create(fn () => $this->pdo, $this->eoa, new Clock(fn () => $this->now));
    }

    /** @param array<string, mixed>|null $body */
    private function call(
        string $method,
        string $path,
        ?array $body = null,
        ?string $uid = null,
        ?string $timezone = null,
    ): ResponseInterface {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path);

        if ($uid !== null) {
            $request = $request->withCookieParams(['uid' => $uid]);
        }
        if ($timezone !== null) {
            $request = $request->withHeader('X-Timezone', $timezone);
        }
        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody((new StreamFactory())->createStream(json_encode($body)));
        }

        return $this->app->handle($request);
    }

    /** @return array<string, mixed> */
    private function data(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true);
    }

    private function uid(ResponseInterface $response): string
    {
        $this->assertSame(1, preg_match('/^uid=([0-9a-f-]{36});/', $response->getHeaderLine('Set-Cookie'), $m));

        return $m[1];
    }

    public function testNewVisitorGetsAnHttpOnlyCookieAndTheirTimezoneIsSaved(): void
    {
        $response = $this->call('GET', '/api/me', timezone: 'America/Chicago');

        $cookie = $response->getHeaderLine('Set-Cookie');
        $this->assertStringContainsString('HttpOnly', $cookie);
        $this->assertStringContainsString('SameSite=Lax', $cookie);
        $this->assertSame('America/Chicago', $this->data($response)['timezone']);
    }

    public function testReturningVisitorKeepsTheirOriginalTimezone(): void
    {
        $first = $this->call('GET', '/api/me', timezone: 'America/Chicago');
        $uid = $this->uid($first);

        $second = $this->call('GET', '/api/me', uid: $uid, timezone: 'Pacific/Auckland');

        $this->assertSame('', $second->getHeaderLine('Set-Cookie'));
        $this->assertSame('America/Chicago', $this->data($second)['timezone']);
    }

    public function testInvalidTimezoneFallsBackToUtc(): void
    {
        $response = $this->call('GET', '/api/me', timezone: 'Not/AZone');

        $this->assertSame('UTC', $this->data($response)['timezone']);
    }

    public function testUnknownOrForgedCookieGetsAFreshIdentity(): void
    {
        $response = $this->call('GET', '/api/me', uid: 'not-a-uuid');

        $this->assertNotSame('', $response->getHeaderLine('Set-Cookie'));
    }

    public function testAddListAndOwnershipEndToEnd(): void
    {
        $created = $this->call('POST', '/api/games', ['title' => 'Zelda']);
        $this->assertSame(201, $created->getStatusCode());
        $uid = $this->uid($created);

        $mine = $this->data($this->call('GET', '/api/games', uid: $uid))['games'];
        $theirs = $this->data($this->call('GET', '/api/games'))['games'];

        $this->assertTrue($mine[0]['canRemove']);
        $this->assertFalse($theirs[0]['canRemove']);
    }

    public function testValidationErrorsUseTheStandardShapeAndStillSetTheCookie(): void
    {
        $response = $this->call('POST', '/api/games', ['title' => '   ']);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('VALIDATION_FAILED', $this->data($response)['error']['code']);
        $this->assertNotSame('', $response->getHeaderLine('Set-Cookie'));
    }

    public function testMissingBodyIsAValidationError(): void
    {
        $this->assertSame(422, $this->call('POST', '/api/games')->getStatusCode());
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
        $this->assertSame('DAILY_LIMIT_REACHED', $this->data($limited)['error']['code']);

        $this->assertSame(200, $this->call('DELETE', "/api/games/$id/vote", uid: $voter)->getStatusCode());
        $this->assertSame(403, $this->call('DELETE', "/api/games/$id", uid: $voter)->getStatusCode());
        $this->assertSame(200, $this->call('DELETE', "/api/games/$id", uid: $owner)->getStatusCode());
    }

    public function testBadIdsAreNotFound(): void
    {
        foreach (['abc', '0', '1.5', '-3'] as $id) {
            $response = $this->call('POST', "/api/games/$id/vote");
            $this->assertSame(404, $response->getStatusCode(), $id);
            $this->assertSame('NOT_FOUND', $this->data($response)['error']['code']);
        }
    }

    public function testUpstreamOutageIsAFriendly502(): void
    {
        $this->eoa->down = true;

        $response = $this->call('GET', '/api/games');

        $this->assertSame(502, $response->getStatusCode());
        $this->assertSame('UPSTREAM_UNAVAILABLE', $this->data($response)['error']['code']);
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
