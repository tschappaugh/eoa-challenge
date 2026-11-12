<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Clock;
use App\Domain\User;
use App\Exception\ApiException;
use App\Services;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/** Runs against the real MySQL test database so unique keys are exercised. */
abstract class DatabaseTestCase extends TestCase
{
    protected PDO $pdo;
    protected FakeEoa $eoa;
    protected DateTimeImmutable $now;
    protected Services $services;

    protected function setUp(): void
    {
        $name = (getenv('DB_NAME') ?: 'gamevoting') . '_test';

        try {
            $this->pdo = new PDO(
                sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST') ?: 'db', $name),
                getenv('DB_USER') ?: 'gamevoting',
                getenv('DB_PASSWORD') ?: 'gamevoting',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
        } catch (PDOException $e) {
            $this->markTestSkipped('Test database unavailable: ' . $e->getMessage());
        }

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['votes', 'daily_actions', 'games', 'users'] as $table) {
            $this->pdo->exec("TRUNCATE TABLE $table");
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $this->eoa = new FakeEoa();
        $this->now = new DateTimeImmutable('2026-11-12 15:00:00', new DateTimeZone('UTC'));
        $this->services = new Services(fn () => $this->pdo, $this->eoa, new Clock(fn () => $this->now));
    }

    protected function makeUser(string $timezone = 'UTC'): User
    {
        $id = sprintf(
            '%08x-%04x-4%03x-a%03x-%012x',
            random_int(0, 0xffffffff),
            random_int(0, 0xffff),
            random_int(0, 0xfff),
            random_int(0, 0xfff),
            random_int(0, 0xffffffffffff)
        );

        return $this->services->users()->create($id, $timezone);
    }

    protected function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }

    protected function assertApiError(string $code, callable $work): void
    {
        try {
            $work();
        } catch (ApiException $e) {
            $this->assertSame($code, $e->errorCode);

            return;
        }

        $this->fail("Expected ApiException $code");
    }

    /** Local game id for a title that exists locally. */
    protected function gameId(string $title): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM games WHERE title = ?');
        $stmt->execute([$title]);

        return (int) $stmt->fetchColumn();
    }

    protected function rowCount(string $table): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM `$table`");
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    protected function actionCount(User $user): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM daily_actions WHERE user_id = ?');
        $stmt->execute([$user->id]);

        return (int) $stmt->fetchColumn();
    }
}
