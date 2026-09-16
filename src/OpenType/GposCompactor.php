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
use Alto\Font\OpenType\Layout\LayoutTableDirectory;
use Alto\Font\OpenType\Layout\LookupListTable;
use Alto\Font\OpenType\Layout\LookupTable;

/**
 * Remaps supported GPOS structures to compact glyph identifiers.
 *
 * @author Simon André <smn.andre@gmail.com>
 *
 * @internal
 */
final readonly class GposCompactor
{
    private const int DEVICE_VALUE_FLAGS = 0x00F0;
    private const int RESERVED_VALUE_FLAGS = 0xFF00;

    public static function compact(string $gpos, GlyphIdMap $glyphIds): string
    {
        $reader = new BinaryReader($gpos, 'GPOS compaction source');
        $directory = LayoutTableDirectory::parse($reader, 'GPOS');

        return $directory->build(self::compactLookupList($reader, $directory->lookupListOffset, $glyphIds));
    }

    private static function compactLookupList(BinaryReader $reader, int $offset, GlyphIdMap $glyphIds): string
    {
        $lookupCount = $reader->uint16($offset);
        $lookups = [];

        for ($index = 0; $index < $lookupCount; ++$index) {
            $lookupOffset = $reader->uint16($offset + 2 + $index * 2);

            if (0 === $lookupOffset) {
                throw new InvalidFontException(\sprintf('GPOS lookup %d offset must not be NULL.', $index));
            }

            $lookups[] = self::compactLookup($reader, $offset + $lookupOffset, $index, $lookupCount, $glyphIds);
        }

        return LookupListTable::build($lookups, 9, 'GPOS');
    }

    private static function compactLookup(
        BinaryReader $reader,
        int $offset,
        int $lookupIndex,
        int $lookupCount,
        GlyphIdMap $glyphIds,
    ): LookupTable {
        $lookupType = $reader->uint16($offset);
        $lookupFlag = $reader->uint16($offset + 2);
        $subtableCount = $reader->uint16($offset + 4);

        if (0 === $subtableCount) {
            throw new InvalidFontException(\sprintf('GPOS lookup %d must contain at least one subtable.', $lookupIndex));
        }

        if (9 === $lookupType) {
            return self::compactExtensionLookup(
                $reader,
                $offset,
                $lookupFlag,
                $subtableCount,
                $lookupIndex,
                $lookupCount,
                $glyphIds,
            );
        }

        $subtables = [];

        for ($index = 0; $index < $subtableCount; ++$index) {
            $subtableOffset = $reader->uint16($offset + 6 + $index * 2);

            if (0 === $subtableOffset) {
                throw new InvalidFontException(\sprintf('GPOS lookup %d subtable offset must not be NULL.', $lookupIndex));
            }

            $subtable = $offset + $subtableOffset;
            array_push(
                $subtables,
                ...self::compactSubtable($reader, $lookupType, $subtable, $lookupIndex, $lookupCount, $glyphIds),
            );
        }

        return new LookupTable(
            $lookupType,
            $lookupFlag,
            self::markFilteringSet($reader, $offset, $lookupFlag, $subtableCount),
            $subtables,
        );
    }

    private static function compactExtensionLookup(
        BinaryReader $reader,
        int $offset,
        int $lookupFlag,
        int $subtableCount,
        int $lookupIndex,
        int $lookupCount,
        GlyphIdMap $glyphIds,
    ): LookupTable {
        $extensions = [];
        $extensionLookupType = null;

        for ($index = 0; $index < $subtableCount; ++$index) {
            $subtableOffset = $reader->uint16($offset + 6 + $index * 2);

            if (0 === $subtableOffset) {
                throw new InvalidFontException(\sprintf('GPOS lookup %d extension offset must not be NULL.', $lookupIndex));
            }

            $extension = $offset + $subtableOffset;

            if (1 !== $reader->uint16($extension)) {
                throw new UnsupportedFontException(\sprintf('GPOS lookup %d extension format is not supported.', $lookupIndex));
            }

            $actualLookupType = $reader->uint16($extension + 2);

            if (9 === $actualLookupType || (null !== $extensionLookupType && $actualLookupType !== $extensionLookupType)) {
                throw new UnsupportedFontException(\sprintf('GPOS lookup %d extension types are invalid or inconsistent.', $lookupIndex));
            }

            $extensionOffset = $reader->uint32($extension + 4);

            if ($extensionOffset < 8) {
                throw new InvalidFontException(\sprintf('GPOS lookup %d extension offset is invalid.', $lookupIndex));
            }

            $extensionLookupType = $actualLookupType;
            array_push(
                $extensions,
                ...self::compactSubtable(
                    $reader,
                    $actualLookupType,
                    $extension + $extensionOffset,
                    $lookupIndex,
                    $lookupCount,
                    $glyphIds,
                ),
            );
        }

        return new LookupTable(
            $extensionLookupType ?? 0,
            $lookupFlag,
            self::markFilteringSet($reader, $offset, $lookupFlag, $subtableCount),
            $extensions,
            true,
        );
    }

    private static function markFilteringSet(
        BinaryReader $reader,
        int $offset,
        int $lookupFlag,
        int $subtableCount,
    ): ?int {
        return 0 === ($lookupFlag & 0x0010)
            ? null
            : $reader->uint16($offset + 6 + $subtableCount * 2);
    }

    /**
     * @return non-empty-list<string>
     */
    private static function compactSubtable(
        BinaryReader $reader,
        int $lookupType,
        int $offset,
        int $lookupIndex,
        int $lookupCount,
        GlyphIdMap $glyphIds,
    ): array {
        return match ($lookupType) {
            1 => [self::compactSingleAdjustment($reader, $offset, $lookupIndex, $glyphIds)],
            2 => self::compactPairAdjustment($reader, $offset, $lookupIndex, $glyphIds),
            3 => [self::compactCursiveAttachment($reader, $offset, $lookupIndex, $glyphIds)],
            4 => [self::compactMarkToBase($reader, $offset, $lookupIndex, $glyphIds)],
            5 => [self::compactMarkToLigature($reader, $offset, $lookupIndex, $glyphIds)],
            6 => [self::compactMarkToMark($reader, $offset, $lookupIndex, $glyphIds)],
            7 => [self::compactContext($reader, $offset, $lookupIndex, $lookupCount, $glyphIds)],
            8 => [self::compactChainedContext($reader, $offset, $lookupIndex, $lookupCount, $glyphIds)],
            default => throw new UnsupportedFontException(\sprintf(
                'Compacting GPOS lookup %d type %d is not supported yet.',
                $lookupIndex,
                $lookupType,
            )),
        };
    }

    private static function compactCursiveAttachment(
        BinaryReader $reader,
        int $offset,
        int $lookupIndex,
        GlyphIdMap $glyphIds,
    ): string {
        if (1 !== $reader->uint16($offset)) {
            throw new UnsupportedFontException(\sprintf('Compacting GPOS lookup %d type 3 requires format 1.', $lookupIndex));
        }

        $coverage = CoverageTable::parse($reader, $offset, $reader->uint16($offset + 2));
        $recordCount = $reader->uint16($offset + 4);

        if ($recordCount !== \count($coverage)) {
            throw new InvalidFontException(\sprintf('GPOS lookup %d cursive record count does not match coverage.', $lookupIndex));
        }

        $newCoverage = [];
        $records = [];

        foreach ($coverage as $index => $oldGlyphId) {
            $newGlyphId = $glyphIds->newId($oldGlyphId);

            if (null === $newGlyphId) {
                continue;
            }

            $recordOffset = $offset + 6 + $index * 4;
            $entryOffset = $reader->uint16($recordOffset);
            $exitOffset = $reader->uint16($recordOffset + 2);
            $newCoverage[] = $newGlyphId;
            $records[] = [
                0 === $entryOffset ? null : self::anchor($reader, $offset + $entryOffset, $lookupIndex),
                0 === $exitOffset ? null : self::anchor($reader, $offset + $exitOffset, $lookupIndex),
            ];
        }

        $headerLength = 6 + \count($records) * 4;
        $recordData = '';
        $anchors = '';
        $cursor = $headerLength;

        foreach ($records as [$entry, $exit]) {
            foreach ([$entry, $exit] as $anchor) {
                if (null === $anchor) {
                    $recordData .= self::uint16(0);
                    continue;
                }

                $recordData .= self::offset16($cursor);
                $anchors .= $anchor;
                $cursor += \strlen($anchor);
            }
        }

        $coverageData = CoverageTable::build($newCoverage);

        return self::uint16(1)
            . self::offset16($cursor)
            . self::uint16(\count($records))
            . $recordData
            . $anchors
            . $coverageData;
    }

    private static function compactSingleAdjustment(
        BinaryReader $reader,
        int $offset,
        int $lookupIndex,
        GlyphIdMap $glyphIds,
    ): string {
        $format = $reader->uint16($offset);

        if (!\in_array($format, [1, 2], true)) {
            throw new UnsupportedFontException(\sprintf('Compacting GPOS lookup %d type 1 format %d is not supported.', $lookupIndex, $format));
        }

        $coverage = CoverageTable::parse($reader, $offset, $reader->uint16($offset + 2));
        $valueFormat = $reader->uint16($offset + 4);
        $valueLength = self::valueRecordLength($valueFormat, $lookupIndex);

        if (1 === $format) {
            [$value, $devices] = self::copyValueRecord(
                $reader,
                $offset + 6,
                $valueFormat,
                $offset,
                $lookupIndex,
                6,
            );
            $remapped = self::remapCoverage($coverage, $glyphIds);
            $coverageData = CoverageTable::build($remapped);

            $table = self::uint16(1)
                . self::offset16(6 + $valueLength)
                . self::uint16($valueFormat)
                . $value
                . $coverageData;

            return self::appendDevices($table, $devices);
        }

        $valueCount = $reader->uint16($offset + 6);

        if ($valueCount !== \count($coverage)) {
            throw new InvalidFontException(\sprintf('GPOS lookup %d single-adjustment count does not match coverage.', $lookupIndex));
        }

        $remapped = [];
        $values = '';
        $devices = [];

        foreach ($coverage as $index => $oldGlyphId) {
            $newGlyphId = $glyphIds->newId($oldGlyphId);

            if (null === $newGlyphId) {
                continue;
            }

            $remapped[] = $newGlyphId;
            [$value, $valueDevices] = self::copyValueRecord(
                $reader,
                $offset + 8 + $index * $valueLength,
                $valueFormat,
                $offset,
                $lookupIndex,
                8 + \strlen($values),
            );
            $values .= $value;
            $devices = [...$devices, ...$valueDevices];
        }

        $coverageData = CoverageTable::build($remapped);

        $table = self::uint16(2)
            . self::offset16(8 + \strlen($values))
            . self::uint16($valueFormat)
            . self::uint16(\count($remapped))
            . $values
            . $coverageData;

        return self::appendDevices($table, $devices);
    }

    private static function compactContext(
        BinaryReader $reader,
        int $offset,
        int $lookupIndex,
        int $lookupCount,
        GlyphIdMap $glyphIds,
    ): string {
        return match ($reader->uint16($offset)) {
            1 => self::compactContextFormatOne($reader, $offset, $lookupIndex, $lookupCount, $glyphIds),
            2 => self::compactContextFormatTwo($reader, $offset, $lookupIndex, $lookupCount, $glyphIds),
            3 => self::compactContextFormatThree($reader, $offset, $lookupIndex, $lookupCount, $glyphIds),
            default => throw new UnsupportedFontException(\sprintf(
                'Compacting GPOS lookup %d type 7 uses an unsupported format.',
                $lookupIndex,
            )),
        };
    }

    private static function compactContextFormatOne(
        BinaryReader $reader,
        int $offset,
        int $lookupIndex,
        int $lookupCount,
        GlyphIdMap $glyphIds,
    ): string {
        $coverage = CoverageTable::parse($reader, $offset, $reader->uint16($offset + 2));
        $setCount = $reader->uint16($offset + 4);

        if ($setCount !== \count($coverage)) {
            throw new InvalidFontException(\sprintf('GPOS lookup %d contextual rule-set count does not match coverage.', $lookupIndex));
        }

        $inputs = [];
        $sets = [];

        foreach ($coverage as $coverageIndex => $oldInputGlyphId) {
            $newInputGlyphId = $glyphIds->newId($oldInputGlyphId);
            $setOffset = $reader->uint16($offset + 6 + $coverageIndex * 2);

            if (null === $newInputGlyphId || 0 === $setOffset) {
                continue;
            }

            $set = $offset + $setOffset;
            $rules = [];

            for ($index = 0, $count = $reader->uint16($set); $index < $count; ++$index) {
                $ruleOffset = $reader->uint16($set + 2 + $index * 2);

                if (0 === $ruleOffset) {
                    throw new InvalidFontException(\sprintf('GPOS lookup %d contextual rule offset must not be NULL.', $lookupIndex));
                }

                $rule = self::compactGlyphContextRule($reader, $set + $ruleOffset, $lookupIndex, $lookupCount, $glyphIds);

                if (null !== $rule) {
                    $rules[] = $rule;
                }
            }

            if ([] === $rules) {
                continue;
            }

            $inputs[] = $newInputGlyphId;
            $sets[] = self::offsetList($rules);
        }

        return self::buildContextSets(1, $inputs, $sets);
    }

    private static function compactContextFormatTwo(
        BinaryReader $reader,
        int $offset,
        int $lookupIndex,
        int $lookupCount,
        GlyphIdMap $glyphIds,
    ): string {
        $sourceCoverage = CoverageTable::parse($reader, $offset, $reader->uint16($offset + 2));
        $sourceClasses = ClassDefinitionTable::parse($reader, $offset, $reader->uint16($offset + 4));
        $setCount = $reader->uint16($offset + 6);

        foreach ($sourceCoverage as $glyphId) {
            if (($sourceClasses[$glyphId] ?? 0) >= $setCount) {
                throw new InvalidFontException(\sprintf('GPOS lookup %d contextual class is outside its rule-set array.', $lookupIndex));
            }
        }

        $coverage = self::remapCoverage($sourceCoverage, $glyphIds);
        $classes = self::remapClasses($sourceClasses, $glyphIds);

        $sets = [];

        for ($index = 0; $index < $setCount; ++$index) {
            $setOffset = $reader->uint16($offset + 8 + $index * 2);

            if (0 === $setOffset) {
                $sets[] = null;
                continue;
            }

            $set = $offset + $setOffset;
            $rules = [];

            for ($ruleIndex = 0, $count = $reader->uint16($set); $ruleIndex < $count; ++$ruleIndex) {
                $ruleOffset = $reader->uint16($set + 2 + $ruleIndex * 2);

                if (0 === $ruleOffset) {
                    throw new InvalidFontException(\sprintf('GPOS lookup %d contextual class rule offset must not be NULL.', $lookupIndex));
                }

                $rules[] = self::copyClassContextRule($reader, $set + $ruleOffset, $lookupIndex, $lookupCount);
            }

            $sets[] = self::offsetList($rules);
        }

        $headerLength = 8 + $setCount * 2;
        $setOffsets = '';
        $data = '';
        $cursor = $headerLength;

        foreach ($sets as $set) {
            if (null === $set) {
                $setOffsets .= self::uint16(0);
                continue;
            }

            $setOffsets .= self::offset16($cursor);
            $data .= $set;
            $cursor += \strlen($set);
        }

        $coverageData = CoverageTable::build($coverage);
        $classData = ClassDefinitionTable::build($classes);
        $coverageOffset = $cursor;
        $classOffset = $coverageOffset + \strlen($coverageData);

        return self::uint16(2)
            . self::offset16($coverageOffset)
            . self::offset16($classOffset)
            . self::uint16($setCount)
            . $setOffsets
            . $data
            . $coverageData
            . $classData;
    }

    private static function compactContextFormatThree(
        BinaryReader $reader,
        int $offset,
        int $lookupIndex,
        int $lookupCount,
        GlyphIdMap $glyphIds,
    ): string {
        $glyphCount = $reader->uint16($offset + 2);
        $positioningCount = $reader->uint16($offset + 4);

        if (0 === $glyphCount) {
            throw new InvalidFontException(\sprintf('GPOS lookup %d contextual glyph count must not be zero.', $lookupIndex));
        }

        $coverageData = [];

        for ($index = 0; $index < $glyphCount; ++$index) {
            $coverageData[] = CoverageTable::build(self::remapCoverage(
                CoverageTable::parse($reader, $offset, $reader->uint16($offset + 6 + $index * 2)),
                $glyphIds,
            ));
        }

        $recordsOffset = $offset + 6 + $glyphCount * 2;
        $records = self::positioningRecords($reader, $recordsOffset, $positioningCount, $glyphCount, $lookupIndex, $lookupCount);
        $headerLength = 6 + $glyphCount * 2 + \strlen($records);
        $coverageOffsets = '';
        $data = '';
        $cursor = $headerLength;

        foreach ($coverageData as $coverage) {
            $coverageOffsets .= self::offset16($cursor);
            $data .= $coverage;
            $cursor += \strlen($coverage);
        }

        return self::uint16(3)
            . self::uint16($glyphCount)
            . self::uint16($positioningCount)
            . $coverageOffsets
            . $records
            . $data;
    }

    private static function compactChainedContext(
        BinaryReader $reader,
        int $offset,
        int $lookupIndex,
        int $lookupCount,
        GlyphIdMap $glyphIds,
    ): string {
        return match ($reader->uint16($offset)) {
            1 => self::compactChainedContextFormatOne($reader, $offset, $lookupIndex, $lookupCount, $glyphIds),
            2 => self::compactChainedContextFormatTwo($reader, $offset, $lookupIndex, $lookupCount, $glyphIds),
            3 => self::compactChainedContextFormatThree($reader, $offset, $lookupIndex, $lookupCount, $glyphIds),
            default => throw new UnsupportedFontException(\sprintf(
                'Compacting GPOS lookup %d type 8 uses an unsupported format.',
                $lookupIndex,
            )),
        };
    }

    private static function compactChainedContextFormatOne(
        BinaryReader $reader,
        int $offset,
        int $lookupIndex,
        int $lookupCount,
        GlyphIdMap $glyphIds,
    ): string {

        $coverage = CoverageTable::parse($reader, $offset, $reader->uint16($offset + 2));
        $setCount = $reader->uint16($offset + 4);

        if ($setCount !== \count($coverage)) {
            throw new InvalidFontException(\sprintf('GPOS lookup %d chained rule-set count does not match coverage.', $lookupIndex));
        }

        $inputs = [];
        $sets = [];

        foreach ($coverage as $coverageIndex => $oldInputGlyphId) {
            $newInputGlyphId = $glyphIds->newId($oldInputGlyphId);
            $setOffset = $reader->uint16($offset + 6 + $coverageIndex * 2);

            if (null === $newInputGlyphId || 0 === $setOffset) {
                continue;
            }

            $set = $offset + $setOffset;
            $rules = [];

            for ($index = 0, $count = $reader->uint16($set); $index < $count; ++$index) {
                $ruleOffset = $reader->uint16($set + 2 + $index * 2);

                if (0 === $ruleOffset) {
                    throw new InvalidFontException(\sprintf('GPOS lookup %d chained rule offset must not be NULL.', $lookupIndex));
                }

                $rule = self::compactGlyphChainedRule($reader, $set + $ruleOffset, $lookupIndex, $lookupCount, $glyphIds);

                if (null !== $rule) {
                    $rules[] = $rule;
                }
            }

            if ([] === $rules) {
                continue;
            }

            $inputs[] = $newInputGlyphId;
            $sets[] = self::offsetList($rules);
        }

        $headerLength = 6 + \count($sets) * 2;
        $header = self::uint16(1);
        $data = '';
        $cursor = $headerLength;

        foreach ($sets as $set) {
            $data .= $set;
            $cursor += \strlen($set);
        }

        $coverageData = CoverageTable::build($inputs);
        $coverageOffset = $cursor;
        $cursor = $headerLength;
        $header .= self::offset16($coverageOffset) . self::uint16(\count($sets));

        foreach ($sets as $set) {
            $header .= self::offset16($cursor);
            $cursor += \strlen($set);
        }

        return $header . $data . $coverageData;
    }

    private static function compactChainedContextFormatTwo(
        BinaryReader $reader,
        int $offset,
        int $lookupIndex,
        int $lookupCount,
        GlyphIdMap $glyphIds,
    ): string {
        $sourceCoverage = CoverageTable::parse($reader, $offset, $reader->uint16($offset + 2));
        $backtrackClasses = self::remapClasses(
            self::nullableClasses($reader, $offset, $reader->uint16($offset + 4)),
            $glyphIds,
        );
        $sourceInputClasses = ClassDefinitionTable::parse($reader, $offset, $reader->uint16($offset + 6));
        $lookaheadClasses = self::remapClasses(
            self::nullableClasses($reader, $offset, $reader->uint16($offset + 8)),
            $glyphIds,
        );
        $setCount = $reader->uint16($offset + 10);

        foreach ($sourceCoverage as $glyphId) {
            if (($sourceInputClasses[$glyphId] ?? 0) >= $setCount) {
                throw new InvalidFontException(\sprintf('GPOS lookup %d chained input class is outside its rule-set array.', $lookupIndex));
            }
        }

        $coverage = self::remapCoverage($sourceCoverage, $glyphIds);
        $inputClasses = self::remapClasses($sourceInputClasses, $glyphIds);

        $sets = [];

        for ($index = 0; $index < $setCount; ++$index) {
            $setOffset = $reader->uint16($offset + 12 + $index * 2);

            if (0 === $setOffset) {
                $sets[] = null;
                continue;
            }

            $set = $offset + $setOffset;
            $rules = [];

            for ($ruleIndex = 0, $count = $reader->uint16($set); $ruleIndex < $count; ++$ruleIndex) {
                $ruleOffset = $reader->uint16($set + 2 + $ruleIndex * 2);

                if (0 === $ruleOffset) {
                    throw new InvalidFontException(\sprintf('GPOS lookup %d chained class rule offset must not be NULL.', $lookupIndex));
                }

                $rules[] = self::copyClassChainedRule($reader, $set + $ruleOffset, $lookupIndex, $lookupCount);
            }

            $sets[] = self::offsetList($rules);
        }

        $headerLength = 12 + $setCount * 2;
        $setOffsets = '';
        $data = '';
        $cursor = $headerLength;

        foreach ($sets as $set) {
            if (null === $set) {
                $setOffsets .= self::uint16(0);
                continue;
            }

            $setOffsets .= self::offset16($cursor);
            $data .= $set;
            $cursor += \strlen($set);
        }

        $coverageData = CoverageTable::build($coverage);
        $backtrackData = ClassDefinitionTable::build($backtrackClasses);
        $inputData = ClassDefinitionTable::build($inputClasses);
        $lookaheadData = ClassDefinitionTable::build($lookaheadClasses);
        $coverageOffset = $cursor;
        $backtrackOffset = $coverageOffset + \strlen($coverageData);
        $inputOffset = $backtrackOffset + \strlen($backtrackData);
        $lookaheadOffset = $inputOffset + \strlen($inputData);

        return self::uint16(2)
            . self::offset16($coverageOffset)
            . self::offset16($backtrackOffset)
            . self::offset16($inputOffset)
            . self::offset16($lookaheadOffset)
            . self::uint16($setCount)
            . $setOffsets
            . $data
            . $coverageData
            . $backtrackData
            . $inputData
            . $lookaheadData;
    }

    private static function compactChainedContextFormatThree(
        BinaryReader $reader,
        int $offset,
        int $lookupIndex,
        int $lookupCount,
        GlyphIdMap $glyphIds,
    ): string {
        $cursor = $offset + 2;
        $backtrack = self::coverageSequence($reader, $offset, $cursor, $glyphIds);
        $inputs = self::coverageSequence($reader, $offset, $cursor, $glyphIds);

        if ([] === $inputs) {
            throw new InvalidFontException(\sprintf('GPOS lookup %d chained input coverage count must not be zero.', $lookupIndex));
        }

        $lookahead = self::coverageSequence($reader, $offset, $cursor, $glyphIds);
        $positioningCount = $reader->uint16($cursor);
        $records = self::positioningRecords($reader, $cursor + 2, $positioningCount, \count($inputs), $lookupIndex, $lookupCount);
        $headerLength = 2
            + 2 + \count($backtrack) * 2
            + 2 + \count($inputs) * 2
            + 2 + \count($lookahead) * 2
            + 2 + \strlen($records);
        $coverageData = '';
        $coverageCursor = $headerLength;
        $header = self::uint16(3);

        foreach ([$backtrack, $inputs, $lookahead] as $sequence) {
            $header .= self::uint16(\count($sequence));

            foreach ($sequence as $coverage) {
                $header .= self::offset16($coverageCursor);
                $coverageData .= $coverage;
                $coverageCursor += \strlen($coverage);
            }
        }

        return $header . self::uint16($positioningCount) . $records . $coverageData;
    }

    private static function compactGlyphContextRule(
        BinaryReader $reader,
        int $offset,
        int $lookupIndex,
        int $lookupCount,
        GlyphIdMap $glyphIds,
    ): ?string {
        $glyphCount = $reader->uint16($offset);
        $positioningCount = $reader->uint16($offset + 2);

        if (0 === $glyphCount) {
            throw new InvalidFontException(\sprintf('GPOS lookup %d contextual glyph count must not be zero.', $lookupIndex));
        }

        $inputs = [];

        for ($index = 1; $index < $glyphCount; ++$index) {
            $newGlyphId = $glyphIds->newId($reader->uint16($offset + 2 + $index * 2));

            if (null === $newGlyphId) {
                return null;
            }

            $inputs[] = $newGlyphId;
        }

        $recordsOffset = $offset + 2 + $glyphCount * 2;
        $records = self::positioningRecords($reader, $recordsOffset, $positioningCount, $glyphCount, $lookupIndex, $lookupCount);

        return self::uint16($glyphCount)
            . self::uint16($positioningCount)
            . implode('', array_map(self::uint16(...), $inputs))
            . $records;
    }

    private static function copyClassContextRule(BinaryReader $reader, int $offset, int $lookupIndex, int $lookupCount): string
    {
        $glyphCount = $reader->uint16($offset);
        $positioningCount = $reader->uint16($offset + 2);

        if (0 === $glyphCount) {
            throw new InvalidFontException(\sprintf('GPOS lookup %d contextual class glyph count must not be zero.', $lookupIndex));
        }

        $length = 4 + ($glyphCount - 1) * 2;
        self::positioningRecords($reader, $offset + $length, $positioningCount, $glyphCount, $lookupIndex, $lookupCount);

        return $reader->string($offset, $length + $positioningCount * 4);
    }

    private static function copyClassChainedRule(BinaryReader $reader, int $offset, int $lookupIndex, int $lookupCount): string
    {
        $cursor = $offset;
        $backtrackCount = $reader->uint16($cursor);
        $cursor += 2 + $backtrackCount * 2;
        $inputCount = $reader->uint16($cursor);

        if (0 === $inputCount) {
            throw new InvalidFontException(\sprintf('GPOS lookup %d chained class input count must not be zero.', $lookupIndex));
        }

        $cursor += 2 + ($inputCount - 1) * 2;
        $lookaheadCount = $reader->uint16($cursor);
        $cursor += 2 + $lookaheadCount * 2;
        $positioningCount = $reader->uint16($cursor);
        self::positioningRecords($reader, $cursor + 2, $positioningCount, $inputCount, $lookupIndex, $lookupCount);
        $cursor += 2 + $positioningCount * 4;

        return $reader->string($offset, $cursor - $offset);
    }

    private static function compactGlyphChainedRule(
        BinaryReader $reader,
        int $offset,
        int $lookupIndex,
        int $lookupCount,
        GlyphIdMap $glyphIds,
    ): ?string {
        $cursor = $offset;
        $backtrack = self::remapRuleGlyphs($reader, $cursor, $glyphIds);

        if (null === $backtrack) {
            return null;
        }

        $inputCount = $reader->uint16($cursor);

        if (0 === $inputCount) {
            throw new InvalidFontException('GPOS chained glyph rule input count must not be zero.');
        }

        $cursor += 2;
        $inputs = [];

        for ($index = 1; $index < $inputCount; ++$index) {
            $glyphId = $glyphIds->newId($reader->uint16($cursor));
            $cursor += 2;

            if (null === $glyphId) {
                return null;
            }

            $inputs[] = $glyphId;
        }

        $lookahead = self::remapRuleGlyphs($reader, $cursor, $glyphIds);

        if (null === $lookahead) {
            return null;
        }

        $positioningCount = $reader->uint16($cursor);
        $records = self::positioningRecords($reader, $cursor + 2, $positioningCount, $inputCount, $lookupIndex, $lookupCount);

        return self::uint16(\count($backtrack))
            . implode('', array_map(self::uint16(...), $backtrack))
            . self::uint16($inputCount)
            . implode('', array_map(self::uint16(...), $inputs))
            . self::uint16(\count($lookahead))
            . implode('', array_map(self::uint16(...), $lookahead))
            . self::uint16($positioningCount)
            . $records;
    }

    /**
     * @return list<int>|null
     */
    private static function remapRuleGlyphs(BinaryReader $reader, int &$cursor, GlyphIdMap $glyphIds): ?array
    {
        $count = $reader->uint16($cursor);
        $cursor += 2;
        $glyphs = [];

        for ($index = 0; $index < $count; ++$index) {
            $glyphId = $glyphIds->newId($reader->uint16($cursor));
            $cursor += 2;

            if (null === $glyphId) {
                return null;
            }

            $glyphs[] = $glyphId;
        }

        return $glyphs;
    }

    /**
     * @return non-empty-list<string>
     */
    private static function compactPairAdjustment(
        BinaryReader $reader,
        int $offset,
        int $lookupIndex,
        GlyphIdMap $glyphIds,
    ): array {
        $format = $reader->uint16($offset);

        return match ($format) {
            1 => self::compactPairFormatOne($reader, $offset, $lookupIndex, $glyphIds),
            2 => self::compactPairFormatTwo($reader, $offset, $lookupIndex, $glyphIds),
            default => throw new UnsupportedFontException(\sprintf(
                'Compacting GPOS lookup %d type 2 format %d is not supported.',
                $lookupIndex,
                $format,
            )),
        };
    }

    /**
     * @return non-empty-list<string>
     */
    private static function compactPairFormatOne(
        BinaryReader $reader,
        int $offset,
        int $lookupIndex,
        GlyphIdMap $glyphIds,
    ): array {
        $coverage = CoverageTable::parse($reader, $offset, $reader->uint16($offset + 2));
        $valueFormat1 = $reader->uint16($offset + 4);
        $valueFormat2 = $reader->uint16($offset + 6);
        $valueLength = self::valueRecordLength($valueFormat1, $lookupIndex)
            + self::valueRecordLength($valueFormat2, $lookupIndex);
        $pairSetCount = $reader->uint16($offset + 8);

        if ($pairSetCount !== \count($coverage)) {
            throw new InvalidFontException(\sprintf('GPOS lookup %d pair-set count does not match coverage.', $lookupIndex));
        }

        $firstGlyphs = [];
        $pairSets = [];

        foreach ($coverage as $coverageIndex => $oldFirstGlyphId) {
            $newFirstGlyphId = $glyphIds->newId($oldFirstGlyphId);
            $pairSetOffset = $reader->uint16($offset + 10 + $coverageIndex * 2);

            if (null === $newFirstGlyphId || 0 === $pairSetOffset) {
                continue;
            }

            $pairSet = $offset + $pairSetOffset;
            $records = '';
            $recordCount = 0;
            $pairSetDevices = [];
            $recordOffset = $pairSet + 2;

            for ($index = 0, $count = $reader->uint16($pairSet); $index < $count; ++$index) {
                $newSecondGlyphId = $glyphIds->newId($reader->uint16($recordOffset));

                if (null !== $newSecondGlyphId) {
                    $outputOffset = 4 + \strlen($records);
                    [$value1, $devices1] = self::copyValueRecord(
                        $reader,
                        $recordOffset + 2,
                        $valueFormat1,
                        $pairSet,
                        $lookupIndex,
                        $outputOffset,
                    );
                    [$value2, $devices2] = self::copyValueRecord(
                        $reader,
                        $recordOffset + 2 + self::valueRecordLength($valueFormat1, $lookupIndex),
                        $valueFormat2,
                        $pairSet,
                        $lookupIndex,
                        $outputOffset + \strlen($value1),
                    );
                    $records .= self::uint16($newSecondGlyphId) . $value1 . $value2;
                    $pairSetDevices = [...$pairSetDevices, ...$devices1, ...$devices2];
                    ++$recordCount;
                }

                $recordOffset += 2 + $valueLength;
            }

            if (0 === $recordCount) {
                continue;
            }

            $firstGlyphs[] = $newFirstGlyphId;
            $pairSets[] = self::appendDevices(
                self::uint16($recordCount) . $records,
                $pairSetDevices,
            );
        }

        return self::splitPairFormatOne($firstGlyphs, $pairSets, $valueFormat1, $valueFormat2, $lookupIndex);
    }

    /**
     * @param list<int>    $firstGlyphs
     * @param list<string> $pairSets
     *
     * @return non-empty-list<string>
     */
    private static function splitPairFormatOne(
        array $firstGlyphs,
        array $pairSets,
        int $valueFormat1,
        int $valueFormat2,
        int $lookupIndex,
    ): array {
        if ([] === $pairSets) {
            return [self::buildPairFormatOne([], [], $valueFormat1, $valueFormat2)];
        }

        $subtables = [];
        $groupGlyphs = [];
        $groupPairSets = [];
        $groupDataLength = 0;

        foreach ($pairSets as $index => $pairSet) {
            $pairSetLength = \strlen($pairSet);

            if (12 + $pairSetLength > 0xFFFF) {
                throw new UnsupportedFontException(\sprintf(
                    'GPOS lookup %d contains a PairSet that exceeds a 16-bit PairPos offset.',
                    $lookupIndex,
                ));
            }

            $nextCount = \count($groupPairSets) + 1;
            $nextCoverageOffset = 10 + $nextCount * 2 + $groupDataLength + $pairSetLength;

            if ($nextCoverageOffset > 0xFFFF) {
                $subtables[] = self::buildPairFormatOne($groupGlyphs, $groupPairSets, $valueFormat1, $valueFormat2);
                $groupGlyphs = [];
                $groupPairSets = [];
                $groupDataLength = 0;
            }

            $groupGlyphs[] = $firstGlyphs[$index];
            $groupPairSets[] = $pairSet;
            $groupDataLength += $pairSetLength;
        }

        $subtables[] = self::buildPairFormatOne($groupGlyphs, $groupPairSets, $valueFormat1, $valueFormat2);

        return $subtables;
    }

    /**
     * @param list<int>    $firstGlyphs
     * @param list<string> $pairSets
     */
    private static function buildPairFormatOne(
        array $firstGlyphs,
        array $pairSets,
        int $valueFormat1,
        int $valueFormat2,
    ): string {
        $headerLength = 10 + \count($pairSets) * 2;
        $header = self::uint16(1);
        $data = '';
        $cursor = $headerLength;

        foreach ($pairSets as $pairSet) {
            $data .= $pairSet;
            $cursor += \strlen($pairSet);
        }

        $coverageData = CoverageTable::build($firstGlyphs);
        $coverageOffset = $cursor;
        $cursor = $headerLength;
        $header .= self::offset16($coverageOffset)
            . self::uint16($valueFormat1)
            . self::uint16($valueFormat2)
            . self::uint16(\count($pairSets));

        foreach ($pairSets as $pairSet) {
            $header .= self::offset16($cursor);
            $cursor += \strlen($pairSet);
        }

        return $header . $data . $coverageData;
    }

    /**
     * @return non-empty-list<string>
     */
    private static function compactPairFormatTwo(
        BinaryReader $reader,
        int $offset,
        int $lookupIndex,
        GlyphIdMap $glyphIds,
    ): array {
        $valueFormat1 = $reader->uint16($offset + 4);
        $valueFormat2 = $reader->uint16($offset + 6);
        $recordLength = self::valueRecordLength($valueFormat1, $lookupIndex)
            + self::valueRecordLength($valueFormat2, $lookupIndex);
        $class1Count = $reader->uint16($offset + 12);
        $class2Count = $reader->uint16($offset + 14);
        $sourceClassDefinition1 = ClassDefinitionTable::parse($reader, $offset, $reader->uint16($offset + 8));
        $sourceClassDefinition2 = ClassDefinitionTable::parse($reader, $offset, $reader->uint16($offset + 10));

        if ($class1Count < 1 || $class2Count < 1) {
            throw new InvalidFontException(\sprintf('GPOS lookup %d pair class counts must include class 0.', $lookupIndex));
        }

        $firstGlyphsByClass = [];

        foreach (CoverageTable::parse($reader, $offset, $reader->uint16($offset + 2)) as $oldGlyphId) {
            $newGlyphId = $glyphIds->newId($oldGlyphId);

            if (null === $newGlyphId) {
                continue;
            }

            $class = $sourceClassDefinition1[$oldGlyphId] ?? 0;

            if ($class >= $class1Count) {
                throw new InvalidFontException(\sprintf('GPOS lookup %d class 1 value is outside the matrix.', $lookupIndex));
            }

            $firstGlyphsByClass[$class][] = $newGlyphId;
        }

        if ([] === $firstGlyphsByClass) {
            $empty = self::buildPairFormatTwoChunk(
                [],
                [],
                [0],
                [],
                $recordLength,
                $valueFormat1,
                $valueFormat2,
            );

            return [$empty ?? throw new \LogicException('An empty PairPos format 2 subtable must fit.')];
        }

        $sourceClass2ByNewGlyph = [];
        $sourceClass2s = [0 => true];

        foreach ($glyphIds->pairs() as $oldGlyphId => $newGlyphId) {
            $class = $sourceClassDefinition2[$oldGlyphId] ?? 0;

            if ($class >= $class2Count) {
                throw new InvalidFontException(\sprintf('GPOS lookup %d class 2 value is outside the matrix.', $lookupIndex));
            }

            $sourceClass2ByNewGlyph[$newGlyphId] = $class;
            $sourceClass2s[$class] = true;
        }

        $sourceClass2s = array_keys($sourceClass2s);
        sort($sourceClass2s, \SORT_NUMERIC);
        $newClass2BySource = array_flip($sourceClass2s);
        $classDefinition2 = [];

        foreach ($sourceClass2ByNewGlyph as $newGlyphId => $sourceClass) {
            $newClass = $newClass2BySource[$sourceClass];

            if (0 !== $newClass) {
                $classDefinition2[$newGlyphId] = $newClass;
            }
        }

        ksort($firstGlyphsByClass, \SORT_NUMERIC);
        $rows = [];

        foreach (array_keys($firstGlyphsByClass) as $sourceClass1) {
            $rows[$sourceClass1] = self::pairFormatTwoRow(
                $reader,
                $offset,
                $lookupIndex,
                $sourceClass1,
                $class2Count,
                $sourceClass2s,
                $valueFormat1,
                $valueFormat2,
            );
        }

        $subtables = [];
        $group = [];
        $groupTable = null;

        foreach (array_keys($firstGlyphsByClass) as $sourceClass1) {
            $candidate = [...$group, $sourceClass1];
            $candidateTable = self::buildPairFormatTwoChunk(
                $candidate,
                $firstGlyphsByClass,
                $sourceClass2s,
                $classDefinition2,
                $recordLength,
                $valueFormat1,
                $valueFormat2,
                $rows,
            );

            if (null !== $candidateTable) {
                $group = $candidate;
                $groupTable = $candidateTable;
                continue;
            }

            if (null !== $groupTable) {
                $subtables[] = $groupTable;
                $group = [];
                $groupTable = null;
            }

            $singleTable = self::buildPairFormatTwoChunk(
                [$sourceClass1],
                $firstGlyphsByClass,
                $sourceClass2s,
                $classDefinition2,
                $recordLength,
                $valueFormat1,
                $valueFormat2,
                $rows,
            );

            if (null !== $singleTable) {
                $group = [$sourceClass1];
                $groupTable = $singleTable;
                continue;
            }

            array_push(
                $subtables,
                ...self::pairFormatTwoRowAsFormatOne(
                    $firstGlyphsByClass[$sourceClass1],
                    $rows[$sourceClass1],
                    $sourceClass2s,
                    $sourceClass2ByNewGlyph,
                    $valueFormat1,
                    $valueFormat2,
                    $lookupIndex,
                ),
            );
        }

        if (null !== $groupTable) {
            $subtables[] = $groupTable;
        }

        if ([] === $subtables) {
            throw new \LogicException('PairPos format 2 compaction must produce at least one subtable.');
        }

        return $subtables;
    }

    /**
     * @param list<int> $sourceClass2s
     *
     * @return list<array{data: string, devices: list<array{offset: int, base: int, data: string}>}>
     */
    private static function pairFormatTwoRow(
        BinaryReader $reader,
        int $offset,
        int $lookupIndex,
        int $sourceClass1,
        int $sourceClass2Count,
        array $sourceClass2s,
        int $valueFormat1,
        int $valueFormat2,
    ): array {
        $valueLength1 = self::valueRecordLength($valueFormat1, $lookupIndex);
        $recordLength = $valueLength1 + self::valueRecordLength($valueFormat2, $lookupIndex);
        $cells = [];

        foreach ($sourceClass2s as $sourceClass2) {
            $inputOffset = $offset + 16 + ($sourceClass1 * $sourceClass2Count + $sourceClass2) * $recordLength;
            [$value1, $devices1] = self::copyValueRecord(
                $reader,
                $inputOffset,
                $valueFormat1,
                $offset,
                $lookupIndex,
                0,
            );
            [$value2, $devices2] = self::copyValueRecord(
                $reader,
                $inputOffset + $valueLength1,
                $valueFormat2,
                $offset,
                $lookupIndex,
                \strlen($value1),
            );
            $cells[] = ['data' => $value1 . $value2, 'devices' => [...$devices1, ...$devices2]];
        }

        return $cells;
    }

    /**
     * @param list<int>                                                                                               $sourceClass1s
     * @param array<int, list<int>>                                                                                   $firstGlyphsByClass
     * @param list<int>                                                                                               $sourceClass2s
     * @param array<int, int>                                                                                         $classDefinition2
     * @param array<int, list<array{data: string, devices: list<array{offset: int, base: int, data: string}>}>>          $rows
     */
    private static function buildPairFormatTwoChunk(
        array $sourceClass1s,
        array $firstGlyphsByClass,
        array $sourceClass2s,
        array $classDefinition2,
        int $recordLength,
        int $valueFormat1,
        int $valueFormat2,
        array $rows = [],
    ): ?string {
        $containsSourceClassZero = isset($firstGlyphsByClass[0]) && \in_array(0, $sourceClass1s, true);
        $rowSources = $containsSourceClassZero ? $sourceClass1s : [null, ...$sourceClass1s];
        $classDefinition1 = [];
        $coverage = [];
        $newClass = $containsSourceClassZero ? 0 : 1;

        foreach ($sourceClass1s as $sourceClass1) {
            foreach ($firstGlyphsByClass[$sourceClass1] as $glyphId) {
                $coverage[] = $glyphId;

                if (0 !== $newClass) {
                    $classDefinition1[$glyphId] = $newClass;
                }
            }

            ++$newClass;
        }

        $matrix = '';
        $devices = [];

        foreach ($rowSources as $sourceClass1) {
            foreach ($sourceClass2s as $column => $_sourceClass2) {
                $cell = null === $sourceClass1
                    ? ['data' => str_repeat("\0", $recordLength), 'devices' => []]
                    : $rows[$sourceClass1][$column];
                $cellOffset = 16 + \strlen($matrix);
                $matrix .= $cell['data'];

                foreach ($cell['devices'] as $device) {
                    $devices[] = [
                        'offset' => $cellOffset + $device['offset'],
                        'base' => $device['base'],
                        'data' => $device['data'],
                    ];
                }
            }
        }

        $coverageData = CoverageTable::build($coverage);
        $classDefinitionData1 = ClassDefinitionTable::build($classDefinition1);
        $classDefinitionData2 = ClassDefinitionTable::build($classDefinition2);
        $coverageOffset = 16 + \strlen($matrix);
        $classDefinitionOffset1 = $coverageOffset + \strlen($coverageData);
        $classDefinitionOffset2 = $classDefinitionOffset1 + \strlen($classDefinitionData1);

        if ($coverageOffset > 0xFFFF || $classDefinitionOffset1 > 0xFFFF || $classDefinitionOffset2 > 0xFFFF) {
            return null;
        }

        $table = self::uint16(2)
            . self::uint16($coverageOffset)
            . self::uint16($valueFormat1)
            . self::uint16($valueFormat2)
            . self::uint16($classDefinitionOffset1)
            . self::uint16($classDefinitionOffset2)
            . self::uint16(\count($rowSources))
            . self::uint16(\count($sourceClass2s))
            . $matrix
            . $coverageData
            . $classDefinitionData1
            . $classDefinitionData2;

        try {
            return self::appendDevices($table, $devices);
        } catch (UnsupportedFontException) {
            return null;
        }
    }

    /**
     * @param list<int>                                                                                      $firstGlyphs
     * @param list<array{data: string, devices: list<array{offset: int, base: int, data: string}>}>           $row
     * @param list<int>                                                                                      $sourceClass2s
     * @param array<int, int>                                                                                $sourceClass2ByNewGlyph
     *
     * @return non-empty-list<string>
     */
    private static function pairFormatTwoRowAsFormatOne(
        array $firstGlyphs,
        array $row,
        array $sourceClass2s,
        array $sourceClass2ByNewGlyph,
        int $valueFormat1,
        int $valueFormat2,
        int $lookupIndex,
    ): array {
        $columnBySourceClass2 = array_flip($sourceClass2s);
        $subtables = [];

        foreach ($firstGlyphs as $firstGlyphId) {
            $records = '';
            $recordCount = 0;
            $devices = [];
            $deviceData = [];
            $deviceDataLength = 0;

            foreach ($sourceClass2ByNewGlyph as $secondGlyphId => $sourceClass2) {
                $cell = $row[$columnBySourceClass2[$sourceClass2]];
                $record = self::uint16($secondGlyphId) . $cell['data'];
                $candidateDeviceData = $deviceData;
                $candidateDeviceDataLength = $deviceDataLength;

                foreach ($cell['devices'] as $device) {
                    if (!isset($candidateDeviceData[$device['data']])) {
                        $candidateDeviceData[$device['data']] = true;
                        $candidateDeviceDataLength += \strlen($device['data']);
                    }
                }

                $candidateLength = 2 + \strlen($records) + \strlen($record)
                    + $candidateDeviceDataLength;

                if ($candidateLength > 0xFFF3 && 0 !== $recordCount) {
                    $subtables[] = self::pairFormatOneRecordChunk(
                        $firstGlyphId,
                        $records,
                        $recordCount,
                        $devices,
                        $valueFormat1,
                        $valueFormat2,
                    );
                    $records = '';
                    $recordCount = 0;
                    $devices = [];
                    $deviceData = [];
                    $deviceDataLength = 0;
                }

                $recordOffset = 4 + \strlen($records);
                $records .= $record;

                foreach ($cell['devices'] as $device) {
                    $devices[] = [
                        'offset' => $recordOffset + $device['offset'],
                        'base' => $device['base'],
                        'data' => $device['data'],
                    ];

                    if (!isset($deviceData[$device['data']])) {
                        $deviceData[$device['data']] = true;
                        $deviceDataLength += \strlen($device['data']);
                    }
                }

                ++$recordCount;

                if (2 + \strlen($records) + $deviceDataLength > 0xFFF3) {
                    throw new UnsupportedFontException(\sprintf(
                        'GPOS lookup %d contains a class-pair record that exceeds PairPos format 1 limits.',
                        $lookupIndex,
                    ));
                }
            }

            if (0 !== $recordCount) {
                $subtables[] = self::pairFormatOneRecordChunk(
                    $firstGlyphId,
                    $records,
                    $recordCount,
                    $devices,
                    $valueFormat1,
                    $valueFormat2,
                );
            }
        }

        if ([] === $subtables) {
            throw new \LogicException('A retained PairPos class row must produce at least one subtable.');
        }

        return $subtables;
    }

    /**
     * @param list<array{offset: int, base: int, data: string}> $devices
     */
    private static function pairFormatOneRecordChunk(
        int $firstGlyphId,
        string $records,
        int $recordCount,
        array $devices,
        int $valueFormat1,
        int $valueFormat2,
    ): string {
        $pairSet = self::appendDevices(self::uint16($recordCount) . $records, $devices);

        return self::buildPairFormatOne([$firstGlyphId], [$pairSet], $valueFormat1, $valueFormat2);
    }

    private static function compactMarkToBase(
        BinaryReader $reader,
        int $offset,
        int $lookupIndex,
        GlyphIdMap $glyphIds,
    ): string {
        if (1 !== $reader->uint16($offset)) {
            throw new UnsupportedFontException(\sprintf('Compacting GPOS lookup %d type 4 requires format 1.', $lookupIndex));
        }

        $markCoverage = CoverageTable::parse($reader, $offset, $reader->uint16($offset + 2));
        $baseCoverage = CoverageTable::parse($reader, $offset, $reader->uint16($offset + 4));
        $classCount = $reader->uint16($offset + 6);
        $markArrayOffset = $offset + $reader->uint16($offset + 8);
        $baseArrayOffset = $offset + $reader->uint16($offset + 10);
        $markCount = $reader->uint16($markArrayOffset);
        $baseCount = $reader->uint16($baseArrayOffset);

        if ($markCount !== \count($markCoverage) || $baseCount !== \count($baseCoverage)) {
            throw new InvalidFontException(\sprintf('GPOS lookup %d mark/base array counts do not match coverage.', $lookupIndex));
        }

        $newMarkCoverage = [];
        $marks = [];

        foreach ($markCoverage as $index => $oldGlyphId) {
            $newGlyphId = $glyphIds->newId($oldGlyphId);

            if (null === $newGlyphId) {
                continue;
            }

            $recordOffset = $markArrayOffset + 2 + $index * 4;
            $class = $reader->uint16($recordOffset);
            $anchorOffset = $reader->uint16($recordOffset + 2);

            if ($class >= $classCount || 0 === $anchorOffset) {
                throw new InvalidFontException(\sprintf('GPOS lookup %d mark record is invalid.', $lookupIndex));
            }

            $newMarkCoverage[] = $newGlyphId;
            $marks[] = ['class' => $class, 'anchor' => self::anchor($reader, $markArrayOffset + $anchorOffset, $lookupIndex)];
        }

        $newBaseCoverage = [];
        $bases = [];

        foreach ($baseCoverage as $index => $oldGlyphId) {
            $newGlyphId = $glyphIds->newId($oldGlyphId);

            if (null === $newGlyphId) {
                continue;
            }

            $anchors = [];

            for ($class = 0; $class < $classCount; ++$class) {
                $anchorOffset = $reader->uint16($baseArrayOffset + 2 + ($index * $classCount + $class) * 2);
                $anchors[] = 0 === $anchorOffset
                    ? null
                    : self::anchor($reader, $baseArrayOffset + $anchorOffset, $lookupIndex);
            }

            $newBaseCoverage[] = $newGlyphId;
            $bases[] = $anchors;
        }

        $markCoverageData = CoverageTable::build($newMarkCoverage);
        $baseCoverageData = CoverageTable::build($newBaseCoverage);
        $markArray = self::buildMarkArray($marks);
        $baseArray = self::buildBaseArray($bases, $classCount);
        $markCoverageOffset = 12;
        $baseCoverageOffset = $markCoverageOffset + \strlen($markCoverageData);
        $markArrayOutputOffset = $baseCoverageOffset + \strlen($baseCoverageData);
        $baseArrayOutputOffset = $markArrayOutputOffset + \strlen($markArray);

        return self::uint16(1)
            . self::offset16($markCoverageOffset)
            . self::offset16($baseCoverageOffset)
            . self::uint16($classCount)
            . self::offset16($markArrayOutputOffset)
            . self::offset16($baseArrayOutputOffset)
            . $markCoverageData
            . $baseCoverageData
            . $markArray
            . $baseArray;
    }

    private static function compactMarkToLigature(
        BinaryReader $reader,
        int $offset,
        int $lookupIndex,
        GlyphIdMap $glyphIds,
    ): string {
        if (1 !== $reader->uint16($offset)) {
            throw new UnsupportedFontException(\sprintf('Compacting GPOS lookup %d type 5 requires format 1.', $lookupIndex));
        }

        $markCoverage = CoverageTable::parse($reader, $offset, $reader->uint16($offset + 2));
        $ligatureCoverage = CoverageTable::parse($reader, $offset, $reader->uint16($offset + 4));
        $classCount = $reader->uint16($offset + 6);
        $markArrayOffset = $offset + $reader->uint16($offset + 8);
        $ligatureArrayOffset = $offset + $reader->uint16($offset + 10);
        $markCount = $reader->uint16($markArrayOffset);
        $ligatureCount = $reader->uint16($ligatureArrayOffset);

        if ($markCount !== \count($markCoverage) || $ligatureCount !== \count($ligatureCoverage)) {
            throw new InvalidFontException(\sprintf('GPOS lookup %d mark/ligature array counts do not match coverage.', $lookupIndex));
        }

        [$newMarkCoverage, $marks] = self::compactMarks(
            $reader,
            $markCoverage,
            $markArrayOffset,
            $classCount,
            $lookupIndex,
            $glyphIds,
        );
        $newLigatureCoverage = [];
        $ligatures = [];

        foreach ($ligatureCoverage as $index => $oldGlyphId) {
            $newGlyphId = $glyphIds->newId($oldGlyphId);

            if (null === $newGlyphId) {
                continue;
            }

            $attachOffset = $reader->uint16($ligatureArrayOffset + 2 + $index * 2);

            if (0 === $attachOffset) {
                throw new InvalidFontException(\sprintf('GPOS lookup %d ligature attachment offset must not be NULL.', $lookupIndex));
            }

            $attach = $ligatureArrayOffset + $attachOffset;
            $componentCount = $reader->uint16($attach);

            if (0 === $componentCount) {
                throw new InvalidFontException(\sprintf('GPOS lookup %d ligature component count must not be zero.', $lookupIndex));
            }

            $components = [];

            for ($component = 0; $component < $componentCount; ++$component) {
                $anchors = [];

                for ($class = 0; $class < $classCount; ++$class) {
                    $anchorOffset = $reader->uint16($attach + 2 + ($component * $classCount + $class) * 2);
                    $anchors[] = 0 === $anchorOffset
                        ? null
                        : self::anchor($reader, $attach + $anchorOffset, $lookupIndex);
                }

                $components[] = $anchors;
            }

            $newLigatureCoverage[] = $newGlyphId;
            $ligatures[] = self::buildBaseArray($components, $classCount);
        }

        $markCoverageData = CoverageTable::build($newMarkCoverage);
        $ligatureCoverageData = CoverageTable::build($newLigatureCoverage);
        $markArray = self::buildMarkArray($marks);
        $ligatureArray = self::offsetList($ligatures);
        $markCoverageOffset = 12;
        $ligatureCoverageOffset = $markCoverageOffset + \strlen($markCoverageData);
        $markArrayOutputOffset = $ligatureCoverageOffset + \strlen($ligatureCoverageData);
        $ligatureArrayOutputOffset = $markArrayOutputOffset + \strlen($markArray);

        return self::uint16(1)
            . self::offset16($markCoverageOffset)
            . self::offset16($ligatureCoverageOffset)
            . self::uint16($classCount)
            . self::offset16($markArrayOutputOffset)
            . self::offset16($ligatureArrayOutputOffset)
            . $markCoverageData
            . $ligatureCoverageData
            . $markArray
            . $ligatureArray;
    }

    private static function compactMarkToMark(
        BinaryReader $reader,
        int $offset,
        int $lookupIndex,
        GlyphIdMap $glyphIds,
    ): string {
        if (1 !== $reader->uint16($offset)) {
            throw new UnsupportedFontException(\sprintf('Compacting GPOS lookup %d type 6 requires format 1.', $lookupIndex));
        }

        $markOneCoverage = CoverageTable::parse($reader, $offset, $reader->uint16($offset + 2));
        $markTwoCoverage = CoverageTable::parse($reader, $offset, $reader->uint16($offset + 4));
        $classCount = $reader->uint16($offset + 6);
        $markOneArrayOffset = $offset + $reader->uint16($offset + 8);
        $markTwoArrayOffset = $offset + $reader->uint16($offset + 10);
        $markOneCount = $reader->uint16($markOneArrayOffset);
        $markTwoCount = $reader->uint16($markTwoArrayOffset);

        if ($markOneCount !== \count($markOneCoverage) || $markTwoCount !== \count($markTwoCoverage)) {
            throw new InvalidFontException(\sprintf('GPOS lookup %d mark array counts do not match coverage.', $lookupIndex));
        }

        [$newMarkOneCoverage, $marks] = self::compactMarks(
            $reader,
            $markOneCoverage,
            $markOneArrayOffset,
            $classCount,
            $lookupIndex,
            $glyphIds,
        );
        $newMarkTwoCoverage = [];
        $markTwos = [];

        foreach ($markTwoCoverage as $index => $oldGlyphId) {
            $newGlyphId = $glyphIds->newId($oldGlyphId);

            if (null === $newGlyphId) {
                continue;
            }

            $anchors = [];

            for ($class = 0; $class < $classCount; ++$class) {
                $anchorOffset = $reader->uint16($markTwoArrayOffset + 2 + ($index * $classCount + $class) * 2);
                $anchors[] = 0 === $anchorOffset
                    ? null
                    : self::anchor($reader, $markTwoArrayOffset + $anchorOffset, $lookupIndex);
            }

            $newMarkTwoCoverage[] = $newGlyphId;
            $markTwos[] = $anchors;
        }

        $markOneCoverageData = CoverageTable::build($newMarkOneCoverage);
        $markTwoCoverageData = CoverageTable::build($newMarkTwoCoverage);
        $markOneArray = self::buildMarkArray($marks);
        $markTwoArray = self::buildBaseArray($markTwos, $classCount);
        $markOneCoverageOffset = 12;
        $markTwoCoverageOffset = $markOneCoverageOffset + \strlen($markOneCoverageData);
        $markOneArrayOutputOffset = $markTwoCoverageOffset + \strlen($markTwoCoverageData);
        $markTwoArrayOutputOffset = $markOneArrayOutputOffset + \strlen($markOneArray);

        return self::uint16(1)
            . self::offset16($markOneCoverageOffset)
            . self::offset16($markTwoCoverageOffset)
            . self::uint16($classCount)
            . self::offset16($markOneArrayOutputOffset)
            . self::offset16($markTwoArrayOutputOffset)
            . $markOneCoverageData
            . $markTwoCoverageData
            . $markOneArray
            . $markTwoArray;
    }

    /**
     * @param list<int> $coverage
     *
     * @return array{list<int>, list<array{class: int, anchor: string}>}
     */
    private static function compactMarks(
        BinaryReader $reader,
        array $coverage,
        int $arrayOffset,
        int $classCount,
        int $lookupIndex,
        GlyphIdMap $glyphIds,
    ): array {
        $newCoverage = [];
        $marks = [];

        foreach ($coverage as $index => $oldGlyphId) {
            $newGlyphId = $glyphIds->newId($oldGlyphId);

            if (null === $newGlyphId) {
                continue;
            }

            $recordOffset = $arrayOffset + 2 + $index * 4;
            $class = $reader->uint16($recordOffset);
            $anchorOffset = $reader->uint16($recordOffset + 2);

            if ($class >= $classCount || 0 === $anchorOffset) {
                throw new InvalidFontException(\sprintf('GPOS lookup %d mark record is invalid.', $lookupIndex));
            }

            $newCoverage[] = $newGlyphId;
            $marks[] = ['class' => $class, 'anchor' => self::anchor($reader, $arrayOffset + $anchorOffset, $lookupIndex)];
        }

        return [$newCoverage, $marks];
    }

    /**
     * @param list<array{class: int, anchor: string}> $marks
     */
    private static function buildMarkArray(array $marks): string
    {
        $headerLength = 2 + \count($marks) * 4;
        $header = self::uint16(\count($marks));
        $anchors = '';
        $cursor = $headerLength;

        foreach ($marks as $mark) {
            $header .= self::uint16($mark['class']) . self::offset16($cursor);
            $anchors .= $mark['anchor'];
            $cursor += \strlen($mark['anchor']);
        }

        return $header . $anchors;
    }

    /**
     * @param list<list<?string>> $bases
     */
    private static function buildBaseArray(array $bases, int $classCount): string
    {
        $headerLength = 2 + \count($bases) * $classCount * 2;
        $header = self::uint16(\count($bases));
        $anchors = '';
        $cursor = $headerLength;

        foreach ($bases as $base) {
            if (\count($base) !== $classCount) {
                throw new InvalidFontException('GPOS base record class count is inconsistent.');
            }

            foreach ($base as $anchor) {
                if (null === $anchor) {
                    $header .= self::uint16(0);
                    continue;
                }

                $header .= self::offset16($cursor);
                $anchors .= $anchor;
                $cursor += \strlen($anchor);
            }
        }

        return $header . $anchors;
    }

    private static function anchor(BinaryReader $reader, int $offset, int $lookupIndex): string
    {
        $format = $reader->uint16($offset);

        return match ($format) {
            1 => $reader->string($offset, 6),
            2 => $reader->string($offset, 8),
            3 => self::anchorFormatThree($reader, $offset, $lookupIndex),
            default => throw new InvalidFontException(\sprintf('GPOS lookup %d anchor format %d is invalid.', $lookupIndex, $format)),
        };
    }

    private static function anchorFormatThree(BinaryReader $reader, int $offset, int $lookupIndex): string
    {
        $anchor = $reader->string($offset, 6) . self::uint16(0) . self::uint16(0);
        $devices = [];

        foreach ([6, 8] as $patchOffset) {
            $deviceOffset = $reader->uint16($offset + $patchOffset);

            if (0 !== $deviceOffset) {
                $devices[] = [
                    'offset' => $patchOffset,
                    'base' => 0,
                    'data' => self::deviceTable($reader, $offset + $deviceOffset, $lookupIndex),
                ];
            }
        }

        return self::appendDevices($anchor, $devices);
    }

    private static function valueRecordLength(int $format, int $lookupIndex): int
    {
        if (0 !== ($format & self::RESERVED_VALUE_FLAGS)) {
            throw new InvalidFontException(\sprintf('GPOS lookup %d value format contains reserved bits.', $lookupIndex));
        }

        $length = 0;

        for ($bit = 1; $bit <= 0x0080; $bit <<= 1) {
            if (0 !== ($format & $bit)) {
                $length += 2;
            }
        }

        return $length;
    }

    /**
     * @return array{string, list<array{offset: int, base: int, data: string}>}
     */
    private static function copyValueRecord(
        BinaryReader $reader,
        int $inputOffset,
        int $format,
        int $subtableOffset,
        int $lookupIndex,
        int $outputOffset,
    ): array {
        self::valueRecordLength($format, $lookupIndex);
        $data = '';
        $devices = [];
        $cursor = $inputOffset;

        for ($bit = 1; $bit <= 0x0080; $bit <<= 1) {
            if (0 === ($format & $bit)) {
                continue;
            }

            if (0 === ($bit & self::DEVICE_VALUE_FLAGS)) {
                $data .= $reader->string($cursor, 2);
                $cursor += 2;
                continue;
            }

            $deviceOffset = $reader->uint16($cursor);
            $cursor += 2;
            $patchOffset = $outputOffset + \strlen($data);
            $data .= self::uint16(0);

            if (0 !== $deviceOffset) {
                $devices[] = [
                    'offset' => $patchOffset,
                    'base' => 0,
                    'data' => self::deviceTable($reader, $subtableOffset + $deviceOffset, $lookupIndex),
                ];
            }
        }

        return [$data, $devices];
    }

    private static function deviceTable(BinaryReader $reader, int $offset, int $lookupIndex): string
    {
        $startSize = $reader->uint16($offset);
        $endSize = $reader->uint16($offset + 2);
        $format = $reader->uint16($offset + 4);

        if (0x8000 === $format) {
            return $reader->string($offset, 6);
        }

        if (!\in_array($format, [1, 2, 3], true) || $endSize < $startSize) {
            throw new InvalidFontException(\sprintf('GPOS lookup %d device table is invalid.', $lookupIndex));
        }

        $bitsPerValue = 1 << $format;
        $wordCount = intdiv(($endSize - $startSize + 1) * $bitsPerValue + 15, 16);

        return $reader->string($offset, 6 + $wordCount * 2);
    }

    /**
     * @param list<array{offset: int, base: int, data: string}> $devices
     */
    private static function appendDevices(string $table, array $devices): string
    {
        $offsetsByData = [];

        foreach ($devices as $device) {
            $deviceOffset = $offsetsByData[$device['data']] ?? null;

            if (null === $deviceOffset) {
                $deviceOffset = \strlen($table);
                $offsetsByData[$device['data']] = $deviceOffset;
                $table .= $device['data'];
            }

            $relativeOffset = $deviceOffset - $device['base'];
            $table = substr_replace($table, self::offset16($relativeOffset), $device['offset'], 2);
        }

        return $table;
    }

    /**
     * @param list<int>    $inputs
     * @param list<string> $sets
     */
    private static function buildContextSets(int $format, array $inputs, array $sets): string
    {
        $headerLength = 6 + \count($sets) * 2;
        $data = '';
        $cursor = $headerLength;

        foreach ($sets as $set) {
            $data .= $set;
            $cursor += \strlen($set);
        }

        $coverage = CoverageTable::build($inputs);
        $setOffsets = '';
        $setCursor = $headerLength;

        foreach ($sets as $set) {
            $setOffsets .= self::offset16($setCursor);
            $setCursor += \strlen($set);
        }

        return self::uint16($format)
            . self::offset16($cursor)
            . self::uint16(\count($sets))
            . $setOffsets
            . $data
            . $coverage;
    }

    /**
     * @return list<string>
     */
    private static function coverageSequence(
        BinaryReader $reader,
        int $baseOffset,
        int &$cursor,
        GlyphIdMap $glyphIds,
    ): array {
        $count = $reader->uint16($cursor);
        $cursor += 2;
        $coverages = [];

        for ($index = 0; $index < $count; ++$index) {
            $coverages[] = CoverageTable::build(self::remapCoverage(
                CoverageTable::parse($reader, $baseOffset, $reader->uint16($cursor)),
                $glyphIds,
            ));
            $cursor += 2;
        }

        return $coverages;
    }

    private static function positioningRecords(
        BinaryReader $reader,
        int $offset,
        int $count,
        int $inputCount,
        int $lookupIndex,
        int $lookupCount,
    ): string {
        for ($index = 0; $index < $count; ++$index) {
            if ($reader->uint16($offset + $index * 4) >= $inputCount) {
                throw new InvalidFontException(\sprintf('GPOS lookup %d positioning record sequence index is out of range.', $lookupIndex));
            }

            if ($reader->uint16($offset + $index * 4 + 2) >= $lookupCount) {
                throw new InvalidFontException(\sprintf('GPOS lookup %d positioning record lookup index is out of range.', $lookupIndex));
            }
        }

        return $reader->string($offset, $count * 4);
    }

    /**
     * @param list<int> $glyphs
     *
     * @return list<int>
     */
    private static function remapCoverage(array $glyphs, GlyphIdMap $glyphIds): array
    {
        $remapped = [];

        foreach ($glyphs as $oldGlyphId) {
            $newGlyphId = $glyphIds->newId($oldGlyphId);

            if (null !== $newGlyphId) {
                $remapped[] = $newGlyphId;
            }
        }

        return $remapped;
    }

    /**
     * @param array<int, int> $classes
     *
     * @return array<int, int>
     */
    private static function remapClasses(array $classes, GlyphIdMap $glyphIds): array
    {
        $remapped = [];

        foreach ($glyphIds->pairs() as $oldGlyphId => $newGlyphId) {
            $class = $classes[$oldGlyphId] ?? 0;

            if (0 !== $class) {
                $remapped[$newGlyphId] = $class;
            }
        }

        return $remapped;
    }

    /**
     * @return array<int, int>
     */
    private static function nullableClasses(BinaryReader $reader, int $baseOffset, int $relativeOffset): array
    {
        return 0 === $relativeOffset ? [] : ClassDefinitionTable::parse($reader, $baseOffset, $relativeOffset);
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
            throw new UnsupportedFontException('Compacted GPOS data exceeds a 16-bit OpenType offset.');
        }

        return self::uint16($value);
    }

    private static function uint16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }

}
