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

    public function testUnicodeVariantsOfTheSameTitleAreDuplicates(): void
    {
        $composed = "Pok\u{00E9}mon";      // é as one code point
        $decomposed = "Poke\u{0301}mon";   // e + combining acute accent
        $this->assertSame(Title::normalize($composed), Title::normalize($decomposed));

        $fullWidth = "\u{FF3A}\u{FF45}\u{FF4C}\u{FF44}\u{FF41}";
        $this->assertSame(Title::normalize('Zelda'), Title::normalize($fullWidth));
        $this->assertSame(Title::normalize('Strasse'), Title::normalize("Stra\u{00DF}e"));
        $this->assertSame(Title::normalize('Zelda'), Title::normalize("Zel\u{200B}da ")); // zero-width space
    }

    public function testCleanStoresTheCanonicalComposedForm(): void
    {
        $this->assertSame("Pok\u{00E9}mon", Title::clean("Poke\u{0301}mon"));
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
