<?php

declare(strict_types=1);

namespace App\Eoa;

interface EoaApi
{
    /** @return list<array{id: int, name: string, votes: int}> */
    public function listGames(): array;

    /** @throws UpstreamUnavailable */
    public function addGame(string $name): int;

    /** @return bool false when the game id does not exist upstream */
    public function vote(int $id): bool;

    /** @return bool false when the game id does not exist upstream */
    public function removeVote(int $id): bool;

    /** @return bool false when the game id does not exist upstream */
    public function removeGame(int $id): bool;
}
