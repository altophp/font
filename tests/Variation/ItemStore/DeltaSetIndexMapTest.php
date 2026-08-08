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

namespace Alto\Font\Tests\Variation\ItemStore;

use Alto\Font\Binary\BinaryReader;
use Alto\Font\Exception\InvalidFontException;
use Alto\Font\Variation\ItemStore\DeltaSetIndexMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeltaSetIndexMap::class)]
final class DeltaSetIndexMapTest extends TestCase
{
    public function testItParsesFormatZeroMaps(): void
    {
        $map = DeltaSetIndexMap::parse(new BinaryReader(self::u8(0) . self::u8(0) . self::u16(3) . "\x00\x01\x02", 'map'));

        self::assertSame([0, 0], $map->deltaSetIndex(0));
        self::assertSame([0, 1], $map->deltaSetIndex(1));
        self::assertSame([1, 0], $map->deltaSetIndex(2));
        self::assertSame([1, 0], $map->deltaSetIndex(99));
    }

    public function testItParsesFormatOneMapsWithLargeEntries(): void
    {
        $entry = (5 << 16) | 9;
        $map = DeltaSetIndexMap::parse(new BinaryReader(
            self::u8(1) . self::u8(0x3F) . self::u32(1) . self::u32($entry),
            'map',
        ));

        self::assertSame([5, 9], $map->deltaSetIndex(0));
    }

    public function testItRejectsUnsupportedFormats(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Unsupported DeltaSetIndexMap format 2.');

        DeltaSetIndexMap::parse(new BinaryReader(self::u8(2) . self::u8(0), 'map'));
    }

    public function testItRejectsEmptyMaps(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('DeltaSetIndexMap must contain at least one entry.');

        DeltaSetIndexMap::parse(new BinaryReader(self::u8(0) . self::u8(0) . self::u16(0), 'map'));
    }

    public function testItRejectsReservedEntryFormatBits(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('DeltaSetIndexMap entry format uses reserved bits.');

        DeltaSetIndexMap::parse(new BinaryReader(self::u8(0) . self::u8(0x80) . self::u16(1) . "\0", 'map'));
    }

    private static function u8(int $value): string
    {
        return pack('C', $value & 0xFF);
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
