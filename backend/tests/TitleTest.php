<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\Title;
use App\Exception\ApiException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TitleTest extends TestCase
{
    public function testCleanTrimsAndCollapsesWhitespace(): void
    {
        $this->assertSame('Super Mario Bros', Title::clean("  Super \t Mario\n  Bros "));
    }

    public function testNormalizeIsCaseInsensitive(): void
    {
        $this->assertSame(Title::normalize('Zelda'), Title::normalize('  zELDA '));
        $this->assertSame(Title::normalize('ÉCLAIR'), Title::normalize('éclair'));
    }

    public function testNormalizeKeepsDifferentTitlesDistinct(): void
    {
        $this->assertNotSame(Title::normalize('Zelda'), Title::normalize('Zelda 2'));
    }

    /** @return array<string, array{mixed}> */
    public static function invalidTitles(): array
    {
        return [
            'empty' => [''],
            'whitespace only' => ["  \t "],
            'null' => [null],
            'array' => [['Zelda']],
            'too long' => [str_repeat('a', 101)],
        ];
    }

    #[DataProvider('invalidTitles')]
    public function testValidateRejects(mixed $raw): void
    {
        $this->expectException(ApiException::class);
        Title::validate($raw);
    }

    public function testValidateReturnsCleanedTitle(): void
    {
        $this->assertSame('Zelda', Title::validate('  Zelda '));
        $this->assertSame(str_repeat('a', 100), Title::validate(str_repeat('a', 100)));
    }
}
