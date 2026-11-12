<?php

declare(strict_types=1);

namespace App\Service;

use App\Db;
use App\Domain\Clock;
use App\Domain\User;
use App\Eoa\EoaApi;
use App\Eoa\UpstreamUnavailable;
use App\Exception\ApiException;
use App\Repository\DailyActionRepository;
use App\Repository\GameRepository;
use App\Repository\VoteRepository;
use PDO;
use Throwable;

final class VoteService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly EoaApi $eoa,
        private readonly Clock $clock,
        private readonly GameRepository $games,
        private readonly VoteRepository $votes,
        private readonly DailyActionRepository $actions,
    ) {
    }

    public function vote(User $user, int $gameId): void
    {
        $game = $this->games->find($gameId);

        if ($game === null || $game['api_id'] === null) {
            throw ApiException::notFound();
        }

        if ($this->votes->find($user->id, $gameId) !== null) {
            throw ApiException::alreadyVoted();
        }

        $today = $this->clock->today($user);

        // One transaction: the day's action and the vote are both taken or neither.
        Db::transaction($this->pdo, function () use ($user, $gameId, $today): void {
            if (!$this->actions->claim($user->id, $today, 'vote', $gameId)) {
                throw ApiException::dailyLimit($this->clock->secondsUntilMidnight($user));
            }
            $this->votes->insert($user->id, $gameId, $today);
        });

        try {
            $exists = $this->eoa->vote($game['api_id']);
        } catch (UpstreamUnavailable $e) {
            $this->undoVote($user, $gameId, $today);
            throw ApiException::upstream($e);
        }

        if (!$exists) {
            $this->undoVote($user, $gameId, $today);
            $this->games->delete($gameId);

            throw ApiException::notFound('That game is no longer in the library.');
        }
    }

    /**
     * Removing a vote never costs an action. If the vote was cast today the
     * day's action is handed back (undo); older votes refund nothing.
     */
    public function removeVote(?User $user, int $gameId): void
    {
        $game = $this->games->find($gameId);

        if ($game === null || $game['api_id'] === null) {
            throw ApiException::notFound();
        }

        if ($user === null) {
            throw ApiException::notFound("You haven't voted for this game.");
        }

        $today = $this->clock->today($user);

        $castDate = Db::transaction(
            $this->pdo,
            function () use ($user, $gameId, $today): string {
                $vote = $this->votes->find($user->id, $gameId);

                if ($vote === null || !$this->votes->delete($user->id, $gameId)) {
                    throw ApiException::notFound("You haven't voted for this game.");
                }

                if ($vote['cast_local_date'] === $today) {
                    $this->actions->releaseIf($user->id, $today, 'vote', $gameId);
                }

                return $vote['cast_local_date'];
            }
        );

        try {
            if (!$this->eoa->removeVote($game['api_id'])) {
                // Gone upstream: nothing left to un-vote, so drop it locally too.
                $this->games->delete($gameId);
            }
        } catch (UpstreamUnavailable $e) {
            $this->restoreVote($user, $gameId, $castDate, $today);
            throw ApiException::upstream($e);
        }
    }

    private function undoVote(User $user, int $gameId, string $today): void
    {
        Db::transaction($this->pdo, function () use ($user, $gameId, $today): void {
            $this->votes->delete($user->id, $gameId);
            $this->actions->releaseIf($user->id, $today, 'vote', $gameId);
        });
    }

    /**
     * Best effort: put the vote (and today's action) back after the upstream
     * un-vote failed. If that is no longer possible, for example because the
     * game was removed meanwhile, log it and let the caller report the
     * original upstream error rather than a 500.
     */
    private function restoreVote(User $user, int $gameId, string $castDate, string $today): void
    {
        try {
            Db::transaction($this->pdo, function () use ($user, $gameId, $castDate, $today): void {
                $this->votes->insert($user->id, $gameId, $castDate);

                // false means the freed action was already used again; keep the vote anyway.
                if ($castDate === $today) {
                    $this->actions->claim($user->id, $today, 'vote', $gameId);
                }
            });
        } catch (Throwable $e) {
            error_log(sprintf('Could not restore vote for game %d: %s', $gameId, $e->getMessage()));
        }
    }
}
