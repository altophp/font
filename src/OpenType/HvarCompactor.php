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

/**
 * Compacts horizontal variation mappings.
 *
 * @author Simon André <smn.andre@gmail.com>
 *
 * @internal
 */
final readonly class HvarCompactor
{
    private const int INNER_INDEX_BIT_COUNT_MASK = 0x0F;
    private const int MAP_ENTRY_SIZE_MASK = 0x30;

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

        $mappingOffsets = array_values(array_filter(
            [$advanceOffset, $leftOffset, $rightOffset],
            static fn(int $offset): bool => 0 !== $offset,
        ));
        sort($mappingOffsets, \SORT_NUMERIC);
        $storeEnd = $mappingOffsets[0] ?? $reader->length();

        if ($storeEnd <= $storeOffset) {
            throw new UnsupportedFontException('Compact HVAR output requires mappings after the ItemVariationStore.');
        }

        $store = $reader->string($storeOffset, $storeEnd - $storeOffset);
        $advance = self::remap($reader, $advanceOffset, $glyphIds, implicit: true);
        $left = 0 === $leftOffset ? null : self::remap($reader, $leftOffset, $glyphIds, implicit: false);
        $right = 0 === $rightOffset ? null : self::remap($reader, $rightOffset, $glyphIds, implicit: false);
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

    private static function remap(
        BinaryReader $reader,
        int $offset,
        GlyphIdMap $glyphIds,
        bool $implicit,
    ): string {
        $entries = 0 === $offset ? null : self::parseMap($reader, $offset);
        $remapped = [];

        foreach ($glyphIds->pairs() as $oldGlyphId => $_newGlyphId) {
            if (null === $entries) {
                if (!$implicit) {
                    throw new InvalidFontException('HVAR mapping unexpectedly uses implicit indexes.');
                }

                $remapped[] = [0, $oldGlyphId];
                continue;
            }

            $remapped[] = $entries[min($oldGlyphId, \count($entries) - 1)];
        }

        return self::buildMap($remapped);
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    private static function parseMap(BinaryReader $reader, int $offset): array
    {
        if ($offset < 20 || $offset >= $reader->length()) {
            throw new InvalidFontException('HVAR delta-set mapping offset is invalid.');
        }

        $format = $reader->uint8($offset);
        $entryFormat = $reader->uint8($offset + 1);

        if (!\in_array($format, [0, 1], true) || 0 !== ($entryFormat & 0xC0)) {
            throw new InvalidFontException('HVAR delta-set mapping format is invalid.');
        }

        $count = 0 === $format ? $reader->uint16($offset + 2) : $reader->uint32($offset + 2);

        if (0 === $count) {
            throw new InvalidFontException('HVAR delta-set mapping must contain at least one entry.');
        }

        $innerBitCount = ($entryFormat & self::INNER_INDEX_BIT_COUNT_MASK) + 1;
        $entrySize = (($entryFormat & self::MAP_ENTRY_SIZE_MASK) >> 4) + 1;
        $innerMask = (1 << $innerBitCount) - 1;
        $cursor = $offset + (0 === $format ? 4 : 6);
        $entries = [];

        for ($index = 0; $index < $count; ++$index) {
            $entry = 0;

            for ($byte = 0; $byte < $entrySize; ++$byte) {
                $entry = ($entry << 8) | $reader->uint8($cursor++);
            }

            $entries[] = [$entry >> $innerBitCount, $entry & $innerMask];
        }

        return $entries;
    }

    /**
     * @param list<array{0: int, 1: int}> $entries
     */
    private static function buildMap(array $entries): string
    {
        if ([] === $entries) {
            throw new InvalidFontException('HVAR compact mapping must contain at least one entry.');
        }

        $maximumInner = max(array_column($entries, 1));
        $innerBitCount = max(1, self::bitCount($maximumInner));
        $maximumEntry = 0;

        foreach ($entries as [$outerIndex, $innerIndex]) {
            if ($outerIndex < 0 || $innerIndex < 0 || $innerBitCount > 16) {
                throw new UnsupportedFontException('HVAR delta-set indexes exceed the compact mapping format.');
            }

            $maximumEntry = max($maximumEntry, ($outerIndex << $innerBitCount) | $innerIndex);
        }

        $entrySize = max(1, intdiv(self::bitCount($maximumEntry) + 7, 8));

        if ($entrySize > 4) {
            throw new UnsupportedFontException('HVAR delta-set indexes exceed four bytes.');
        }

        $entryFormat = (($entrySize - 1) << 4) | ($innerBitCount - 1);
        $data = '';

        foreach ($entries as [$outerIndex, $innerIndex]) {
            $entry = ($outerIndex << $innerBitCount) | $innerIndex;

            for ($byte = $entrySize - 1; $byte >= 0; --$byte) {
                $data .= pack('C', ($entry >> ($byte * 8)) & 0xFF);
            }
        }

        return "\0" . pack('C', $entryFormat) . self::uint16(\count($entries)) . $data;
    }

    private static function bitCount(int $value): int
    {
        $count = 0;

        do {
            ++$count;
            $value >>= 1;
        } while ($value > 0);

        return $count;
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
