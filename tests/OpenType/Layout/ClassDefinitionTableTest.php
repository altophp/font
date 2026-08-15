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
use Alto\Font\OpenType\Layout\ClassDefinitionTable;
use PHPUnit\Framework\Attributes\CoversClass;
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

    private static function u16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }
}
