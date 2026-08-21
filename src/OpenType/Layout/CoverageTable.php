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

        $glyphsByIndex = [];
        $lastEnd = -1;

        for ($rangeIndex = 0, $count = $reader->uint16($offset + 2); $rangeIndex < $count; ++$rangeIndex) {
            $rangeOffset = $offset + 4 + $rangeIndex * 6;
            $start = $reader->uint16($rangeOffset);
            $end = $reader->uint16($rangeOffset + 2);
            $coverageIndex = $reader->uint16($rangeOffset + 4);

            if ($end < $start || $start <= $lastEnd) {
                throw new InvalidFontException('OpenType coverage ranges are invalid or overlap.');
            }

            for ($glyphId = $start; $glyphId <= $end; ++$glyphId) {
                if (isset($glyphsByIndex[$coverageIndex])) {
                    throw new InvalidFontException('OpenType coverage indexes overlap.');
                }

                $glyphsByIndex[$coverageIndex++] = $glyphId;
            }

            $lastEnd = $end;
        }

        ksort($glyphsByIndex, \SORT_NUMERIC);

        if ([] !== $glyphsByIndex && array_keys($glyphsByIndex) !== range(0, \count($glyphsByIndex) - 1)) {
            throw new InvalidFontException('OpenType coverage indexes are not contiguous.');
        }

        return array_values($glyphsByIndex);
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

        return self::uint16(1)
            . self::uint16(\count($glyphs))
            . implode('', array_map(self::uint16(...), $glyphs));
    }

    private static function uint16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }
}
