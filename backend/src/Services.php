<?php

declare(strict_types=1);

namespace App;

use App\Domain\Clock;
use App\Eoa\EoaApi;
use App\Repository\DailyActionRepository;
use App\Repository\GameRepository;
use App\Repository\UserRepository;
use App\Repository\VoteRepository;
use App\Service\DailyLimitService;
use App\Service\GameService;
use App\Service\VoteService;
use Closure;
use PDO;

/** Wires services per request. The DB connection is created on first use. */
final class Services
{
    private ?PDO $pdo = null;

    public function __construct(
        private readonly Closure $pdoFactory,
        private readonly EoaApi $eoa,
        private readonly Clock $clock,
    ) {
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= ($this->pdoFactory)();
    }

    public function users(): UserRepository
    {
        return new UserRepository($this->pdo());
    }

    public function games(): GameService
    {
        return new GameService(
            $this->pdo(),
            $this->eoa,
            $this->clock,
            new GameRepository($this->pdo()),
            new VoteRepository($this->pdo()),
            new DailyActionRepository($this->pdo())
        );
    }

    public function votes(): VoteService
    {
        return new VoteService(
            $this->pdo(),
            $this->eoa,
            $this->clock,
            new GameRepository($this->pdo()),
            new VoteRepository($this->pdo()),
            new DailyActionRepository($this->pdo())
        );
    }

    public function limits(): DailyLimitService
    {
        return new DailyLimitService(new DailyActionRepository($this->pdo()), $this->clock);
    }
}
