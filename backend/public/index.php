<?php

declare(strict_types=1);

use App\AppFactory;
use App\Config;
use App\Database;

require __DIR__ . '/../vendor/autoload.php';

$config = Config::fromEnv();
$pdo = null;

$app = AppFactory::create(
    function () use ($config, &$pdo): PDO {
        return $pdo ??= Database::connect($config);
    },
    $config->debug
);

$app->run();
