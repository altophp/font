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
use Alto\Font\OpenType\GlyphIdMap;

/**
 * Parses and builds packed variation delta-set index mappings.
 *
 * @author Simon André <smn.andre@gmail.com>
 *
 * @internal
 */
final readonly class DeltaSetIndexMapTable
{
    private const int INNER_INDEX_BIT_COUNT_MASK = 0x0F;
    private const int MAP_ENTRY_SIZE_MASK = 0x30;

    /**
     * @return array{entries: list<array{0: int, 1: int}>, end: int}
     */
    public static function parse(BinaryReader $reader, int $offset): array
    {
        if ($offset < 0 || $offset >= $reader->length()) {
            throw new InvalidFontException('DeltaSetIndexMap offset is invalid.');
        }

        $format = $reader->uint8($offset);

        if (!\in_array($format, [0, 1], true)) {
            throw new InvalidFontException(\sprintf('Unsupported DeltaSetIndexMap format %d.', $format));
        }

        if ($offset + 4 > $reader->length()) {
            throw new InvalidFontException('DeltaSetIndexMap length is invalid.');
        }

        $entryFormat = $reader->uint8($offset + 1);

        if (0 !== ($entryFormat & 0xC0)) {
            throw new InvalidFontException('DeltaSetIndexMap entry format uses reserved bits.');
        }

        $headerLength = 0 === $format ? 4 : 6;

        if ($offset + $headerLength > $reader->length()) {
            throw new InvalidFontException('DeltaSetIndexMap length is invalid.');
        }

        $count = 0 === $format ? $reader->uint16($offset + 2) : $reader->uint32($offset + 2);

        if (0 === $count) {
            throw new InvalidFontException('DeltaSetIndexMap must contain at least one entry.');
        }

        $innerBitCount = ($entryFormat & self::INNER_INDEX_BIT_COUNT_MASK) + 1;
        $entrySize = (($entryFormat & self::MAP_ENTRY_SIZE_MASK) >> 4) + 1;
        $innerMask = (1 << $innerBitCount) - 1;
        $cursor = $offset + $headerLength;
        $end = $cursor + ($count * $entrySize);

        if ($end > $reader->length()) {
            throw new InvalidFontException('DeltaSetIndexMap length is invalid.');
        }

        $entries = [];

        for ($index = 0; $index < $count; ++$index) {
            $entry = 0;

            for ($byte = 0; $byte < $entrySize; ++$byte) {
                $entry = ($entry << 8) | $reader->uint8($cursor++);
            }

            $entries[] = [$entry >> $innerBitCount, $entry & $innerMask];
        }

        return ['entries' => $entries, 'end' => $end];
    }

    /**
     * @param null|list<array{0: int, 1: int}> $entries
     */
    public static function remap(?array $entries, GlyphIdMap $glyphIds): string
    {
        $remapped = [];

        foreach ($glyphIds->pairs() as $oldGlyphId => $_newGlyphId) {
            $remapped[] = null === $entries
                ? [0, $oldGlyphId]
                : $entries[min($oldGlyphId, \count($entries) - 1)];
        }

        return self::build($remapped);
    }

    /**
     * @param list<array{0: int, 1: int}> $entries
     */
    public static function build(array $entries): string
    {
        if ([] === $entries || \count($entries) > 0xFFFF) {
            throw new InvalidFontException('DeltaSetIndexMap must contain between 1 and 65535 entries.');
        }

        $maximumInner = max(array_column($entries, 1));
        $innerBitCount = max(1, self::bitCount($maximumInner));
        $maximumEntry = 0;

        foreach ($entries as [$outerIndex, $innerIndex]) {
            if ($outerIndex < 0 || $innerIndex < 0 || $innerBitCount > 16) {
                throw new UnsupportedFontException('Delta-set indexes exceed the compact mapping format.');
            }

            $maximumEntry = max($maximumEntry, ($outerIndex << $innerBitCount) | $innerIndex);
        }

        $entrySize = max(1, intdiv(self::bitCount($maximumEntry) + 7, 8));

        if ($entrySize > 4) {
            throw new UnsupportedFontException('Delta-set indexes exceed four bytes.');
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

}
