<?php

declare(strict_types=1);

namespace App;

final class Config
{
    public function __construct(
        public readonly string $dbHost,
        public readonly string $dbName,
        public readonly string $dbUser,
        public readonly string $dbPassword,
        public readonly string $apiBaseUrl,
        public readonly string $apiKey,
        public readonly bool $debug,
    ) {
    }

    public static function fromEnv(): self
    {
        return new self(
            dbHost: self::env('DB_HOST', 'db'),
            dbName: self::env('DB_NAME', 'gamevoting'),
            dbUser: self::env('DB_USER', 'gamevoting'),
            dbPassword: self::env('DB_PASSWORD', 'gamevoting'),
            apiBaseUrl: rtrim(
                self::env('EOA_API_BASE_URL', 'https://codechallenge.essensedesigns.info'),
                '/'
            ),
            apiKey: self::env('EOA_API_KEY', ''),
            debug: self::env('APP_DEBUG', '0') === '1',
        );
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? $default : $value;
    }
}
