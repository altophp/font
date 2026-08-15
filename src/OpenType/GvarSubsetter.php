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

/**
 * @internal
 */
final readonly class GvarSubsetter
{
    private const int LONG_OFFSETS = 0x0001;

    /**
     * Retains glyph IDs and removes variation blocks for discarded glyphs.
     *
     * @param array<int, true> $retainedGlyphs
     */
    public static function subset(string $gvar, int $glyphCount, array $retainedGlyphs): string
    {
        foreach ($retainedGlyphs as $glyphId => $_retained) {
            if ($glyphId < 0 || $glyphId >= $glyphCount) {
                throw new InvalidFontException(\sprintf('Cannot retain invalid gvar glyph ID %d.', $glyphId));
            }
        }

        return self::rewrite($gvar, $glyphCount, self::stableGlyphIds($glyphCount, $retainedGlyphs));
    }

    public static function compact(string $gvar, int $glyphCount, GlyphIdMap $glyphIds): string
    {
        $sourceGlyphIds = [];

        foreach ($glyphIds->pairs() as $oldGlyphId => $_newGlyphId) {
            $sourceGlyphIds[] = $oldGlyphId;
        }

        return self::rewrite($gvar, $glyphCount, $sourceGlyphIds);
    }

    /**
     * @param list<?int> $sourceGlyphIds
     */
    private static function rewrite(string $gvar, int $glyphCount, array $sourceGlyphIds): string
    {
        $reader = new BinaryReader($gvar, 'gvar subsetting source');

        if (1 !== $reader->uint16(0) || 0 !== $reader->uint16(2)) {
            throw new InvalidFontException('Only gvar version 1.0 can be subset.');
        }

        $axisCount = $reader->uint16(4);
        $sharedTupleCount = $reader->uint16(6);
        $sharedTuplesOffset = $reader->uint32(8);
        $tableGlyphCount = $reader->uint16(12);
        $flags = $reader->uint16(14);
        $glyphDataOffset = $reader->uint32(16);

        if ($tableGlyphCount !== $glyphCount) {
            throw new InvalidFontException(\sprintf('gvar glyph count %d does not match maxp glyph count %d.', $tableGlyphCount, $glyphCount));
        }

        if (0 !== ($flags & ~self::LONG_OFFSETS)) {
            throw new InvalidFontException('gvar flags contain reserved bits.');
        }

        foreach ($sourceGlyphIds as $glyphId) {
            if (null !== $glyphId && ($glyphId < 0 || $glyphId >= $glyphCount)) {
                throw new InvalidFontException(\sprintf('Cannot retain invalid gvar glyph ID %d.', $glyphId));
            }
        }

        $offsetEntrySize = 0 !== ($flags & self::LONG_OFFSETS) ? 4 : 2;
        $offsetArrayEnd = 20 + ($glyphCount + 1) * $offsetEntrySize;
        $sharedTupleLength = $sharedTupleCount * $axisCount * 2;
        $sharedTuplesEnd = $sharedTuplesOffset + $sharedTupleLength;

        if ($glyphDataOffset < $offsetArrayEnd || $glyphDataOffset > $reader->length()) {
            throw new InvalidFontException('gvar glyph variation data overlaps the header or offset array.');
        }

        if (0 !== $sharedTupleCount && ($sharedTuplesOffset < $offsetArrayEnd || $sharedTuplesEnd > $glyphDataOffset)) {
            throw new InvalidFontException('gvar shared tuples overlap the header, offsets, or glyph variation data.');
        }

        if ($sharedTuplesEnd < $sharedTuplesOffset || $sharedTuplesEnd > $reader->length()) {
            throw new InvalidFontException('gvar shared tuples exceed the table bounds.');
        }

        $sharedTuples = $reader->string($sharedTuplesOffset, $sharedTupleLength);
        $offsetCursor = 20;
        $sourceOffsets = [];

        for ($index = 0; $index <= $glyphCount; ++$index) {
            $sourceOffsets[] = 0 !== ($flags & self::LONG_OFFSETS)
                ? $reader->uint32($offsetCursor)
                : $reader->uint16($offsetCursor) * 2;
            $offsetCursor += $offsetEntrySize;
        }

        $sourceDataLength = $reader->length() - $glyphDataOffset;
        $previousOffset = 0;

        foreach ($sourceOffsets as $offset) {
            if ($offset < $previousOffset || $offset > $sourceDataLength) {
                throw new InvalidFontException('gvar glyph variation offsets are invalid.');
            }

            $previousOffset = $offset;
        }

        $glyphData = '';
        $offsets = [];

        foreach ($sourceGlyphIds as $glyphId) {
            $offsets[] = \strlen($glyphData);

            if (null === $glyphId) {
                continue;
            }

            $start = $sourceOffsets[$glyphId];
            $length = $sourceOffsets[$glyphId + 1] - $start;
            $block = $reader->string($glyphDataOffset + $start, $length);
            $glyphData .= $block . (0 === \strlen($block) % 2 ? '' : "\0");
        }

        $offsets[] = \strlen($glyphData);
        $longOffsets = \strlen($glyphData) > 0x1FFFE;
        $offsetData = '';

        foreach ($offsets as $offset) {
            $offsetData .= $longOffsets ? self::uint32($offset) : self::uint16(intdiv($offset, 2));
        }

        $newSharedTuplesOffset = 20 + \strlen($offsetData);
        $newGlyphDataOffset = $newSharedTuplesOffset + \strlen($sharedTuples);

        return self::uint16(1)
            . self::uint16(0)
            . self::uint16($axisCount)
            . self::uint16($sharedTupleCount)
            . self::uint32($newSharedTuplesOffset)
            . self::uint16(\count($sourceGlyphIds))
            . self::uint16($longOffsets ? self::LONG_OFFSETS : 0)
            . self::uint32($newGlyphDataOffset)
            . $offsetData
            . $sharedTuples
            . $glyphData;
    }

    /**
     * @param array<int, true> $retainedGlyphs
     *
     * @return list<?int>
     */
    private static function stableGlyphIds(int $glyphCount, array $retainedGlyphs): array
    {
        $glyphIds = [];

        for ($glyphId = 0; $glyphId < $glyphCount; ++$glyphId) {
            $glyphIds[] = isset($retainedGlyphs[$glyphId]) ? $glyphId : null;
        }

        return $glyphIds;
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
