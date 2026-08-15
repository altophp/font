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
final readonly class GposCompactor
{
    private const int DEVICE_VALUE_FLAGS = 0x00F0;
    private const int RESERVED_VALUE_FLAGS = 0xFF00;

    public static function compact(string $gpos, GlyphIdMap $glyphIds): string
    {
        $reader = new BinaryReader($gpos, 'GPOS compaction source');

        if (1 !== $reader->uint16(0) || 0 !== $reader->uint16(2)) {
            throw new UnsupportedFontException('Compact GPOS output currently supports version 1.0 only.');
        }

        $scriptListOffset = $reader->uint16(4);
        $featureListOffset = $reader->uint16(6);
        $lookupListOffset = $reader->uint16(8);

        if ($scriptListOffset < 10
            || $featureListOffset <= $scriptListOffset
            || $lookupListOffset <= $featureListOffset
            || $lookupListOffset >= $reader->length()
        ) {
            throw new UnsupportedFontException('Compact GPOS output requires ordered script, feature, and lookup lists.');
        }

        $scriptList = $reader->string($scriptListOffset, $featureListOffset - $scriptListOffset);
        $featureList = $reader->string($featureListOffset, $lookupListOffset - $featureListOffset);
        $lookupList = self::compactLookupList($reader, $lookupListOffset, $glyphIds);
        $newScriptListOffset = 10;
        $newFeatureListOffset = $newScriptListOffset + \strlen($scriptList);
        $newLookupListOffset = $newFeatureListOffset + \strlen($featureList);

        return self::uint16(1)
            . self::uint16(0)
            . self::offset16($newScriptListOffset)
            . self::offset16($newFeatureListOffset)
            . self::offset16($newLookupListOffset)
            . $scriptList
            . $featureList
            . $lookupList;
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

            $lookups[] = self::compactLookup($reader, $offset + $lookupOffset, $index, $glyphIds);
        }

        return self::offsetList($lookups);
    }

    private static function compactLookup(
        BinaryReader $reader,
        int $offset,
        int $lookupIndex,
        GlyphIdMap $glyphIds,
    ): string {
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
            $subtables[] = self::compactSubtable($reader, $lookupType, $subtable, $lookupIndex, $glyphIds);
        }

        $headerLength = 6 + \count($subtables) * 2 + (0 !== ($lookupFlag & 0x0010) ? 2 : 0);
        $header = self::uint16($lookupType) . self::uint16($lookupFlag) . self::uint16(\count($subtables));
        $data = '';
        $cursor = $headerLength;

        foreach ($subtables as $subtable) {
            $header .= self::offset16($cursor);
            $data .= $subtable;
            $cursor += \strlen($subtable);
        }

        if (0 !== ($lookupFlag & 0x0010)) {
            $header .= self::uint16($reader->uint16($offset + 6 + $subtableCount * 2));
        }

        return $header . $data;
    }

    private static function compactExtensionLookup(
        BinaryReader $reader,
        int $offset,
        int $lookupFlag,
        int $subtableCount,
        int $lookupIndex,
        GlyphIdMap $glyphIds,
    ): string {
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
            $extensions[] = self::compactSubtable(
                $reader,
                $actualLookupType,
                $extension + $extensionOffset,
                $lookupIndex,
                $glyphIds,
            );
        }

        $headerLength = 6 + $subtableCount * 2 + (0 !== ($lookupFlag & 0x0010) ? 2 : 0);
        $header = self::uint16(9) . self::uint16($lookupFlag) . self::uint16($subtableCount);
        $wrapperCursor = $headerLength;

        foreach ($extensions as $_extension) {
            $header .= self::offset16($wrapperCursor);
            $wrapperCursor += 8;
        }

        if (0 !== ($lookupFlag & 0x0010)) {
            $header .= self::uint16($reader->uint16($offset + 6 + $subtableCount * 2));
        }

        $wrappers = '';
        $data = '';
        $actualCursor = $headerLength + $subtableCount * 8;

        foreach ($extensions as $index => $extension) {
            $currentWrapper = $headerLength + $index * 8;
            $wrappers .= self::uint16(1)
                . self::uint16($extensionLookupType ?? 0)
                . self::uint32($actualCursor - $currentWrapper);
            $data .= $extension;
            $actualCursor += \strlen($extension);
        }

        return $header . $wrappers . $data;
    }

    private static function compactSubtable(
        BinaryReader $reader,
        int $lookupType,
        int $offset,
        int $lookupIndex,
        GlyphIdMap $glyphIds,
    ): string {
        return match ($lookupType) {
            1 => self::compactSingleAdjustment($reader, $offset, $lookupIndex, $glyphIds),
            2 => self::compactPairAdjustment($reader, $offset, $lookupIndex, $glyphIds),
            4 => self::compactMarkToBase($reader, $offset, $lookupIndex, $glyphIds),
            8 => self::compactChainedContext($reader, $offset, $lookupIndex, $glyphIds),
            default => throw new UnsupportedFontException(\sprintf(
                'Compacting GPOS lookup %d type %d is not supported yet.',
                $lookupIndex,
                $lookupType,
            )),
        };
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

    private static function compactChainedContext(
        BinaryReader $reader,
        int $offset,
        int $lookupIndex,
        GlyphIdMap $glyphIds,
    ): string {
        if (1 !== $reader->uint16($offset)) {
            throw new UnsupportedFontException(\sprintf('Compacting GPOS lookup %d type 8 requires format 1.', $lookupIndex));
        }

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

                $rule = self::compactGlyphChainedRule($reader, $set + $ruleOffset, $glyphIds);

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

    private static function compactGlyphChainedRule(
        BinaryReader $reader,
        int $offset,
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
        $records = $reader->string($cursor + 2, $positioningCount * 4);

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

    private static function compactPairAdjustment(
        BinaryReader $reader,
        int $offset,
        int $lookupIndex,
        GlyphIdMap $glyphIds,
    ): string {
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

    private static function compactPairFormatOne(
        BinaryReader $reader,
        int $offset,
        int $lookupIndex,
        GlyphIdMap $glyphIds,
    ): string {
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

    private static function compactPairFormatTwo(
        BinaryReader $reader,
        int $offset,
        int $lookupIndex,
        GlyphIdMap $glyphIds,
    ): string {
        $valueFormat1 = $reader->uint16($offset + 4);
        $valueFormat2 = $reader->uint16($offset + 6);
        $recordLength = self::valueRecordLength($valueFormat1, $lookupIndex)
            + self::valueRecordLength($valueFormat2, $lookupIndex);
        $class1Count = $reader->uint16($offset + 12);
        $class2Count = $reader->uint16($offset + 14);
        $matrixLength = $class1Count * $class2Count * $recordLength;
        $matrix = '';
        $devices = [];

        for ($recordOffset = 0; $recordOffset < $matrixLength; $recordOffset += $recordLength) {
            [$value1, $devices1] = self::copyValueRecord(
                $reader,
                $offset + 16 + $recordOffset,
                $valueFormat1,
                $offset,
                $lookupIndex,
                16 + \strlen($matrix),
            );
            [$value2, $devices2] = self::copyValueRecord(
                $reader,
                $offset + 16 + $recordOffset + self::valueRecordLength($valueFormat1, $lookupIndex),
                $valueFormat2,
                $offset,
                $lookupIndex,
                16 + \strlen($matrix) + \strlen($value1),
            );
            $matrix .= $value1 . $value2;
            $devices = [...$devices, ...$devices1, ...$devices2];
        }
        $coverage = self::remapCoverage(
            CoverageTable::parse($reader, $offset, $reader->uint16($offset + 2)),
            $glyphIds,
        );
        $classDefinition1 = self::remapClasses(
            ClassDefinitionTable::parse($reader, $offset, $reader->uint16($offset + 8)),
            $glyphIds,
        );
        $classDefinition2 = self::remapClasses(
            ClassDefinitionTable::parse($reader, $offset, $reader->uint16($offset + 10)),
            $glyphIds,
        );

        foreach ($classDefinition1 as $class) {
            if ($class >= $class1Count) {
                throw new InvalidFontException(\sprintf('GPOS lookup %d class 1 value is outside the matrix.', $lookupIndex));
            }
        }

        foreach ($classDefinition2 as $class) {
            if ($class >= $class2Count) {
                throw new InvalidFontException(\sprintf('GPOS lookup %d class 2 value is outside the matrix.', $lookupIndex));
            }
        }

        $coverageData = CoverageTable::build($coverage);
        $classDefinitionData1 = ClassDefinitionTable::build($classDefinition1);
        $classDefinitionData2 = ClassDefinitionTable::build($classDefinition2);
        $coverageOffset = 16 + \strlen($matrix);
        $classDefinitionOffset1 = $coverageOffset + \strlen($coverageData);
        $classDefinitionOffset2 = $classDefinitionOffset1 + \strlen($classDefinitionData1);

        $table = self::uint16(2)
            . self::offset16($coverageOffset)
            . self::uint16($valueFormat1)
            . self::uint16($valueFormat2)
            . self::offset16($classDefinitionOffset1)
            . self::offset16($classDefinitionOffset2)
            . self::uint16($class1Count)
            . self::uint16($class2Count)
            . $matrix
            . $coverageData
            . $classDefinitionData1
            . $classDefinitionData2;

        return self::appendDevices($table, $devices);
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
            3 => throw new UnsupportedFontException(\sprintf(
                'Compacting GPOS lookup %d anchor format 3 with device offsets is not supported yet.',
                $lookupIndex,
            )),
            default => throw new InvalidFontException(\sprintf('GPOS lookup %d anchor format %d is invalid.', $lookupIndex, $format)),
        };
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

    private static function uint32(int $value): string
    {
        return pack('N', $value & 0xFFFFFFFF);
    }
}
