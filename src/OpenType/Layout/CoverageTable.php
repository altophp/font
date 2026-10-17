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

namespace Alto\Font\OpenType\Layout;

use Alto\Font\Binary\BinaryReader;
use Alto\Font\Exception\InvalidFontException;
use Alto\Font\Exception\UnsupportedFontException;

/**
 * Parses and builds OpenType coverage tables.
 *
 * @author Simon André <smn.andre@gmail.com>
 *
 * @internal
 */
final readonly class CoverageTable
{
    /**
     * @return list<int>
     */
    public static function parse(BinaryReader $reader, int $baseOffset, int $relativeOffset): array
    {
        if (0 === $relativeOffset) {
            throw new InvalidFontException('OpenType coverage offset must not be NULL.');
        }

        $offset = $baseOffset + $relativeOffset;
        $format = $reader->uint16($offset);

        if (1 === $format) {
            $glyphs = [];
            $previousGlyphId = -1;

            for ($index = 0, $count = $reader->uint16($offset + 2); $index < $count; ++$index) {
                $glyphId = $reader->uint16($offset + 4 + $index * 2);

                if ($glyphId <= $previousGlyphId) {
                    throw new InvalidFontException('OpenType coverage glyph IDs must be strictly increasing.');
                }

                $glyphs[] = $glyphId;
                $previousGlyphId = $glyphId;
            }

            return $glyphs;
        }

        if (2 !== $format) {
            throw new UnsupportedFontException(\sprintf('OpenType coverage format %d is not supported.', $format));
        }

        $glyphs = [];
        $lastEnd = -1;

        for ($rangeIndex = 0, $count = $reader->uint16($offset + 2); $rangeIndex < $count; ++$rangeIndex) {
            $rangeOffset = $offset + 4 + $rangeIndex * 6;
            $start = $reader->uint16($rangeOffset);
            $end = $reader->uint16($rangeOffset + 2);
            $coverageIndex = $reader->uint16($rangeOffset + 4);

            if ($end < $start || $start <= $lastEnd) {
                throw new InvalidFontException('OpenType coverage ranges are invalid or overlap.');
            }

            if ($coverageIndex < \count($glyphs)) {
                throw new InvalidFontException('OpenType coverage indexes overlap.');
            }

            if ($coverageIndex !== \count($glyphs)) {
                throw new InvalidFontException('OpenType coverage indexes are not contiguous in glyph order.');
            }

            for ($glyphId = $start; $glyphId <= $end; ++$glyphId) {
                $glyphs[] = $glyphId;
            }

            $lastEnd = $end;
        }

        return $glyphs;
    }

    /**
     * @param iterable<int> $glyphs
     */
    public static function build(iterable $glyphs): string
    {
        $set = [];

        foreach ($glyphs as $glyphId) {
            if ($glyphId < 0 || $glyphId > 0xFFFF) {
                throw new InvalidFontException(\sprintf('OpenType coverage glyph ID %d is invalid.', $glyphId));
            }

            $set[$glyphId] = true;
        }

        $glyphs = array_keys($set);
        sort($glyphs, \SORT_NUMERIC);
        $ranges = self::ranges($glyphs);

        if (\count($ranges) * 6 < \count($glyphs) * 2) {
            $data = '';
            $coverageIndex = 0;

            foreach ($ranges as [$start, $end]) {
                $data .= self::uint16($start) . self::uint16($end) . self::uint16($coverageIndex);
                $coverageIndex += $end - $start + 1;
            }

            return self::uint16(2) . self::uint16(\count($ranges)) . $data;
        }

        return self::uint16(1)
            . self::uint16(\count($glyphs))
            . implode('', array_map(self::uint16(...), $glyphs));
    }

    /**
     * Groups sorted, unique glyph IDs into inclusive ranges of consecutive IDs.
     *
     * @param list<int> $glyphs
     *
     * @return list<array{int, int}>
     */
    private static function ranges(array $glyphs): array
    {
        $ranges = [];
        $current = null;

        foreach ($glyphs as $glyphId) {
            if (null !== $current && $glyphId === $current[1] + 1) {
                $current[1] = $glyphId;

                continue;
            }

            if (null !== $current) {
                $ranges[] = $current;
            }

            $current = [$glyphId, $glyphId];
        }

        if (null !== $current) {
            $ranges[] = $current;
        }

        return $ranges;
    }

    private static function uint16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }
}
