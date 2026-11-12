<?php

declare(strict_types=1);

namespace App\Repository;

use App\Db;
use App\Exception\ApiException;
use PDO;
use PDOException;

/**
 * Local mirror of the games that exist upstream. Rows reserved by the add
 * flow have api_id = NULL until the upstream call succeeds. Rows with
 * created_by = NULL were imported from the API and have no owner.
 */
final class GameRepository
{
    /** Local rows younger than this are never purged by a list sync. */
    private const PURGE_GRACE_SECONDS = 60;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{id: int, api_id: ?int, title: string, created_by: ?string}|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, api_id, title, created_by FROM games WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    /** @throws ApiException DUPLICATE_TITLE */
    public function reserve(string $title, string $normalized, string $userId): int
    {
        try {
            $this->pdo->prepare('INSERT INTO games (title, title_normalized, created_by) VALUES (?, ?, ?)')
                ->execute([$title, $normalized, $userId]);
        } catch (PDOException $e) {
            if (Db::isDuplicate($e)) {
                throw ApiException::duplicateTitle();
            }

            throw $e;
        }

        return (int) $this->pdo->lastInsertId();
    }

    public function finalize(int $id, int $apiId): void
    {
        Db::transaction($this->pdo, function () use ($id, $apiId): void {
            // A list sync may have imported this upstream game in the meantime.
            $this->pdo->prepare('DELETE FROM games WHERE api_id = ? AND id <> ?')->execute([$apiId, $id]);
            $this->pdo->prepare('UPDATE games SET api_id = ? WHERE id = ?')->execute([$apiId, $id]);
        });
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM games WHERE id = ?')->execute([$id]);
    }

    /** @return array<int, array{id: int, api_id: ?int, title: string, created_by: ?string}> keyed by api_id */
    public function synced(): array
    {
        $rows = $this->pdo->query(
            'SELECT id, api_id, title, created_by FROM games WHERE api_id IS NOT NULL'
        )->fetchAll();

        $byApiId = [];
        foreach ($rows as $row) {
            $game = $this->hydrate($row);
            $byApiId[$game['api_id']] = $game;
        }

        return $byApiId;
    }

    /** @return list<string> */
    public function reservedTitles(): array
    {
        return $this->pdo->query('SELECT title_normalized FROM games WHERE api_id IS NULL')
            ->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Remove local rows whose upstream game is gone (ignoring brand-new ones). */
    public function purgeMissing(array $upstreamIds): void
    {
        $sql = 'DELETE FROM games WHERE api_id IS NOT NULL AND created_at < (NOW() - INTERVAL '
            . self::PURGE_GRACE_SECONDS . ' SECOND)';

        if ($upstreamIds !== []) {
            $sql .= ' AND api_id NOT IN (' . implode(',', array_fill(0, count($upstreamIds), '?')) . ')';
        }

        $this->pdo->prepare($sql)->execute(array_values($upstreamIds));
    }

    /** Add an upstream-only game locally. It has no owner, so nobody can remove it. */
    public function import(int $apiId, string $title, string $normalized): void
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO games (api_id, title, title_normalized, created_by) VALUES (?, ?, ?, NULL)'
        );

        try {
            $insert->execute([$apiId, $title, $normalized]);
        } catch (PDOException $e) {
            if (!Db::isDuplicate($e)) {
                throw $e;
            }

            // Same title as another game (the API allows duplicates): disambiguate.
            try {
                $insert->execute([$apiId, $title, mb_substr($normalized, 0, 85) . '#' . $apiId]);
            } catch (PDOException $e2) {
                if (!Db::isDuplicate($e2)) {
                    throw $e2;
                }
                // Another request imported it first.
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, api_id: ?int, title: string, created_by: ?string}
     */
    private function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'api_id' => $row['api_id'] === null ? null : (int) $row['api_id'],
            'title' => $row['title'],
            'created_by' => $row['created_by'],
        ];
    }
}
