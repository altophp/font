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

namespace Alto\Font\OpenType;

use Alto\Font\Binary\BinaryReader;
use Alto\Font\Exception\InvalidFontException;
use Alto\Font\Exception\UnsupportedFontException;
use Alto\Font\OpenType\Layout\DeltaSetIndexMapTable;
use Alto\Font\OpenType\Layout\ItemVariationStoreTable;

/**
 * Compacts horizontal variation mappings.
 *
 * @author Simon André <smn.andre@gmail.com>
 *
 * @internal
 */
final readonly class HvarCompactor
{
    public static function compact(string $hvar, GlyphIdMap $glyphIds): string
    {
        $reader = new BinaryReader($hvar, 'HVAR compaction source');

        if (1 !== $reader->uint16(0) || 0 !== $reader->uint16(2)) {
            throw new UnsupportedFontException('Compact HVAR output supports version 1.0 only.');
        }

        $storeOffset = $reader->uint32(4);
        $advanceOffset = $reader->uint32(8);
        $leftOffset = $reader->uint32(12);
        $rightOffset = $reader->uint32(16);

        if ($storeOffset < 20 || $storeOffset >= $reader->length()) {
            throw new InvalidFontException('HVAR item variation store offset is invalid.');
        }

        if ((0 === $leftOffset) !== (0 === $rightOffset)) {
            throw new InvalidFontException('HVAR side-bearing mappings must both be present or both be NULL.');
        }

        $maps = [];

        foreach ([$advanceOffset, $leftOffset, $rightOffset] as $mappingOffset) {
            if (0 !== $mappingOffset && !isset($maps[$mappingOffset])) {
                $maps[$mappingOffset] = self::parseMap($reader, $mappingOffset);
            }
        }

        ksort($maps, \SORT_NUMERIC);
        $previousEnd = 0;

        foreach ($maps as $mappingOffset => $map) {
            if ($mappingOffset < $previousEnd) {
                throw new InvalidFontException('HVAR delta-set mappings overlap.');
            }

            $previousEnd = $map['end'];
        }

        $parsedStore = ItemVariationStoreTable::parse($reader, $storeOffset);

        foreach ($parsedStore['ranges'] as [$start, $end]) {
            foreach ($maps as $mappingOffset => $map) {
                if ($start < $map['end'] && $end > $mappingOffset) {
                    throw new InvalidFontException('HVAR ItemVariationStore overlaps a delta-set mapping.');
                }
            }
        }

        $store = $parsedStore['data'];
        $advance = DeltaSetIndexMapTable::remap($maps[$advanceOffset]['entries'] ?? null, $glyphIds);
        $left = 0 === $leftOffset ? null : DeltaSetIndexMapTable::remap($maps[$leftOffset]['entries'], $glyphIds);
        $right = 0 === $rightOffset ? null : DeltaSetIndexMapTable::remap($maps[$rightOffset]['entries'], $glyphIds);
        $cursor = 20 + \strlen($store);
        $advanceOutputOffset = $cursor;
        $cursor += \strlen($advance);
        $leftOutputOffset = null === $left ? 0 : $cursor;
        $cursor += null === $left ? 0 : \strlen($left);
        $rightOutputOffset = null === $right ? 0 : $cursor;

        return self::uint16(1)
            . self::uint16(0)
            . self::uint32(20)
            . self::uint32($advanceOutputOffset)
            . self::uint32($leftOutputOffset)
            . self::uint32($rightOutputOffset)
            . $store
            . $advance
            . ($left ?? '')
            . ($right ?? '');
    }

    /**
     * @return array{entries: list<array{0: int, 1: int}>, end: int}
     */
    private static function parseMap(BinaryReader $reader, int $offset): array
    {
        if ($offset < 20 || $offset >= $reader->length()) {
            throw new InvalidFontException('HVAR delta-set mapping offset is invalid.');
        }

        return DeltaSetIndexMapTable::parse($reader, $offset);
    }

    private static function uint16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }

    private static function uint32(int $value): string
    {
        return pack('N', $value & 0xFFFFFFFF);
    }
}
