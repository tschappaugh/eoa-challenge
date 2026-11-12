<?php

declare(strict_types=1);

namespace App\Repository;

use App\Db;
use App\Exception\ApiException;
use PDO;
use PDOException;

final class VoteRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{user_id: string, game_id: int, cast_local_date: string}|null */
    public function find(string $userId, int $gameId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT user_id, game_id, cast_local_date FROM votes WHERE user_id = ? AND game_id = ?'
        );
        $stmt->execute([$userId, $gameId]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return null;
        }

        return [
            'user_id' => (string) $row['user_id'],
            'game_id' => (int) $row['game_id'],
            'cast_local_date' => (string) $row['cast_local_date'],
        ];
    }

    /** @throws ApiException ALREADY_VOTED */
    public function insert(string $userId, int $gameId, string $castDate): void
    {
        try {
            $this->pdo->prepare('INSERT INTO votes (user_id, game_id, cast_local_date) VALUES (?, ?, ?)')
                ->execute([$userId, $gameId, $castDate]);
        } catch (PDOException $e) {
            if (Db::isDuplicate($e)) {
                throw ApiException::alreadyVoted();
            }

            throw $e;
        }
    }

    public function delete(string $userId, int $gameId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM votes WHERE user_id = ? AND game_id = ?');
        $stmt->execute([$userId, $gameId]);

        return $stmt->rowCount() > 0;
    }

    /** @return list<int> */
    public function votedGameIds(string $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT game_id FROM votes WHERE user_id = ?');
        $stmt->execute([$userId]);

        return array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** @return list<array{user_id: string, cast_local_date: string, timezone: string}> */
    public function forGame(int $gameId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT v.user_id, v.cast_local_date, u.timezone
             FROM votes v JOIN users u ON u.id = v.user_id
             WHERE v.game_id = ?'
        );
        $stmt->execute([$gameId]);

        return array_values(array_map(static fn (array $row): array => [
            'user_id' => (string) $row['user_id'],
            'cast_local_date' => (string) $row['cast_local_date'],
            'timezone' => (string) $row['timezone'],
        ], $stmt->fetchAll()));
    }
}
