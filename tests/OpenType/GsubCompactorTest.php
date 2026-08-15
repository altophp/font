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
use Alto\Font\OpenType\GsubCompactor;
use Alto\Font\OpenType\Table\GsubTable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GsubCompactor::class)]
final class GsubCompactorTest extends TestCase
{
    public function testItRemapsMultipleSubstitutionSequences(): void
    {
        $sequenceOne = self::u16(2) . self::u16(4) . self::u16(5);
        $sequenceTwo = self::u16(1) . self::u16(3);
        $coverage = self::u16(1) . self::u16(2) . self::u16(1) . self::u16(2);
        $subtable = self::u16(1)
            . self::u16(10 + \strlen($sequenceOne) + \strlen($sequenceTwo))
            . self::u16(2)
            . self::u16(10)
            . self::u16(10 + \strlen($sequenceOne))
            . $sequenceOne
            . $sequenceTwo
            . $coverage;
        $mapping = GlyphIdMap::fromRetained(6, [1 => true, 4 => true, 5 => true]);
        $compacted = GsubCompactor::compact(self::gsub(2, $subtable), $mapping);
        $reader = new BinaryReader($compacted, 'test compacted multiple GSUB');
        $lookupList = $reader->uint16(8);
        $lookup = $lookupList + $reader->uint16($lookupList + 2);
        $multiple = $lookup + $reader->uint16($lookup + 6);
        $sequence = $multiple + $reader->uint16($multiple + 6);

        self::assertSame(1, $reader->uint16($multiple));
        self::assertSame(1, $reader->uint16($multiple + 4));
        self::assertSame(2, $reader->uint16($sequence));
        self::assertSame([2, 3], [$reader->uint16($sequence + 2), $reader->uint16($sequence + 4)]);
    }

    public function testItRemapsChainedContextFormatThreeLookups(): void
    {
        $mapping = GlyphIdMap::fromRetained(6, [1 => true, 4 => true]);
        $compacted = GsubCompactor::compact(self::chainedGsub(), $mapping);
        $closure = GsubTable::parse(new BinaryReader($compacted, 'test compacted GSUB'))
            ->glyphClosure([1 => true], 3);

        self::assertSame([1, 2], array_keys($closure));
    }

    public function testItRemapsChainedContextFormatOneRules(): void
    {
        $rule = self::u16(1) . self::u16(2)
            . self::u16(2) . self::u16(4)
            . self::u16(1) . self::u16(5)
            . self::u16(1) . self::u16(1) . self::u16(0);
        $set = self::u16(1) . self::u16(4) . $rule;
        $coverage = self::coverage(1);
        $subtable = self::u16(1)
            . self::u16(8 + \strlen($set))
            . self::u16(1)
            . self::u16(8)
            . $set
            . $coverage;
        $mapping = GlyphIdMap::fromRetained(6, [1 => true, 2 => true, 4 => true, 5 => true]);
        $compacted = GsubCompactor::compact(self::gsub(6, $subtable), $mapping);
        $reader = new BinaryReader($compacted, 'test compacted chained GSUB');
        $lookupList = $reader->uint16(8);
        $lookup = $lookupList + $reader->uint16($lookupList + 2);
        $chain = $lookup + $reader->uint16($lookup + 6);
        $ruleSet = $chain + $reader->uint16($chain + 6);
        $newRule = $ruleSet + $reader->uint16($ruleSet + 2);

        self::assertSame(2, $reader->uint16($newRule + 2));
        self::assertSame(3, $reader->uint16($newRule + 6));
        self::assertSame(4, $reader->uint16($newRule + 10));
    }

    private static function chainedGsub(): string
    {
        $single = self::u16(2)
            . self::u16(8)
            . self::u16(1)
            . self::u16(4)
            . self::coverage(1);
        $chain = self::u16(3)
            . self::u16(0)
            . self::u16(1)
            . self::u16(16)
            . self::u16(0)
            . self::u16(1)
            . self::u16(0)
            . self::u16(0)
            . self::coverage(1);
        $lookupList = self::offsetList([
            self::lookup(1, $single),
            self::lookup(6, $chain),
        ]);
        $scriptList = self::u16(0);
        $featureList = self::u16(1)
            . 'test'
            . self::u16(8)
            . self::u16(0)
            . self::u16(1)
            . self::u16(1);
        $scriptOffset = 10;
        $featureOffset = $scriptOffset + \strlen($scriptList);
        $lookupOffset = $featureOffset + \strlen($featureList);

        return self::u16(1)
            . self::u16(0)
            . self::u16($scriptOffset)
            . self::u16($featureOffset)
            . self::u16($lookupOffset)
            . $scriptList
            . $featureList
            . $lookupList;
    }

    private static function gsub(int $lookupType, string $subtable): string
    {
        $lookupList = self::offsetList([self::lookup($lookupType, $subtable)]);
        $scriptList = self::u16(0);
        $featureList = self::u16(0);

        return self::u16(1)
            . self::u16(0)
            . self::u16(10)
            . self::u16(10 + \strlen($scriptList))
            . self::u16(10 + \strlen($scriptList) + \strlen($featureList))
            . $scriptList
            . $featureList
            . $lookupList;
    }

    private static function lookup(int $type, string $subtable): string
    {
        return self::u16($type)
            . self::u16(0)
            . self::u16(1)
            . self::u16(8)
            . $subtable;
    }

    private static function coverage(int $glyphId): string
    {
        return self::u16(1) . self::u16(1) . self::u16($glyphId);
    }

    /**
     * @param list<string> $items
     */
    private static function offsetList(array $items): string
    {
        $header = self::u16(\count($items));
        $data = '';
        $offset = 2 + \count($items) * 2;

        foreach ($items as $item) {
            $header .= self::u16($offset);
            $data .= $item;
            $offset += \strlen($item);
        }

        return $header . $data;
    }

    private static function u16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }
}
