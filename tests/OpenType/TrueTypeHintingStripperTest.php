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

namespace Alto\Font\Tests\OpenType;

use Alto\Font\Binary\BinaryReader;
use Alto\Font\Exception\InvalidFontException;
use Alto\Font\OpenType\TrueTypeHintingStripper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TrueTypeHintingStripper::class)]
final class TrueTypeHintingStripperTest extends TestCase
{
    public function testItRemovesSimpleGlyphInstructions(): void
    {
        $glyph = self::int16(1)
            . str_repeat("\0", 8)
            . self::uint16(0)
            . self::uint16(2)
            . "\xB0\x00"
            . "\x01"
            . self::int16(10)
            . self::int16(20);

        $stripped = TrueTypeHintingStripper::stripGlyph($glyph);

        self::assertSame(0, (new BinaryReader($stripped, 'stripped simple glyph'))->uint16(12));
        self::assertSame(\strlen($glyph) - 2, \strlen($stripped));
        self::assertSame("\x01" . self::int16(10) . self::int16(20), substr($stripped, 14));
    }

    public function testItRemovesCompoundGlyphInstructionsAndClearsTheFlag(): void
    {
        $glyph = self::int16(-1)
            . str_repeat("\0", 8)
            . self::uint16(0x0101)
            . self::uint16(1)
            . self::int16(0)
            . self::int16(0)
            . self::uint16(2)
            . "\xB0\x00";

        $stripped = TrueTypeHintingStripper::stripGlyph($glyph);
        $reader = new BinaryReader($stripped, 'stripped compound glyph');

        self::assertSame(0x0001, $reader->uint16(10));
        self::assertSame(18, \strlen($stripped));
    }

    public function testItClearsHintingMaxima(): void
    {
        $maxp = "\x00\x01\x00\x00" . self::uint16(5) . str_repeat("\x01", 26);
        $stripped = TrueTypeHintingStripper::stripMaxp($maxp);
        $reader = new BinaryReader($stripped, 'stripped maxp');

        self::assertSame(1, $reader->uint16(14));

        foreach ([16, 18, 20, 22, 24, 26] as $offset) {
            self::assertSame(0, $reader->uint16($offset));
        }

        self::assertSame(5, $reader->uint16(4));
    }

    public function testItRejectsTruncatedInstructions(): void
    {
        $glyph = self::int16(0) . str_repeat("\0", 8) . self::uint16(4) . "\xB0";

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('instructions exceed the glyph bounds');

        TrueTypeHintingStripper::stripGlyph($glyph);
    }

    private static function uint16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }

    private static function int16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }
}
