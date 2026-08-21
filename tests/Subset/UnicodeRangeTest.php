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

use Alto\Font\Exception\InvalidUnicodeRangeException;
use Alto\Font\Subset\UnicodeRange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnicodeRange::class)]
final class UnicodeRangeTest extends TestCase
{
    public function testItRepresentsAnInclusiveRange(): void
    {
        $range = UnicodeRange::between(0x41, 0x5A);

        self::assertSame(0x41, $range->start);
        self::assertSame(0x5A, $range->end);
        self::assertSame(26, $range->count());
        self::assertTrue($range->contains(0x41));
        self::assertTrue($range->contains(0x5A));
        self::assertFalse($range->contains(0x60));
        self::assertSame('U+0041-005A', $range->toCss());
    }

    public function testItRepresentsASingleCodepoint(): void
    {
        $range = UnicodeRange::single(0x1F600);

        self::assertSame(1, $range->count());
        self::assertSame('U+1F600', $range->toCss());
    }

    #[DataProvider('invalidRanges')]
    public function testItRejectsInvalidRanges(int $start, int $end, string $message): void
    {
        $this->expectException(InvalidUnicodeRangeException::class);
        $this->expectExceptionMessage($message);

        new UnicodeRange($start, $end);
    }

    /**
     * @return iterable<string, array{int, int, string}>
     */
    public static function invalidRanges(): iterable
    {
        yield 'negative start' => [-1, 0x41, 'Unicode codepoint must be between U+0000 and U+10FFFF, got -1.'];
        yield 'end above Unicode maximum' => [0x41, 0x110000, 'Unicode codepoint must be between U+0000 and U+10FFFF, got 1114112.'];
        yield 'reversed' => [0x5A, 0x41, 'Unicode range start U+005A must not be greater than end U+0041.'];
    }
}
