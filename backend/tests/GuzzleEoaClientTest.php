<?php

declare(strict_types=1);

namespace Tests;

use App\Eoa\GuzzleEoaClient;
use App\Eoa\UpstreamUnavailable;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class GuzzleEoaClientTest extends TestCase
{
    private const KEY = 'unit-test-key-value';

    /** @var array<mixed>|\ArrayAccess<int, array<mixed>> */
    private array|\ArrayAccess $history = [];

    private function client(Response|ConnectException ...$responses): GuzzleEoaClient
    {
        $stack = HandlerStack::create(new MockHandler(array_values($responses)));
        $stack->push(Middleware::history($this->history));

        return new GuzzleEoaClient(
            new Client(['handler' => $stack, 'base_uri' => 'http://eoa.test', 'http_errors' => false]),
            self::KEY
        );
    }

    private function json(mixed $data, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($data));
    }

    public function testListGamesMapsTheResponse(): void
    {
        $client = $this->client($this->json(['games' => [['id' => 1, 'name' => 'Zelda', 'votes' => 3]]]));

        $this->assertSame([['id' => 1, 'name' => 'Zelda', 'votes' => 3]], $client->listGames());
    }

    public function testRequestsAreFormEncodedWithTheKeyInTheBody(): void
    {
        $this->client($this->json(['id' => 4]))->addGame('Zelda');

        $request = $this->history[0]['request'] ?? null;
        $this->assertInstanceOf(Request::class, $request);
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/games/add', $request->getUri()->getPath());
        $this->assertStringContainsString('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        parse_str((string) $request->getBody(), $form);
        $this->assertSame(['api_key' => self::KEY, 'name' => 'Zelda'], $form);
        $this->assertStringNotContainsString(self::KEY, (string) $request->getUri());
    }

    public function testAddGameReturnsTheNewId(): void
    {
        $this->assertSame(7, $this->client($this->json(['id' => 7]))->addGame('Zelda'));
    }

    public function testAddGameTreatsMinusOneAsFailure(): void
    {
        $this->expectException(UpstreamUnavailable::class);
        $this->client($this->json(['id' => -1]))->addGame('Zelda');
    }

    public function testVoteReportsUnknownGamesAsFalse(): void
    {
        $client = $this->client($this->json(['success' => true]), $this->json(['success' => false]));

        $this->assertTrue($client->vote(1));
        $this->assertFalse($client->vote(999));
    }

    public function testRemoveVoteAndRemoveGameReadTheSuccessFlag(): void
    {
        $client = $this->client($this->json(['success' => true]), $this->json(['success' => false]));

        $this->assertTrue($client->removeVote(1));
        $this->assertFalse($client->removeGame(1));
    }

    public function testAuthErrorsBecomeUpstreamUnavailable(): void
    {
        $this->expectException(UpstreamUnavailable::class);
        $this->expectExceptionMessage('INVALID_KEY');
        $this->client($this->json(['error' => 'INVALID_KEY'], 403))->listGames();
    }

    public function testHtmlErrorPagesBecomeUpstreamUnavailable(): void
    {
        $this->expectException(UpstreamUnavailable::class);
        $this->client(new Response(405, [], '<!DOCTYPE html><title>Method Not Allowed</title>'))->listGames();
    }

    public function testServerErrorsBecomeUpstreamUnavailable(): void
    {
        $this->expectException(UpstreamUnavailable::class);
        $this->client($this->json([], 503))->listGames();
    }

    public function testMalformedListBecomesUpstreamUnavailable(): void
    {
        $this->expectException(UpstreamUnavailable::class);
        $this->client($this->json(['games' => [['id' => 'x']]]))->listGames();
    }

    public function testNetworkFailuresBecomeUpstreamUnavailableWithoutLeakingTheKey(): void
    {
        $client = $this->client(new ConnectException('timeout', new Request('POST', '/games/list')));

        try {
            $client->listGames();
            $this->fail('Expected UpstreamUnavailable');
        } catch (UpstreamUnavailable $e) {
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
        }
    }
}
