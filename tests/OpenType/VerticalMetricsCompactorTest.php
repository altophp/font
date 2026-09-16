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
use Alto\Font\Exception\UnsupportedFontException;
use Alto\Font\OpenType\GlyphIdMap;
use Alto\Font\OpenType\VerticalMetricsCompactor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VerticalMetricsCompactor::class)]
final class VerticalMetricsCompactorTest extends TestCase
{
    public function testItRemapsAndRecalculatesVerticalMetrics(): void
    {
        $result = VerticalMetricsCompactor::compact(
            self::vhea(3),
            self::metric(1000, 10) . self::metric(900, 20) . self::metric(1200, 30),
            self::glyph(-50, 600),
            [0, 0, 10],
            GlyphIdMap::fromRetained(3, [2 => true]),
        );
        $vhea = new BinaryReader($result['vhea'], 'compacted vhea');
        $vmtx = new BinaryReader($result['vmtx'], 'compacted vmtx');

        self::assertSame(1200, $vhea->uint16(10));
        self::assertSame(30, $vhea->int16(12));
        self::assertSame(520, $vhea->int16(14));
        self::assertSame(680, $vhea->int16(16));
        self::assertSame(2, $vhea->uint16(34));
        self::assertSame([1000, 10, 1200, 30], [
            $vmtx->uint16(0),
            $vmtx->int16(2),
            $vmtx->uint16(4),
            $vmtx->int16(6),
        ]);
    }

    public function testItUsesTheShortBearingArrayForTrailingEqualAdvances(): void
    {
        $result = VerticalMetricsCompactor::compact(
            self::vhea(1),
            self::metric(1000, 10) . self::i16(20) . self::i16(30),
            '',
            [0, 0, 0],
            GlyphIdMap::fromRetained(3, [2 => true]),
        );
        $vhea = new BinaryReader($result['vhea'], 'compact monospaced vhea');

        self::assertSame(1, $vhea->uint16(34));
        self::assertSame(self::metric(1000, 10) . self::i16(30), $result['vmtx']);
        self::assertSame(0, $vhea->int16(12));
        self::assertSame(0, $vhea->int16(14));
        self::assertSame(0, $vhea->int16(16));
    }

    public function testItPreservesVheaVersionOnePointZero(): void
    {
        $vhea = substr_replace(self::vhea(1), "\0\1\0\0", 0, 4);
        $result = VerticalMetricsCompactor::compact(
            $vhea,
            self::metric(1000, 10),
            '',
            [0, 0],
            GlyphIdMap::fromRetained(1, []),
        );

        self::assertSame("\0\1\0\0", substr($result['vhea'], 0, 4));
    }

    public function testItRejectsUnsupportedVheaVersions(): void
    {
        $this->expectException(UnsupportedFontException::class);
        $this->expectExceptionMessage('vhea versions 1.0 and 1.1 only');

        VerticalMetricsCompactor::compact(
            substr_replace(self::vhea(1), "\0\2\0\0", 0, 4),
            self::metric(1000, 10),
            '',
            [0, 0],
            GlyphIdMap::fromRetained(1, []),
        );
    }

    /**
     * @return iterable<string, array{string, string, string, list<int>, string}>
     */
    public static function invalidTables(): iterable
    {
        yield 'truncated vhea' => [str_repeat("\0", 35), self::metric(1000, 0), '', [0, 0, 0], 'vhea table is truncated'];
        yield 'zero metrics' => [self::vhea(0), self::metric(1000, 0), '', [0, 0, 0], 'numOfLongVerMetrics is invalid'];
        yield 'too many metrics' => [self::vhea(4), self::metric(1000, 0), '', [0, 0, 0], 'numOfLongVerMetrics is invalid'];
        yield 'non-zero metric format' => [substr_replace(self::vhea(1), self::i16(1), 32, 2), self::metric(1000, 0), '', [0, 0, 0], 'metricDataFormat must be zero'];
        yield 'truncated vmtx' => [self::vhea(1), self::i16(1000), '', [0, 0, 0], 'vmtx table length is inconsistent'];
        yield 'trailing vmtx' => [self::vhea(1), self::metric(1000, 0) . "\0", '', [0, 0, 0], 'vmtx table length is inconsistent'];
        yield 'wrong offsets count' => [self::vhea(1), self::metric(1000, 0), '', [0], 'one glyf offset per output glyph'];
        yield 'invalid glyf range' => [self::vhea(1), self::metric(1000, 0) . self::i16(0) . self::i16(0), '', [0, 0, 1], 'invalid glyf offsets'];
    }

    /**
     * @param list<int> $glyphOffsets
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidTables')]
    public function testItRejectsInvalidTables(
        string $vhea,
        string $vmtx,
        string $glyf,
        array $glyphOffsets,
        string $message,
    ): void {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage($message);

        VerticalMetricsCompactor::compact(
            $vhea,
            $vmtx,
            $glyf,
            $glyphOffsets,
            GlyphIdMap::fromRetained(3, [2 => true]),
        );
    }

    private static function vhea(int $metricCount): string
    {
        return "\0\1\x10\0" . str_repeat("\0", 30) . self::u16($metricCount);
    }

    private static function glyph(int $yMin, int $yMax): string
    {
        return self::i16(1) . self::i16(0) . self::i16($yMin) . self::i16(0) . self::i16($yMax);
    }

    private static function metric(int $advanceHeight, int $topSideBearing): string
    {
        return self::u16($advanceHeight) . self::i16($topSideBearing);
    }

    private static function u16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }

    private static function i16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }
}
