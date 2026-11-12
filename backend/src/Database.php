<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Database
{
    public static function connect(Config $config): PDO
    {
        return new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config->dbHost, $config->dbName),
            $config->dbUser,
            $config->dbPassword,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }
}
