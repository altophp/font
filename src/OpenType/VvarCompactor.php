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
 * Compacts vertical variation mappings.
 *
 * @author Simon André <smn.andre@gmail.com>
 *
 * @internal
 */
final readonly class VvarCompactor
{
    private const int HEADER_LENGTH = 24;
    private const int INNER_INDEX_BIT_COUNT_MASK = 0x0F;
    private const int MAP_ENTRY_SIZE_MASK = 0x30;

    public static function compact(string $vvar, GlyphIdMap $glyphIds, int $axisCount): string
    {
        if ($axisCount < 1 || $axisCount > 0xFFFF) {
            throw new InvalidFontException('VVAR compaction requires a valid fvar axis count.');
        }

        $reader = new BinaryReader($vvar, 'VVAR compaction source');

        if (1 !== $reader->uint16(0) || 0 !== $reader->uint16(2)) {
            throw new UnsupportedFontException('Compact VVAR output supports version 1.0 only.');
        }

        $storeOffset = $reader->uint32(4);
        $mappingOffsets = [
            'advance height' => $reader->uint32(8),
            'top side bearing' => $reader->uint32(12),
            'bottom side bearing' => $reader->uint32(16),
            'vertical origin' => $reader->uint32(20),
        ];
        $mapEntries = [
            'advance height' => null,
            'top side bearing' => null,
            'bottom side bearing' => null,
            'vertical origin' => null,
        ];

        if ($storeOffset < self::HEADER_LENGTH || $storeOffset >= $reader->length()) {
            throw new InvalidFontException('VVAR item variation store offset is invalid.');
        }

        $maps = [];

        foreach ($mappingOffsets as $name => $offset) {
            if (0 === $offset) {
                continue;
            }

            $map = self::parseMap($reader, $offset, $name);
            $maps[$name] = ['offset' => $offset, ...$map];
            $mapEntries[$name] = $map['entries'];
        }

        uasort($maps, static fn(array $left, array $right): int => $left['offset'] <=> $right['offset']);
        $previousEnd = 0;

        foreach ($maps as $map) {
            if ($map['offset'] < $previousEnd) {
                throw new InvalidFontException('VVAR delta-set mappings overlap.');
            }

            $previousEnd = $map['end'];
        }

        $mappingRanges = array_values(array_map(
            static fn(array $map): array => [$map['offset'], $map['end']],
            $maps,
        ));
        $storeStructureEnd = self::validateItemVariationStore(
            $reader,
            $storeOffset,
            $reader->length(),
            $axisCount,
            $mappingRanges,
        );

        foreach ($maps as $map) {
            if ($map['offset'] < $storeStructureEnd && $map['end'] > $storeOffset) {
                throw new InvalidFontException('VVAR ItemVariationStore overlaps a delta-set mapping.');
            }
        }

        $storeEnd = $reader->length();

        foreach ($maps as $map) {
            if ($map['offset'] >= $storeStructureEnd) {
                $storeEnd = $map['offset'];

                break;
            }
        }

        $store = $reader->string($storeOffset, $storeEnd - $storeOffset);
        $outputs = [];

        foreach ($mappingOffsets as $name => $offset) {
            if (0 === $offset && 'advance height' !== $name) {
                $outputs[$name] = null;

                continue;
            }

            $outputs[$name] = self::remap($mapEntries[$name], $glyphIds);
        }

        $cursor = self::HEADER_LENGTH + \strlen($store);
        $outputOffsets = [];

        foreach ($outputs as $name => $output) {
            $outputOffsets[$name] = null === $output ? 0 : $cursor;
            $cursor += null === $output ? 0 : \strlen($output);
        }

        return self::uint16(1)
            . self::uint16(0)
            . self::uint32(self::HEADER_LENGTH)
            . self::uint32($outputOffsets['advance height'])
            . self::uint32($outputOffsets['top side bearing'])
            . self::uint32($outputOffsets['bottom side bearing'])
            . self::uint32($outputOffsets['vertical origin'])
            . $store
            . $outputs['advance height']
            . ($outputs['top side bearing'] ?? '')
            . ($outputs['bottom side bearing'] ?? '')
            . ($outputs['vertical origin'] ?? '');
    }

    /**
     * @param null|list<array{0: int, 1: int}> $entries
     */
    private static function remap(?array $entries, GlyphIdMap $glyphIds): string
    {
        $remapped = [];

        foreach ($glyphIds->pairs() as $oldGlyphId => $_newGlyphId) {
            $remapped[] = null === $entries
                ? [0, $oldGlyphId]
                : $entries[min($oldGlyphId, \count($entries) - 1)];
        }

        return self::buildMap($remapped);
    }

    /**
     * @return array{entries: list<array{0: int, 1: int}>, end: int}
     */
    private static function parseMap(BinaryReader $reader, int $offset, string $name): array
    {
        if ($offset < self::HEADER_LENGTH || $offset >= $reader->length()) {
            throw new InvalidFontException(\sprintf('VVAR %s mapping offset is invalid.', $name));
        }

        if ($offset + 4 > $reader->length()) {
            throw new InvalidFontException(\sprintf('VVAR %s mapping length is invalid.', $name));
        }

        $format = $reader->uint8($offset);

        if (!\in_array($format, [0, 1], true)) {
            throw new UnsupportedFontException(\sprintf('Compact VVAR %s mapping supports formats 0 and 1 only.', $name));
        }

        $entryFormat = $reader->uint8($offset + 1);

        if (0 !== ($entryFormat & 0xC0)) {
            throw new InvalidFontException(\sprintf('VVAR %s mapping entry format is invalid.', $name));
        }

        $headerLength = 0 === $format ? 4 : 6;

        if ($offset + $headerLength > $reader->length()) {
            throw new InvalidFontException(\sprintf('VVAR %s mapping length is invalid.', $name));
        }

        $count = 0 === $format ? $reader->uint16($offset + 2) : $reader->uint32($offset + 2);

        if (0 === $count) {
            throw new InvalidFontException(\sprintf('VVAR %s mapping must contain at least one entry.', $name));
        }

        $innerBitCount = ($entryFormat & self::INNER_INDEX_BIT_COUNT_MASK) + 1;
        $entrySize = (($entryFormat & self::MAP_ENTRY_SIZE_MASK) >> 4) + 1;
        $innerMask = (1 << $innerBitCount) - 1;
        $cursor = $offset + $headerLength;
        $end = $cursor + ($count * $entrySize);

        if ($end > $reader->length()) {
            throw new InvalidFontException(\sprintf('VVAR %s mapping length is invalid.', $name));
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
     * @param list<array{int, int}> $mappingRanges
     */
    private static function validateItemVariationStore(
        BinaryReader $reader,
        int $offset,
        int $end,
        int $expectedAxisCount,
        array $mappingRanges,
    ): int {
        if ($end - $offset < 8 || 1 !== $reader->uint16($offset)) {
            throw new InvalidFontException('VVAR ItemVariationStore is invalid.');
        }

        $regionListOffset = $reader->uint32($offset + 2);
        $dataCount = $reader->uint16($offset + 6);
        $headerEnd = $offset + 8 + ($dataCount * 4);

        if ($headerEnd > $end || $regionListOffset < 8 + ($dataCount * 4)) {
            throw new InvalidFontException('VVAR ItemVariationStore header is invalid.');
        }

        self::assertNoMappingOverlap($offset, $headerEnd, $mappingRanges);
        $ranges = [[$offset, $headerEnd]];
        $regionListStart = $offset + $regionListOffset;

        if ($regionListStart + 4 > $end) {
            throw new InvalidFontException('VVAR ItemVariationStore region list offset is invalid.');
        }

        $axisCount = $reader->uint16($regionListStart);
        $regionCount = $reader->uint16($regionListStart + 2);

        if ($axisCount !== $expectedAxisCount) {
            throw new InvalidFontException('VVAR ItemVariationStore axis count does not match fvar.');
        }

        $regionListEnd = $regionListStart + 4 + ($axisCount * $regionCount * 6);

        if ($regionListEnd > $end) {
            throw new InvalidFontException('VVAR ItemVariationStore region list length is invalid.');
        }

        self::assertNoMappingOverlap($regionListStart, $regionListEnd, $mappingRanges);
        $ranges[] = [$regionListStart, $regionListEnd];

        for ($index = 0; $index < $dataCount; ++$index) {
            $dataOffset = $reader->uint32($offset + 8 + ($index * 4));

            if (0 === $dataOffset) {
                continue;
            }

            $dataStart = $offset + $dataOffset;

            if ($dataOffset < 8 + ($dataCount * 4) || $dataStart + 6 > $end) {
                throw new InvalidFontException('VVAR ItemVariationStore data offset is invalid.');
            }

            self::assertNoMappingOverlap($dataStart, $dataStart + 6, $mappingRanges);
            $itemCount = $reader->uint16($dataStart);
            $wordDeltaCount = $reader->uint16($dataStart + 2);
            $regionIndexCount = $reader->uint16($dataStart + 4);
            $longWords = 0 !== ($wordDeltaCount & 0x8000);
            $wordDeltaCount &= 0x7FFF;

            if ($longWords) {
                throw new UnsupportedFontException('VVAR ItemVariationStore does not support 32-bit delta words.');
            }

            if ($wordDeltaCount > $regionIndexCount) {
                throw new InvalidFontException('VVAR ItemVariationStore word delta count is invalid.');
            }

            $dataHeaderEnd = $dataStart + 6 + ($regionIndexCount * 2);

            if ($dataHeaderEnd > $end) {
                throw new InvalidFontException('VVAR ItemVariationStore data header length is invalid.');
            }

            for ($regionIndex = 0; $regionIndex < $regionIndexCount; ++$regionIndex) {
                if ($reader->uint16($dataStart + 6 + ($regionIndex * 2)) >= $regionCount) {
                    throw new InvalidFontException('VVAR ItemVariationStore region index is invalid.');
                }
            }

            $rowLength = ($wordDeltaCount * 2) + ($regionIndexCount - $wordDeltaCount);
            $dataEnd = $dataHeaderEnd + ($itemCount * $rowLength);

            if ($dataEnd > $end) {
                throw new InvalidFontException('VVAR ItemVariationStore data length is invalid.');
            }

            self::assertNoMappingOverlap($dataStart, $dataEnd, $mappingRanges);
            $ranges[] = [$dataStart, $dataEnd];
        }

        usort($ranges, static fn(array $left, array $right): int => $left[0] <=> $right[0]);
        $previousEnd = $ranges[0][1];

        foreach (array_slice($ranges, 1) as [$rangeStart, $rangeEnd]) {
            if ($rangeStart < $previousEnd) {
                throw new InvalidFontException('VVAR ItemVariationStore structures overlap.');
            }

            $previousEnd = $rangeEnd;
        }

        return $previousEnd;
    }

    /**
     * @param list<array{int, int}> $mappingRanges
     */
    private static function assertNoMappingOverlap(int $start, int $end, array $mappingRanges): void
    {
        foreach ($mappingRanges as [$mappingStart, $mappingEnd]) {
            if ($start < $mappingEnd && $end > $mappingStart) {
                throw new InvalidFontException('VVAR ItemVariationStore overlaps a delta-set mapping.');
            }
        }
    }

    /**
     * @param list<array{0: int, 1: int}> $entries
     */
    private static function buildMap(array $entries): string
    {
        if ([] === $entries || \count($entries) > 0xFFFF) {
            throw new InvalidFontException('VVAR compact mapping must contain between 1 and 65535 entries.');
        }

        $maximumInner = max(array_column($entries, 1));
        $innerBitCount = max(1, self::bitCount($maximumInner));
        $maximumEntry = 0;

        foreach ($entries as [$outerIndex, $innerIndex]) {
            if ($outerIndex < 0 || $innerIndex < 0 || $innerBitCount > 16) {
                throw new UnsupportedFontException('VVAR delta-set indexes exceed the compact mapping format.');
            }

            $maximumEntry = max($maximumEntry, ($outerIndex << $innerBitCount) | $innerIndex);
        }

        $entrySize = max(1, intdiv(self::bitCount($maximumEntry) + 7, 8));

        if ($entrySize > 4) {
            throw new UnsupportedFontException('VVAR delta-set indexes exceed four bytes.');
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
