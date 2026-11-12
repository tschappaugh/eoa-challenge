<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;
use Throwable;

final class Db
{
    private const MYSQL_DUPLICATE_KEY = 1062;

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function transaction(PDO $pdo, callable $work): mixed
    {
        $pdo->beginTransaction();

        try {
            $result = $work();
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    public static function isDuplicate(PDOException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === self::MYSQL_DUPLICATE_KEY;
    }
}
