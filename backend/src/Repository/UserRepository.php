<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\User;
use PDO;

final class UserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function find(string $id): ?User
    {
        $stmt = $this->pdo->prepare('SELECT id, timezone FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ? new User($row['id'], $row['timezone']) : null;
    }

    public function create(string $id, string $timezone): User
    {
        $stmt = $this->pdo->prepare('INSERT INTO users (id, timezone) VALUES (?, ?)');
        $stmt->execute([$id, $timezone]);

        return new User($id, $timezone);
    }
}
