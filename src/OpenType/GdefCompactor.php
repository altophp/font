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

/**
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

        $subtables = [
            self::compactClassDefinition($reader, $reader->uint16(4), $glyphIds),
            self::compactAttachList($reader, $reader->uint16(6), $glyphIds),
            self::compactLigatureCaretList($reader, $reader->uint16(8), $glyphIds),
            self::compactClassDefinition($reader, $reader->uint16(10), $glyphIds),
        ];

        if ($minorVersion >= 2) {
            $subtables[] = self::compactMarkGlyphSets($reader, $reader->uint16(12), $glyphIds);
        }

        $itemVariationStore = null;

        if (0 !== $itemVariationStoreOffset) {
            $topLevelOffsets = [
                $reader->uint16(4),
                $reader->uint16(6),
                $reader->uint16(8),
                $reader->uint16(10),
                $reader->uint16(12),
            ];

            if ($itemVariationStoreOffset < 18
                || $itemVariationStoreOffset <= max($topLevelOffsets)
                || $itemVariationStoreOffset >= $reader->length()
            ) {
                throw new UnsupportedFontException('Compact GDEF output requires the ItemVariationStore to be the final top-level subtable.');
            }

            $itemVariationStore = $reader->string(
                $itemVariationStoreOffset,
                $reader->length() - $itemVariationStoreOffset,
            );
        }

        $headerLength = match ($minorVersion) {
            0 => 12,
            2 => 14,
            3 => 18,
        };
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

    private static function compactClassDefinition(
        BinaryReader $reader,
        int $offset,
        GlyphIdMap $glyphIds,
    ): ?string {
        if (0 === $offset) {
            return null;
        }

        $classes = ClassDefinitionTable::parse($reader, 0, $offset);
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
                3 => throw new UnsupportedFontException('Compact GDEF output does not support caret device offsets yet.'),
                default => throw new InvalidFontException(\sprintf('GDEF caret value format %d is invalid.', $format)),
            };
        }

        return self::offsetList($carets);
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
