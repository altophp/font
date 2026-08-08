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

namespace Alto\Font\Tests\OpenType\Table;

use Alto\Font\Binary\BinaryReader;
use Alto\Font\Exception\InvalidFontException;
use Alto\Font\OpenType\Table\CmapTable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CmapTable::class)]
final class CmapTableTest extends TestCase
{
    public function testItMapsCodepointsFromFormat4Subtables(): void
    {
        $cmap = CmapTable::parse(new BinaryReader(
            self::u16(0) . self::u16(1)
            . self::u16(3) . self::u16(1) . self::u32(12)
            . self::u16(4) . self::u16(32) . self::u16(0) . self::u16(4)
            . self::u16(0) . self::u16(0) . self::u16(0)
            . self::u16(65) . self::u16(0xFFFF) . self::u16(0)
            . self::u16(65) . self::u16(0xFFFF)
            . self::u16(0xFFC0) . self::u16(1)
            . self::u16(0) . self::u16(0),
            'cmap',
        ));

        self::assertSame(1, $cmap->glyphIdForCodepoint(65));
        self::assertNull($cmap->glyphIdForCodepoint(66));
    }

    public function testItMapsFormat4SubtablesThroughGlyphIdArrays(): void
    {
        $cmap = CmapTable::parse(new BinaryReader(
            self::u16(0) . self::u16(1)
            . self::u16(3) . self::u16(1) . self::u32(12)
            . self::u16(4) . self::u16(36) . self::u16(0) . self::u16(4)
            . self::u16(0) . self::u16(0) . self::u16(0)
            . self::u16(66) . self::u16(0xFFFF) . self::u16(0)
            . self::u16(65) . self::u16(0xFFFF)
            . self::u16(1) . self::u16(1)
            . self::u16(4) . self::u16(0)
            . self::u16(4) . self::u16(0),
            'cmap',
        ));

        self::assertSame(5, $cmap->glyphIdForCodepoint(65));
        self::assertSame(0, $cmap->glyphIdForCodepoint(66));
    }

    public function testItIgnoresFormat4GlyphArrayOffsetsOutsideTheSubtable(): void
    {
        $cmap = CmapTable::parse(new BinaryReader(
            self::u16(0) . self::u16(1)
            . self::u16(3) . self::u16(1) . self::u32(12)
            . self::u16(4) . self::u16(32) . self::u16(0) . self::u16(4)
            . self::u16(0) . self::u16(0) . self::u16(0)
            . self::u16(65) . self::u16(0xFFFF) . self::u16(0)
            . self::u16(65) . self::u16(0xFFFF)
            . self::u16(1) . self::u16(1)
            . self::u16(4) . self::u16(0),
            'cmap',
        ));

        self::assertNull($cmap->glyphIdForCodepoint(65));
    }

    public function testItMapsCodepointsFromFormat12Subtables(): void
    {
        $cmap = CmapTable::parse(new BinaryReader(
            self::u16(0) . self::u16(1)
            . self::u16(3) . self::u16(10) . self::u32(12)
            . self::u16(12) . self::u16(0) . self::u32(28) . self::u32(0) . self::u32(1)
            . self::u32(0x1F600) . self::u32(0x1F601) . self::u32(7),
            'cmap',
        ));

        self::assertSame(7, $cmap->glyphIdForCodepoint(0x1F600));
        self::assertSame(8, $cmap->glyphIdForCodepoint(0x1F601));
        self::assertNull($cmap->glyphIdForCodepoint(0x1F602));
    }

    public function testItRejectsUnsupportedVersions(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Unsupported cmap table version.');

        CmapTable::parse(new BinaryReader(self::u16(1) . self::u16(0), 'cmap'));
    }

    public function testItRejectsCmapsWithoutSupportedSubtables(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('No supported cmap format 4 or 12 subtable found.');

        CmapTable::parse(new BinaryReader(
            self::u16(0) . self::u16(1)
            . self::u16(3) . self::u16(1) . self::u32(12)
            . self::u16(0) . str_repeat("\0", 10),
            'cmap',
        ));
    }

    public function testItRejectsUnsupportedFormat12ReservedValues(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Unsupported cmap format 12 reserved value.');

        CmapTable::parse(new BinaryReader(
            self::u16(0) . self::u16(1)
            . self::u16(3) . self::u16(10) . self::u32(12)
            . self::u16(12) . self::u16(1) . self::u32(16) . self::u32(0) . self::u32(0),
            'cmap',
        ));
    }

    private static function u16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }

    private static function u32(int $value): string
    {
        return pack('N', $value);
    }
}
