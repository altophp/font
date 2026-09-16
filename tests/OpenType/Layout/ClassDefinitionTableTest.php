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

namespace Alto\Font\Tests\OpenType\Layout;

use Alto\Font\Binary\BinaryReader;
use Alto\Font\Exception\InvalidFontException;
use Alto\Font\Exception\UnsupportedFontException;
use Alto\Font\OpenType\Layout\ClassDefinitionTable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClassDefinitionTable::class)]
final class ClassDefinitionTableTest extends TestCase
{
    public function testItParsesBothClassDefinitionFormats(): void
    {
        $formatOne = self::u16(1) . self::u16(3) . self::u16(4)
            . self::u16(0) . self::u16(2) . self::u16(2) . self::u16(4);
        $formatTwo = self::u16(2) . self::u16(2)
            . self::u16(2) . self::u16(3) . self::u16(1)
            . self::u16(7) . self::u16(7) . self::u16(3);

        self::assertSame([4 => 2, 5 => 2, 6 => 4], ClassDefinitionTable::parse(new BinaryReader("\0\0" . $formatOne, 'class one'), 0, 2));
        self::assertSame([2 => 1, 3 => 1, 7 => 3], ClassDefinitionTable::parse(new BinaryReader("\0\0" . $formatTwo, 'class two'), 0, 2));
    }

    public function testItBuildsMergedFormatTwoRanges(): void
    {
        $classes = ClassDefinitionTable::build([7 => 3, 2 => 1, 3 => 1, 5 => 0]);

        self::assertSame(
            [2 => 1, 3 => 1, 7 => 3],
            ClassDefinitionTable::parse(new BinaryReader("\0\0" . $classes, 'built classes'), 0, 2),
        );
    }

    public function testItAcceptsFormatOneAtTheGlyphIdBoundary(): void
    {
        $classes = self::u16(1) . self::u16(0xFFFE) . self::u16(2)
            . self::u16(0) . self::u16(3);

        self::assertSame(
            [0xFFFF => 3],
            ClassDefinitionTable::parse(new BinaryReader("\0\0" . $classes, 'boundary classes'), 0, 2),
        );
    }

    #[DataProvider('overflowingClasses')]
    public function testItRejectsFormatOneBeyondTheGlyphIdBoundary(int $lastClass): void
    {
        $classes = self::u16(1) . self::u16(0xFFFF) . self::u16(2)
            . self::u16(1) . self::u16($lastClass);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('exceeds the glyph ID range');

        ClassDefinitionTable::parse(new BinaryReader("\0\0" . $classes, 'overflowing classes'), 0, 2);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function overflowingClasses(): iterable
    {
        yield 'nonzero class' => [2];
        yield 'class zero' => [0];
    }

    public function testItRejectsNullOffsets(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('offset must not be NULL');

        ClassDefinitionTable::parse(new BinaryReader('', 'null class definition'), 0, 0);
    }

    public function testItRejectsUnsupportedFormats(): void
    {
        $this->expectException(UnsupportedFontException::class);
        $this->expectExceptionMessage('format 3 is not supported');

        ClassDefinitionTable::parse(new BinaryReader("\0\0" . self::u16(3), 'unsupported class definition'), 0, 2);
    }

    public function testItRejectsOverlappingFormatTwoRanges(): void
    {
        $classes = self::u16(2)
            . self::u16(2)
            . self::u16(2) . self::u16(4) . self::u16(1)
            . self::u16(4) . self::u16(5) . self::u16(2);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('ranges are invalid or overlap');

        ClassDefinitionTable::parse(new BinaryReader("\0\0" . $classes, 'overlapping classes'), 0, 2);
    }

    public function testItRejectsInvalidValuesWhenBuilding(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('invalid glyph or class ID');

        ClassDefinitionTable::build([0x10000 => 1]);
    }

    private static function u16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }
}
