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
 * Parses and builds OpenType class definition tables.
 *
 * @author Simon André <smn.andre@gmail.com>
 *
 * @internal
 */
final readonly class ClassDefinitionTable
{
    /**
     * @return array<int, int>
     */
    public static function parse(BinaryReader $reader, int $baseOffset, int $relativeOffset): array
    {
        if (0 === $relativeOffset) {
            throw new InvalidFontException('OpenType class definition offset must not be NULL.');
        }

        $offset = $baseOffset + $relativeOffset;
        $format = $reader->uint16($offset);

        if (1 === $format) {
            $startGlyphId = $reader->uint16($offset + 2);
            $glyphCount = $reader->uint16($offset + 4);
            $classes = [];

            for ($index = 0; $index < $glyphCount; ++$index) {
                $class = $reader->uint16($offset + 6 + $index * 2);

                if (0 !== $class) {
                    $classes[$startGlyphId + $index] = $class;
                }
            }

            return $classes;
        }

        if (2 !== $format) {
            throw new UnsupportedFontException(\sprintf('OpenType class definition format %d is not supported.', $format));
        }

        $classes = [];
        $lastEnd = -1;

        for ($index = 0, $count = $reader->uint16($offset + 2); $index < $count; ++$index) {
            $rangeOffset = $offset + 4 + $index * 6;
            $start = $reader->uint16($rangeOffset);
            $end = $reader->uint16($rangeOffset + 2);
            $class = $reader->uint16($rangeOffset + 4);

            if ($end < $start || $start <= $lastEnd) {
                throw new InvalidFontException('OpenType class definition ranges are invalid or overlap.');
            }

            if (0 !== $class) {
                for ($glyphId = $start; $glyphId <= $end; ++$glyphId) {
                    $classes[$glyphId] = $class;
                }
            }

            $lastEnd = $end;
        }

        return $classes;
    }

    /**
     * @param array<int, int> $classes
     */
    public static function build(array $classes): string
    {
        ksort($classes, \SORT_NUMERIC);
        /** @var list<array{start: int, end: int, class: int}> $ranges */
        $ranges = [];

        foreach ($classes as $glyphId => $class) {
            if ($glyphId < 0 || $glyphId > 0xFFFF || $class < 0 || $class > 0xFFFF) {
                throw new InvalidFontException('OpenType class definition contains an invalid glyph or class ID.');
            }

            if (0 === $class) {
                continue;
            }

            $last = \count($ranges) - 1;

            if ($last >= 0 && $glyphId === $ranges[$last]['end'] + 1 && $class === $ranges[$last]['class']) {
                $ranges[$last]['end'] = $glyphId;
                continue;
            }

            $ranges[] = ['start' => $glyphId, 'end' => $glyphId, 'class' => $class];
        }

        $data = '';

        foreach ($ranges as $range) {
            $data .= self::uint16($range['start'])
                . self::uint16($range['end'])
                . self::uint16($range['class']);
        }

        return self::uint16(2) . self::uint16(\count($ranges)) . $data;
    }

    private static function uint16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }
}
