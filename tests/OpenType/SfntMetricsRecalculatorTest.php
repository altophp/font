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
use Alto\Font\OpenType\SfntMetricsRecalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SfntMetricsRecalculator::class)]
final class SfntMetricsRecalculatorTest extends TestCase
{
    public function testItRecalculatesGlobalBoundsAndHorizontalExtrema(): void
    {
        $head = str_repeat("\0", 54);
        $hhea = str_repeat("\0", 34) . self::uint16(2);
        $hmtx = self::uint16(500) . self::int16(10) . self::uint16(700) . self::int16(-20);
        $firstGlyph = self::glyph(-10, -30, 400, 600);
        $secondGlyph = self::glyph(20, -10, 620, 700);
        $glyf = $firstGlyph . $secondGlyph;

        $tables = SfntMetricsRecalculator::recalculate(
            $head,
            $hhea,
            $hmtx,
            $glyf,
            [0, \strlen($firstGlyph), \strlen($glyf)],
            [0 => true, 1 => true],
        );
        $headReader = new BinaryReader($tables['head'], 'recalculated head');
        $hheaReader = new BinaryReader($tables['hhea'], 'recalculated hhea');

        self::assertSame([-10, -30, 620, 700], [
            $headReader->int16(36),
            $headReader->int16(38),
            $headReader->int16(40),
            $headReader->int16(42),
        ]);
        self::assertSame(700, $hheaReader->uint16(10));
        self::assertSame(-20, $hheaReader->int16(12));
        self::assertSame(80, $hheaReader->int16(14));
        self::assertSame(580, $hheaReader->int16(16));
    }

    public function testItIgnoresEmptyGlyphsForBoundsAndHheaExtrema(): void
    {
        $head = str_repeat("\x01", 54);
        $hhea = str_repeat("\0", 34) . self::uint16(1);
        $hmtx = self::uint16(500) . self::int16(20);

        $tables = SfntMetricsRecalculator::recalculate($head, $hhea, $hmtx, '', [0, 0], [0 => true]);
        $headReader = new BinaryReader($tables['head'], 'empty head');
        $hheaReader = new BinaryReader($tables['hhea'], 'empty hhea');

        self::assertSame([0, 0, 0, 0], [
            $headReader->int16(36),
            $headReader->int16(38),
            $headReader->int16(40),
            $headReader->int16(42),
        ]);
        self::assertSame([500, 0, 0, 0], [
            $hheaReader->uint16(10),
            $hheaReader->int16(12),
            $hheaReader->int16(14),
            $hheaReader->int16(16),
        ]);
    }

    private static function glyph(int $xMin, int $yMin, int $xMax, int $yMax): string
    {
        return self::int16(0)
            . self::int16($xMin)
            . self::int16($yMin)
            . self::int16($xMax)
            . self::int16($yMax)
            . self::uint16(0);
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
