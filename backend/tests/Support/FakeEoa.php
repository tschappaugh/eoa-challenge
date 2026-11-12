<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Eoa\EoaApi;
use App\Eoa\UpstreamUnavailable;

/** In-memory stand-in for the EOA API, mimicking its observed quirks. */
final class FakeEoa implements EoaApi
{
    /** @var array<int, array{name: string, votes: int}> */
    public array $games = [];
    public bool $down = false;
    /** @var list<string> methods that should fail with UpstreamUnavailable */
    public array $failOn = [];
    /** @var list<string> */
    public array $calls = [];
    private int $nextId = 1;

    /** Add a game directly, as if created outside our app. */
    public function seed(string $name, int $votes = 0): int
    {
        $id = $this->nextId++;
        $this->games[$id] = ['name' => htmlspecialchars($name, ENT_QUOTES | ENT_HTML5), 'votes' => $votes];

        return $id;
    }

    public function listGames(): array
    {
        $this->enter('listGames');

        $list = [];
        foreach ($this->games as $id => $game) {
            $list[] = ['id' => $id, 'name' => $game['name'], 'votes' => $game['votes']];
        }

        return $list;
    }

    public function addGame(string $name): int
    {
        $this->enter('addGame');

        return $this->seed($name);
    }

    public function vote(int $id): bool
    {
        $this->enter('vote');
        if (!isset($this->games[$id])) {
            return false;
        }
        $this->games[$id]['votes']++;

        return true;
    }

    public function removeVote(int $id): bool
    {
        $this->enter('removeVote');
        if (!isset($this->games[$id])) {
            return false;
        }
        $this->games[$id]['votes'] = max(0, $this->games[$id]['votes'] - 1);

        return true;
    }

    public function removeGame(int $id): bool
    {
        $this->enter('removeGame');
        if (!isset($this->games[$id])) {
            return false;
        }
        unset($this->games[$id]);

        return true;
    }

    public function votes(int $id): int
    {
        return $this->games[$id]['votes'];
    }

    public function countCalls(string $method): int
    {
        return count(array_filter($this->calls, fn (string $c) => $c === $method));
    }

    private function enter(string $method): void
    {
        $this->calls[] = $method;

        if ($this->down || in_array($method, $this->failOn, true)) {
            throw new UpstreamUnavailable("fake upstream down ($method)");
        }
    }
}
