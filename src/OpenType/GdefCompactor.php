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
use Alto\Font\OpenType\Layout\ClassDefinitionTable;
use Alto\Font\OpenType\Layout\CoverageTable;
use Alto\Font\OpenType\Layout\DeviceTable;
use Alto\Font\OpenType\Layout\ItemVariationStoreTable;

/**
 * Remaps supported GDEF structures to compact glyph identifiers.
 *
 * @author Simon André <smn.andre@gmail.com>
 *
 * @internal
 */
final readonly class GdefCompactor
{
    public static function compact(string $gdef, GlyphIdMap $glyphIds): string
    {
        $reader = new BinaryReader($gdef, 'GDEF compaction source');
        $majorVersion = $reader->uint16(0);
        $minorVersion = $reader->uint16(2);

        if (1 !== $majorVersion || !\in_array($minorVersion, [0, 2, 3], true)) {
            throw new UnsupportedFontException(\sprintf('Compact GDEF output does not support version %d.%d.', $majorVersion, $minorVersion));
        }

        $itemVariationStoreOffset = 3 === $minorVersion ? $reader->uint32(14) : 0;
        $headerLength = match ($minorVersion) {
            0 => 12,
            2 => 14,
            3 => 18,
        };
        $itemVariationStore = null;
        $storeRanges = [];

        if (0 !== $itemVariationStoreOffset) {
            if ($itemVariationStoreOffset < $headerLength) {
                throw new InvalidFontException('GDEF ItemVariationStore overlaps the table header.');
            }

            $store = ItemVariationStoreTable::parse($reader, $itemVariationStoreOffset);

            foreach ([4, 6, 8, 10, 12] as $field) {
                $subtableOffset = $reader->uint16($field);

                foreach ($store['ranges'] as [$start, $end]) {
                    if ($subtableOffset >= $start && $subtableOffset < $end) {
                        throw new InvalidFontException('GDEF ItemVariationStore overlaps another subtable.');
                    }
                }
            }

            $itemVariationStore = $store['data'];
            $storeRanges = $store['ranges'];
        }

        $subtables = [
            self::compactClassDefinition($reader, $reader->uint16(4), $glyphIds, $storeRanges),
            self::compactAttachList($reader, $reader->uint16(6), $glyphIds),
            self::compactLigatureCaretList($reader, $reader->uint16(8), $glyphIds),
            self::compactClassDefinition($reader, $reader->uint16(10), $glyphIds, $storeRanges),
        ];

        if ($minorVersion >= 2) {
            $subtables[] = self::compactMarkGlyphSets($reader, $reader->uint16(12), $glyphIds);
        }

        $header = self::uint16(1) . self::uint16($minorVersion);
        $data = '';
        $cursor = $headerLength;

        foreach ($subtables as $subtable) {
            if (null === $subtable) {
                $header .= self::uint16(0);
                continue;
            }

            $header .= self::offset16($cursor);
            $data .= $subtable;
            $cursor += \strlen($subtable);
        }

        if (3 === $minorVersion) {
            $header .= self::uint32(null === $itemVariationStore ? 0 : $cursor);
        }

        return $header . $data . ($itemVariationStore ?? '');
    }

    /**
     * @param list<array{int, int}> $storeRanges
     */
    private static function compactClassDefinition(
        BinaryReader $reader,
        int $offset,
        GlyphIdMap $glyphIds,
        array $storeRanges,
    ): ?string {
        if (0 === $offset) {
            return null;
        }

        $classes = ClassDefinitionTable::parse($reader, 0, $offset);
        // Class definitions have inline entries, so their complete source span
        // matters even when their start precedes the variation store.
        $length = 1 === $reader->uint16($offset)
            ? 6 + $reader->uint16($offset + 4) * 2
            : 4 + $reader->uint16($offset + 2) * 6;

        foreach ($storeRanges as [$start, $end]) {
            if ($offset < $end && $offset + $length > $start) {
                throw new InvalidFontException('GDEF ItemVariationStore overlaps another subtable.');
            }
        }

        $remapped = [];

        foreach ($glyphIds->pairs() as $oldGlyphId => $newGlyphId) {
            $class = $classes[$oldGlyphId] ?? 0;

            if (0 !== $class) {
                $remapped[$newGlyphId] = $class;
            }
        }

        return ClassDefinitionTable::build($remapped);
    }

    private static function compactAttachList(
        BinaryReader $reader,
        int $offset,
        GlyphIdMap $glyphIds,
    ): ?string {
        if (0 === $offset) {
            return null;
        }

        $coverage = CoverageTable::parse($reader, $offset, $reader->uint16($offset));
        $glyphCount = $reader->uint16($offset + 2);

        if ($glyphCount !== \count($coverage)) {
            throw new InvalidFontException('GDEF attachment count does not match coverage.');
        }

        $newCoverage = [];
        $points = [];

        foreach ($coverage as $index => $oldGlyphId) {
            $newGlyphId = $glyphIds->newId($oldGlyphId);

            if (null === $newGlyphId) {
                continue;
            }

            $pointOffset = $reader->uint16($offset + 4 + $index * 2);

            if (0 === $pointOffset) {
                throw new InvalidFontException('GDEF attachment point offset must not be NULL.');
            }

            $pointCount = $reader->uint16($offset + $pointOffset);
            $newCoverage[] = $newGlyphId;
            $points[] = $reader->string($offset + $pointOffset, 2 + $pointCount * 2);
        }

        return self::coverageRecordList($newCoverage, $points);
    }

    private static function compactLigatureCaretList(
        BinaryReader $reader,
        int $offset,
        GlyphIdMap $glyphIds,
    ): ?string {
        if (0 === $offset) {
            return null;
        }

        $coverage = CoverageTable::parse($reader, $offset, $reader->uint16($offset));
        $ligatureCount = $reader->uint16($offset + 2);

        if ($ligatureCount !== \count($coverage)) {
            throw new InvalidFontException('GDEF ligature caret count does not match coverage.');
        }

        $newCoverage = [];
        $ligatures = [];

        foreach ($coverage as $index => $oldGlyphId) {
            $newGlyphId = $glyphIds->newId($oldGlyphId);

            if (null === $newGlyphId) {
                continue;
            }

            $ligatureOffset = $reader->uint16($offset + 4 + $index * 2);

            if (0 === $ligatureOffset) {
                throw new InvalidFontException('GDEF ligature glyph offset must not be NULL.');
            }

            $newCoverage[] = $newGlyphId;
            $ligatures[] = self::compactLigatureGlyph($reader, $offset + $ligatureOffset);
        }

        return self::coverageRecordList($newCoverage, $ligatures);
    }

    private static function compactLigatureGlyph(BinaryReader $reader, int $offset): string
    {
        $caretCount = $reader->uint16($offset);
        $carets = [];

        for ($index = 0; $index < $caretCount; ++$index) {
            $caretOffset = $reader->uint16($offset + 2 + $index * 2);

            if (0 === $caretOffset) {
                throw new InvalidFontException('GDEF caret value offset must not be NULL.');
            }

            $caret = $offset + $caretOffset;
            $format = $reader->uint16($caret);

            $carets[] = match ($format) {
                1, 2 => $reader->string($caret, 4),
                3 => self::compactAdjustedCaret($reader, $caret),
                default => throw new InvalidFontException(\sprintf('GDEF caret value format %d is invalid.', $format)),
            };
        }

        return self::offsetList($carets);
    }

    private static function compactAdjustedCaret(BinaryReader $reader, int $offset): string
    {
        $caret = $reader->string($offset, 6);
        $deviceOffset = $reader->uint16($offset + 4);

        if (0 === $deviceOffset) {
            return $caret;
        }

        if ($deviceOffset < 6) {
            throw new InvalidFontException('GDEF caret adjustment overlaps the caret value.');
        }

        return DeviceTable::append($caret, [[
            'offset' => 4,
            'base' => 0,
            'data' => DeviceTable::copy($reader, $offset + $deviceOffset),
        ]]);
    }

    private static function compactMarkGlyphSets(
        BinaryReader $reader,
        int $offset,
        GlyphIdMap $glyphIds,
    ): ?string {
        if (0 === $offset) {
            return null;
        }

        if (1 !== $reader->uint16($offset)) {
            throw new UnsupportedFontException('Compact GDEF output supports mark glyph sets format 1 only.');
        }

        $count = $reader->uint16($offset + 2);
        $coverages = [];

        for ($index = 0; $index < $count; ++$index) {
            $coverageOffset = $reader->uint32($offset + 4 + $index * 4);

            if (0 === $coverageOffset) {
                throw new InvalidFontException('GDEF mark glyph set coverage offset must not be NULL.');
            }

            $coverage = CoverageTable::parse($reader, $offset, $coverageOffset);
            $remapped = [];

            foreach ($coverage as $oldGlyphId) {
                $newGlyphId = $glyphIds->newId($oldGlyphId);

                if (null !== $newGlyphId) {
                    $remapped[] = $newGlyphId;
                }
            }

            $coverages[] = CoverageTable::build($remapped);
        }

        $headerLength = 4 + $count * 4;
        $header = self::uint16(1) . self::uint16($count);
        $data = '';
        $cursor = $headerLength;

        foreach ($coverages as $coverage) {
            $header .= self::uint32($cursor);
            $data .= $coverage;
            $cursor += \strlen($coverage);
        }

        return $header . $data;
    }

    /**
     * @param list<int>    $coverage
     * @param list<string> $records
     */
    private static function coverageRecordList(array $coverage, array $records): string
    {
        if (\count($coverage) !== \count($records)) {
            throw new InvalidFontException('GDEF coverage and record counts are inconsistent.');
        }

        $headerLength = 4 + \count($records) * 2;
        $header = '';
        $data = '';
        $cursor = $headerLength;

        foreach ($records as $record) {
            $header .= self::offset16($cursor);
            $data .= $record;
            $cursor += \strlen($record);
        }

        $coverageData = CoverageTable::build($coverage);

        return self::offset16($cursor)
            . self::uint16(\count($records))
            . $header
            . $data
            . $coverageData;
    }

    /**
     * @param list<string> $items
     */
    private static function offsetList(array $items): string
    {
        $headerLength = 2 + \count($items) * 2;
        $header = self::uint16(\count($items));
        $data = '';
        $cursor = $headerLength;

        foreach ($items as $item) {
            $header .= self::offset16($cursor);
            $data .= $item;
            $cursor += \strlen($item);
        }

        return $header . $data;
    }

    private static function offset16(int $value): string
    {
        if ($value < 0 || $value > 0xFFFF) {
            throw new UnsupportedFontException('Compacted GDEF data exceeds a 16-bit OpenType offset.');
        }

        return self::uint16($value);
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
