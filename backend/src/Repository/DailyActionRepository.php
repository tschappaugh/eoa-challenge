<?php

declare(strict_types=1);

namespace App\Repository;

use App\Db;
use PDO;
use PDOException;

/**
 * One row per user per local day. The (user_id, local_date) primary key is
 * what enforces "one vote OR one new game per day", even under concurrency.
 */
final class DailyActionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return bool false when the user has already used that day's action */
    public function claim(string $userId, string $date, string $type, ?int $refId): bool
    {
        try {
            $this->pdo->prepare(
                'INSERT INTO daily_actions (user_id, local_date, type, ref_id) VALUES (?, ?, ?, ?)'
            )->execute([$userId, $date, $type, $refId]);
        } catch (PDOException $e) {
            if (Db::isDuplicate($e)) {
                return false;
            }

            throw $e;
        }

        return true;
    }

    /** @return array{type: string, ref_id: ?int}|null */
    public function find(string $userId, string $date): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT type, ref_id FROM daily_actions WHERE user_id = ? AND local_date = ?'
        );
        $stmt->execute([$userId, $date]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return null;
        }

        return [
            'type' => (string) $row['type'],
            'ref_id' => $row['ref_id'] === null ? null : (int) $row['ref_id'],
        ];
    }

    public function setRef(string $userId, string $date, int $refId): void
    {
        $this->pdo->prepare('UPDATE daily_actions SET ref_id = ? WHERE user_id = ? AND local_date = ?')
            ->execute([$refId, $userId, $date]);
    }

    public function release(string $userId, string $date): void
    {
        $this->pdo->prepare('DELETE FROM daily_actions WHERE user_id = ? AND local_date = ?')
            ->execute([$userId, $date]);
    }

    /** Refund only if that day's action is exactly this type and target. */
    public function releaseIf(string $userId, string $date, string $type, int $refId): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM daily_actions WHERE user_id = ? AND local_date = ? AND type = ? AND ref_id = ?'
        );
        $stmt->execute([$userId, $date, $type, $refId]);

        return $stmt->rowCount() > 0;
    }
}
