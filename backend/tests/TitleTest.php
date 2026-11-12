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
        // Folding only merges look-alikes; the punctuation itself still counts.
        $this->assertNotSame(Title::normalize("Baldur's Gate"), Title::normalize('Baldurs Gate'));
        $this->assertNotSame(Title::normalize('Half-Life'), Title::normalize('Half Life'));
    }

    public function testNormalizeFoldsLookAlikeQuotesAndDashes(): void
    {
        foreach (["\u{2019}", "\u{2018}", "\u{201B}", "\u{02BC}", "\u{2032}", '`', "\u{00B4}"] as $quote) {
            $this->assertSame(Title::normalize("Baldur's Gate"), Title::normalize("Baldur{$quote}s Gate"), $quote);
        }
        foreach (["\u{201C}", "\u{201D}", "\u{201E}", "\u{201F}", "\u{2033}"] as $quote) {
            $this->assertSame(Title::normalize('"Tag"'), Title::normalize("{$quote}Tag{$quote}"), $quote);
        }
        foreach (["\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}", "\u{2014}", "\u{2015}", "\u{2212}"] as $dash) {
            $this->assertSame(Title::normalize('Half-Life'), Title::normalize("Half{$dash}Life"), $dash);
        }
    }

    public function testFoldingDoesNotChangeTheStoredTitle(): void
    {
        $this->assertSame("Baldur\u{2019}s Gate", Title::clean("Baldur\u{2019}s Gate"));
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
