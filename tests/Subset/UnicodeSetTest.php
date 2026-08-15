<?php

declare(strict_types=1);

/*
 * This file is part of the ALTO library.
 *
 * © 2026-present Simon André
 *
 * For full copyright and license information, please see
 * the LICENSE file distributed with this source code.
 */

namespace Alto\Font\Tests\Subset;

use Alto\Font\Exception\InvalidFontException;
use Alto\Font\Exception\InvalidUnicodeRangeException;
use Alto\Font\Subset\UnicodeRange;
use Alto\Font\Subset\UnicodeSet;
use Alto\Font\Text\UnicodeString;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnicodeSet::class)]
#[CoversClass(UnicodeRange::class)]
#[CoversClass(UnicodeString::class)]
final class UnicodeSetTest extends TestCase
{
    public function testItSortsMergesAndDeduplicatesCodepoints(): void
    {
        $set = UnicodeSet::fromCodepoints([0x43, 0x41, 0x42, 0x41, 0x45]);

        self::assertSame([0x41, 0x42, 0x43, 0x45], iterator_to_array($set));
        self::assertCount(4, $set);
        self::assertSame('U+0041-0043, U+0045', $set->toCss());
    }

    public function testItMergesOverlappingAndAdjacentRanges(): void
    {
        $set = UnicodeSet::fromRanges([
            UnicodeRange::between(0x50, 0x60),
            UnicodeRange::between(0x20, 0x30),
            UnicodeRange::between(0x2A, 0x40),
            UnicodeRange::between(0x41, 0x4F),
        ]);

        self::assertCount(1, $set->ranges);
        self::assertSame(0x20, $set->ranges[0]->start);
        self::assertSame(0x60, $set->ranges[0]->end);
        self::assertCount(65, $set);
    }

    public function testItCreatesASetFromUtf8Text(): void
    {
        $set = UnicodeSet::fromText('Aé€😀A');

        self::assertSame([0x41, 0xE9, 0x20AC, 0x1F600], iterator_to_array($set));
    }

    public function testItParsesCssUnicodeRanges(): void
    {
        $set = UnicodeSet::fromCss('U+0020-007E, u+00A0, U+4??');

        self::assertTrue($set->contains(0x20));
        self::assertTrue($set->contains(0x7E));
        self::assertTrue($set->contains(0xA0));
        self::assertTrue($set->contains(0x400));
        self::assertTrue($set->contains(0x4FF));
        self::assertFalse($set->contains(0x500));
        self::assertSame('U+0020-007E, U+00A0, U+0400-04FF', $set->toCss());
    }

    public function testItCreatesTheUnionOfTwoSets(): void
    {
        $set = UnicodeSet::fromCss('U+0030-0039, U+0041')
            ->union(UnicodeSet::fromCss('U+0041-005A'));

        self::assertSame('U+0030-0039, U+0041-005A', $set->toCss());
        self::assertCount(36, $set);
    }

    public function testItIntersectsTwoSetsWithoutExpandingTheirRanges(): void
    {
        $set = UnicodeSet::fromCss('U+0020-007E, U+0400-04FF')
            ->intersect(UnicodeSet::fromCss('U+0030-0040, U+0042-0060, U+0450-0550'));

        self::assertSame('U+0030-0040, U+0042-0060, U+0450-04FF', $set->toCss());
        self::assertCount(224, $set);
    }

    public function testItSubtractsCharactersAndRanges(): void
    {
        $set = UnicodeSet::fromCss('U+0020-024F')
            ->without(UnicodeSet::fromCss('U+0041, U+0061-007A, U+0100-01FF'));

        self::assertSame('U+0020-0040, U+0042-0060, U+007B-00FF, U+0200-024F', $set->toCss());
        self::assertFalse($set->contains(0x41));
        self::assertFalse($set->contains(0x61));
        self::assertFalse($set->contains(0x17F));
        self::assertTrue($set->contains(0x42));
        self::assertTrue($set->contains(0x200));
    }

    public function testItSubtractsRangesAcrossSeveralInputRanges(): void
    {
        $set = UnicodeSet::fromCss('U+0020-0030, U+0040-0050')
            ->without(UnicodeSet::fromCss('U+0010-0025, U+0028-0045, U+0048-0060'));

        self::assertSame('U+0026-0027, U+0046-0047', $set->toCss());
    }

    public function testSubtractingAnEmptySetReturnsTheSameInstance(): void
    {
        $set = UnicodeSet::fromCss('U+0020-007E');

        self::assertSame($set, $set->without(UnicodeSet::fromCodepoints([])));
    }

    public function testAnEmptyCodepointCollectionCreatesAnEmptySet(): void
    {
        $set = UnicodeSet::fromCodepoints([]);

        self::assertTrue($set->isEmpty());
        self::assertCount(0, $set);
        self::assertSame([], iterator_to_array($set));
        self::assertSame('', $set->toCss());
    }

    public function testItRejectsInvalidUtf8Text(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Text must be valid UTF-8.');

        UnicodeSet::fromText("\xFF");
    }

    public function testItRejectsAnInvalidCodepoint(): void
    {
        $this->expectException(InvalidUnicodeRangeException::class);
        $this->expectExceptionMessage('Unicode codepoint must be between U+0000 and U+10FFFF, got -1.');

        UnicodeSet::fromCodepoints([-1]);
    }

    public function testContainsRejectsAnInvalidCodepoint(): void
    {
        $this->expectException(InvalidUnicodeRangeException::class);
        $this->expectExceptionMessage('Unicode codepoint must be between U+0000 and U+10FFFF, got 1114112.');

        UnicodeSet::fromCodepoints([])->contains(0x110000);
    }

    #[DataProvider('invalidCssRanges')]
    public function testItRejectsInvalidCssRanges(string $value, string $message): void
    {
        $this->expectException(InvalidUnicodeRangeException::class);
        $this->expectExceptionMessage($message);

        UnicodeSet::fromCss($value);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidCssRanges(): iterable
    {
        yield 'empty' => ['', 'CSS unicode-range must not be empty.'];
        yield 'empty item' => ['U+0041,', 'Invalid CSS unicode-range value "".'];
        yield 'missing prefix' => ['0041-005A', 'Invalid CSS unicode-range value "0041-005A".'];
        yield 'non-trailing wildcard' => ['U+4?0', 'Invalid CSS unicode-range value "U+4?0".'];
        yield 'too many digits' => ['U+0000041', 'Invalid CSS unicode-range value "U+0000041".'];
        yield 'above Unicode maximum' => ['U+110000', 'Unicode codepoint must be between U+0000 and U+10FFFF, got 1114112.'];
        yield 'reversed' => ['U+005A-0041', 'Unicode range start U+005A must not be greater than end U+0041.'];
    }
}
