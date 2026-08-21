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
use Alto\Font\OpenType\GlyphIdMap;
use Alto\Font\OpenType\Layout\ClassDefinitionTable;
use Alto\Font\OpenType\MergCompactor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MergCompactor::class)]
final class MergCompactorTest extends TestCase
{
    public function testItRemapsMergeClassDefinitions(): void
    {
        $classesOne = ClassDefinitionTable::build([2 => 1, 4 => 2]);
        $classesTwo = ClassDefinitionTable::build([5 => 1, 8 => 2]);
        $offsetArray = 10;
        $classesOneOffset = 14;
        $classesTwoOffset = $classesOneOffset + \strlen($classesOne);
        $mergeDataOffset = $classesTwoOffset + \strlen($classesTwo);
        $mergeData = "\0\1\0\2\0\0\0\0\4";
        $merg = self::u16(0)
            . self::u16(3)
            . self::u16($mergeDataOffset)
            . self::u16(2)
            . self::u16($offsetArray)
            . self::u16($classesOneOffset)
            . self::u16($classesTwoOffset)
            . $classesOne
            . $classesTwo
            . $mergeData;
        $mapping = GlyphIdMap::fromRetained(10, [2 => true, 5 => true, 8 => true]);
        $reader = new BinaryReader(MergCompactor::compact($merg, $mapping), 'compacted MERG');
        $newOffsetArray = $reader->uint16(8);

        self::assertSame([1 => 1], ClassDefinitionTable::parse($reader, 0, $reader->uint16($newOffsetArray)));
        self::assertSame(
            [2 => 1, 3 => 2],
            ClassDefinitionTable::parse($reader, 0, $reader->uint16($newOffsetArray + 2)),
        );
        self::assertSame($mergeData, $reader->string($reader->uint16(4), \strlen($mergeData)));
    }

    private static function u16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }
}
