<?php

declare(strict_types=1);

namespace App\Service;

use App\Db;
use App\Domain\Clock;
use App\Domain\Title;
use App\Domain\User;
use App\Eoa\EoaApi;
use App\Eoa\UpstreamUnavailable;
use App\Exception\ApiException;
use App\Repository\DailyActionRepository;
use App\Repository\GameRepository;
use App\Repository\VoteRepository;
use PDO;
use Throwable;

/**
 * Write path used everywhere: claim locally (atomically), call the EOA API,
 * and undo the local claim if the API call fails.
 */
final class GameService
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

    /**
     * Games sorted by votes (desc), then oldest first. Upstream is the source
     * of truth for which games exist and their vote counts.
     *
     * Anonymous visitors (no identity yet) get the same list with nothing
     * marked as theirs.
     *
     * @return list<array{id: int, title: string, votes: int, votedByMe: bool, canRemove: bool}>
     */
    public function list(?User $user): array
    {
        try {
            $upstream = $this->eoa->listGames();
        } catch (UpstreamUnavailable $e) {
            throw ApiException::upstream($e);
        }

        $local = $this->sync($upstream);
        $voted = $user === null ? [] : array_flip($this->votes->votedGameIds($user->id));

        $items = [];
        foreach ($upstream as $game) {
            $row = $local[$game['id']] ?? null;
            if ($row === null) {
                continue;
            }
            $items[] = [
                'apiId' => $game['id'],
                'id' => $row['id'],
                'title' => $row['title'],
                'votes' => max(0, $game['votes']),
                'votedByMe' => isset($voted[$row['id']]),
                'canRemove' => $user !== null && $row['created_by'] === $user->id,
            ];
        }

        usort($items, fn (array $a, array $b) => [$b['votes'], $a['apiId']] <=> [$a['votes'], $b['apiId']]);

        return array_map(static function (array $item): array {
            unset($item['apiId']);

            return $item;
        }, $items);
    }

    /**
     * @return array{id: int, title: string, votes: int, votedByMe: bool, canRemove: bool}
     */
    public function add(User $user, mixed $rawTitle): array
    {
        $title = Title::validate($rawTitle);
        $normalized = Title::normalize($title);
        $today = $this->clock->today($user);

        // Claim the day's action and the title in one transaction: if either
        // fails, neither is consumed.
        $gameId = Db::transaction($this->pdo, function () use ($user, $today, $title, $normalized): int {
            if (!$this->actions->claim($user->id, $today, 'add', null)) {
                throw ApiException::dailyLimit($this->clock->secondsUntilMidnight($user));
            }

            return $this->games->reserve($title, $normalized, $user->id);
        });

        try {
            $apiId = $this->eoa->addGame($title);
        } catch (UpstreamUnavailable $e) {
            $this->undoAdd($user, $today, $gameId);
            throw ApiException::upstream($e);
        }

        try {
            $this->games->finalize($gameId, $apiId);
            $this->actions->setRef($user->id, $today, $gameId);
        } catch (Throwable $e) {
            try {
                $this->eoa->removeGame($apiId);
            } catch (UpstreamUnavailable) {
                // Best effort; a later list sync reconciles the leftover.
            }
            $this->undoAdd($user, $today, $gameId);

            throw $e;
        }

        return ['id' => $gameId, 'title' => $title, 'votes' => 0, 'votedByMe' => false, 'canRemove' => true];
    }

    /** Only the user who added a game may remove it. */
    public function remove(?User $user, int $gameId): void
    {
        $game = $this->games->find($gameId);

        if ($game === null || $game['api_id'] === null) {
            throw ApiException::notFound();
        }

        if ($game['created_by'] === null) {
            throw ApiException::notOwner("This game wasn't added through this app, so it can't be removed here.");
        }

        if ($user === null || $game['created_by'] !== $user->id) {
            throw ApiException::notOwner('Only the person who added a game can remove it.');
        }

        try {
            // false means it is already gone upstream; cleaning up locally is still right.
            $this->eoa->removeGame($game['api_id']);
        } catch (UpstreamUnavailable $e) {
            throw ApiException::upstream($e);
        }

        Db::transaction($this->pdo, function () use ($user, $game): void {
            // Voters who spent today's action on this game get it back.
            foreach ($this->votes->forGame($game['id']) as $vote) {
                if ($vote['cast_local_date'] === $this->clock->today($vote['timezone'])) {
                    $this->actions->releaseIf($vote['user_id'], $vote['cast_local_date'], 'vote', $game['id']);
                }
            }

            // So does the owner, if they added it today.
            $this->actions->releaseIf($user->id, $this->clock->today($user), 'add', $game['id']);

            $this->games->delete($game['id']);
        });
    }

    private function undoAdd(User $user, string $today, int $gameId): void
    {
        Db::transaction($this->pdo, function () use ($user, $today, $gameId): void {
            $this->games->delete($gameId);
            $this->actions->release($user->id, $today);
        });
    }

    /**
     * Mirror upstream locally: import games added elsewhere, drop games that
     * no longer exist upstream.
     *
     * @param list<array{id: int, name: string, votes: int}> $upstream
     * @return array<int, array{id: int, api_id: ?int, title: string, created_by: ?string}> keyed by api_id
     */
    private function sync(array $upstream): array
    {
        $upstreamIds = array_column($upstream, 'id');
        $this->games->purgeMissing($upstreamIds);

        $local = $this->games->synced();
        $reserved = array_flip($this->games->reservedTitles());

        $imported = false;
        foreach ($upstream as $game) {
            if (isset($local[$game['id']])) {
                continue;
            }

            // The API stores names HTML-escaped.
            $title = Title::clean(html_entity_decode($game['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $title = $title === '' ? 'Untitled game' : mb_substr($title, 0, Title::MAX_LENGTH);
            $normalized = Title::normalize($title);

            // Our own in-flight add; finalize() will attach the API id.
            if (isset($reserved[$normalized])) {
                continue;
            }

            $this->games->import($game['id'], $title, $normalized);
            $imported = true;
        }

        return $imported ? $this->games->synced() : $local;
    }
}
