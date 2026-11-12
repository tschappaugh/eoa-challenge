<?php

declare(strict_types=1);

namespace App\Eoa;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;

/**
 * Talks to the EOA REST API. The API is permissive and reports many failures
 * as HTTP 200, so every response is validated here and anything unusable
 * becomes UpstreamUnavailable. The API key is only ever sent in the request
 * body and never appears in exception messages.
 */
final class GuzzleEoaClient implements EoaApi
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $apiKey,
    ) {
    }

    public static function create(string $baseUrl, string $apiKey): self
    {
        return new self(
            new Client([
                'base_uri' => $baseUrl,
                'timeout' => 10,
                'connect_timeout' => 5,
                'http_errors' => false,
            ]),
            $apiKey
        );
    }

    public function listGames(): array
    {
        $data = $this->post('/games/list');
        $games = $data['games'] ?? null;

        if (!is_array($games)) {
            throw new UpstreamUnavailable('EOA list response had no games array');
        }

        $result = [];
        foreach ($games as $game) {
            if (
                !is_array($game)
                || !is_int($game['id'] ?? null)
                || !is_string($game['name'] ?? null)
                || !is_int($game['votes'] ?? null)
            ) {
                throw new UpstreamUnavailable('EOA list response had a malformed game');
            }
            $result[] = ['id' => $game['id'], 'name' => $game['name'], 'votes' => $game['votes']];
        }

        return $result;
    }

    public function addGame(string $name): int
    {
        $data = $this->post('/games/add', ['name' => $name]);
        $id = $data['id'] ?? null;

        // The API answers 200 with id -1 when it rejects the name.
        if (!is_int($id) || $id < 1) {
            throw new UpstreamUnavailable('EOA did not create the game');
        }

        return $id;
    }

    public function vote(int $id): bool
    {
        return $this->succeeded($this->post('/games/vote', ['id' => $id]));
    }

    public function removeVote(int $id): bool
    {
        return $this->succeeded($this->post('/games/removeVote', ['id' => $id]));
    }

    public function removeGame(int $id): bool
    {
        return $this->succeeded($this->post('/games/remove', ['id' => $id]));
    }

    /** @param array<string, mixed> $data */
    private function succeeded(array $data): bool
    {
        return ($data['success'] ?? false) === true;
    }

    /**
     * @param array<string, scalar> $params
     * @return array<string, mixed>
     */
    private function post(string $path, array $params = []): array
    {
        try {
            $response = $this->http->request('POST', $path, [
                'form_params' => ['api_key' => $this->apiKey] + $params,
            ]);
        } catch (GuzzleException $e) {
            throw new UpstreamUnavailable('EOA request failed (' . $e::class . ')', 0, $e);
        }

        $status = $response->getStatusCode();

        try {
            $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new UpstreamUnavailable("EOA returned a non-JSON response (HTTP $status)");
        }

        if (!is_array($data)) {
            throw new UpstreamUnavailable("EOA returned an unexpected payload (HTTP $status)");
        }

        if (is_string($data['error'] ?? null)) {
            $hint = in_array($data['error'], ['INVALID_KEY', 'MISSING_KEY'], true)
                ? ' (generate a new key at /api-key, set EOA_API_KEY in .env, then restart the php container)'
                : '';

            throw new UpstreamUnavailable('EOA error: ' . $data['error'] . $hint);
        }

        if ($status >= 500) {
            throw new UpstreamUnavailable("EOA server error (HTTP $status)");
        }

        return $data;
    }
}
