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
 * Compacts vertical variation mappings.
 *
 * @author Simon André <smn.andre@gmail.com>
 *
 * @internal
 */
final readonly class VvarCompactor
{
    private const int HEADER_LENGTH = 24;

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
            // Several metrics may reference the same physical mapping table.
            $maps[$offset] = ['offset' => $offset, ...$map];
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

        $parsedStore = ItemVariationStoreTable::parse($reader, $storeOffset, $axisCount);

        foreach ($parsedStore['ranges'] as [$start, $end]) {
            foreach ($maps as $map) {
                if ($start < $map['end'] && $end > $map['offset']) {
                    throw new InvalidFontException('VVAR ItemVariationStore overlaps a delta-set mapping.');
                }
            }
        }

        $store = $parsedStore['data'];
        $outputs = [];

        foreach ($mappingOffsets as $name => $offset) {
            if (0 === $offset && 'advance height' !== $name) {
                $outputs[$name] = null;

                continue;
            }

            $outputs[$name] = DeltaSetIndexMapTable::remap($mapEntries[$name], $glyphIds);
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
     * @return array{entries: list<array{0: int, 1: int}>, end: int}
     */
    private static function parseMap(BinaryReader $reader, int $offset, string $name): array
    {
        if ($offset < self::HEADER_LENGTH || $offset >= $reader->length()) {
            throw new InvalidFontException(\sprintf('VVAR %s mapping offset is invalid.', $name));
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
