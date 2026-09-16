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
 * Compacts legacy OpenType kerning pairs.
 *
 * @author Simon André <smn.andre@gmail.com>
 *
 * @internal
 */
final readonly class KernCompactor
{
    private const int PAIR_SIZE = 6;
    private const int SUBTABLE_HEADER_SIZE = 14;

    public static function compact(string $kern, GlyphIdMap $glyphIds): string
    {
        $reader = new BinaryReader($kern, 'kern compaction source');

        if (0 !== $reader->uint16(0)) {
            throw new UnsupportedFontException('Compact kern output supports OpenType version 0 only.');
        }

        $subtableCount = $reader->uint16(2);

        if (0 === $subtableCount) {
            throw new InvalidFontException('kern table must contain at least one subtable.');
        }

        $cursor = 4;
        $subtables = '';

        for ($index = 0; $index < $subtableCount; ++$index) {
            $length = $reader->uint16($cursor + 2);

            if ($length < self::SUBTABLE_HEADER_SIZE) {
                throw new InvalidFontException(\sprintf('kern subtable %d length is invalid.', $index));
            }

            $reader->string($cursor, $length);
            $subtables .= self::compactSubtable($reader, $cursor, $length, $index, $glyphIds);
            $cursor += $length;
        }

        if ($cursor !== $reader->length()) {
            throw new InvalidFontException('kern table contains data outside its declared subtables.');
        }

        return self::uint16(0) . self::uint16($subtableCount) . $subtables;
    }

    private static function compactSubtable(
        BinaryReader $reader,
        int $offset,
        int $length,
        int $index,
        GlyphIdMap $glyphIds,
    ): string {
        if (0 !== $reader->uint16($offset)) {
            throw new UnsupportedFontException(\sprintf('Compact kern output supports subtable version 0 only; subtable %d differs.', $index));
        }

        $coverage = $reader->uint16($offset + 4);
        $format = $coverage >> 8;

        if (0 !== $format) {
            throw new UnsupportedFontException(\sprintf('Compact kern output supports subtable format 0 only; subtable %d uses format %d.', $index, $format));
        }

        if (0 !== ($coverage & 0x00F0)) {
            throw new InvalidFontException(\sprintf('kern subtable %d coverage contains reserved flags.', $index));
        }

        $pairCount = $reader->uint16($offset + 6);
        $expectedLength = self::SUBTABLE_HEADER_SIZE + $pairCount * self::PAIR_SIZE;

        if ($length < $expectedLength || $length - $expectedLength > 3) {
            throw new InvalidFontException(\sprintf('kern subtable %d length does not match its pair count.', $index));
        }

        if ($length > $expectedLength
            && trim($reader->string($offset + $expectedLength, $length - $expectedLength), "\0") !== ''
        ) {
            throw new InvalidFontException(\sprintf('kern subtable %d padding must contain only NULL bytes.', $index));
        }

        [$searchRange, $entrySelector, $rangeShift] = self::searchParameters($pairCount);

        if ($reader->uint16($offset + 8) !== $searchRange
            || $reader->uint16($offset + 10) !== $entrySelector
            || $reader->uint16($offset + 12) !== $rangeShift
        ) {
            throw new InvalidFontException(\sprintf('kern subtable %d search parameters are invalid.', $index));
        }

        /** @var list<array{left: int, right: int, value: int}> $pairs */
        $pairs = [];
        $previousKey = -1;

        for ($pairIndex = 0; $pairIndex < $pairCount; ++$pairIndex) {
            $pairOffset = $offset + self::SUBTABLE_HEADER_SIZE + $pairIndex * self::PAIR_SIZE;
            $left = $reader->uint16($pairOffset);
            $right = $reader->uint16($pairOffset + 2);
            $key = ($left << 16) | $right;

            if ($left >= $glyphIds->sourceGlyphCount() || $right >= $glyphIds->sourceGlyphCount()) {
                throw new InvalidFontException(\sprintf(
                    'kern subtable %d pair %d references a glyph outside the source font.',
                    $index,
                    $pairIndex,
                ));
            }

            if ($key <= $previousKey) {
                throw new InvalidFontException(\sprintf('kern subtable %d pairs must be strictly increasing.', $index));
            }

            $previousKey = $key;
            $newLeft = $glyphIds->newId($left);
            $newRight = $glyphIds->newId($right);

            if (null === $newLeft || null === $newRight) {
                continue;
            }

            $pairs[] = [
                'left' => $newLeft,
                'right' => $newRight,
                'value' => $reader->int16($pairOffset + 4),
            ];
        }

        usort($pairs, static fn(array $left, array $right): int => [$left['left'], $left['right']] <=> [$right['left'], $right['right']]);

        return self::buildSubtable($coverage, $pairs);
    }

    /**
     * @param list<array{left: int, right: int, value: int}> $pairs
     */
    private static function buildSubtable(int $coverage, array $pairs): string
    {
        $pairCount = \count($pairs);
        $length = self::SUBTABLE_HEADER_SIZE + $pairCount * self::PAIR_SIZE;

        if ($length > 0xFFFF) {
            throw new UnsupportedFontException('Compacted kern subtable exceeds its 16-bit length.');
        }

        [$searchRange, $entrySelector, $rangeShift] = self::searchParameters($pairCount);
        $data = '';

        foreach ($pairs as $pair) {
            $data .= self::uint16($pair['left'])
                . self::uint16($pair['right'])
                . self::uint16($pair['value']);
        }

        return self::uint16(0)
            . self::uint16($length)
            . self::uint16($coverage)
            . self::uint16($pairCount)
            . self::uint16($searchRange)
            . self::uint16($entrySelector)
            . self::uint16($rangeShift)
            . $data;
    }

    /**
     * @return array{int, int, int}
     */
    private static function searchParameters(int $pairCount): array
    {
        if (0 === $pairCount) {
            return [0, 0, 0];
        }

        $maximumPowerOfTwo = 1;
        $entrySelector = 0;

        while ($maximumPowerOfTwo * 2 <= $pairCount) {
            $maximumPowerOfTwo *= 2;
            ++$entrySelector;
        }

        $searchRange = $maximumPowerOfTwo * self::PAIR_SIZE;

        return [$searchRange, $entrySelector, $pairCount * self::PAIR_SIZE - $searchRange];
    }

    private static function uint16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }
}
