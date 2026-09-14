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
use Alto\Font\OpenType\Layout\LayoutTableDirectory;
use Alto\Font\OpenType\Layout\LookupListTable;
use Alto\Font\OpenType\Layout\LookupTable;

/**
 * Remaps supported GSUB structures to compact glyph identifiers.
 *
 * @author Simon André <smn.andre@gmail.com>
 *
 * @internal
 */
final readonly class GsubCompactor
{
    public static function compact(string $gsub, GlyphIdMap $glyphIds): string
    {
        $reader = new BinaryReader($gsub, 'GSUB compaction source');
        $directory = LayoutTableDirectory::parse($reader, 'GSUB');

        return $directory->build(self::compactLookupList($reader, $directory->lookupListOffset, $glyphIds));
    }

    private static function compactLookupList(BinaryReader $reader, int $offset, GlyphIdMap $glyphIds): string
    {
        $lookupCount = $reader->uint16($offset);
        $lookups = [];

        for ($index = 0; $index < $lookupCount; ++$index) {
            $lookupOffset = $reader->uint16($offset + 2 + $index * 2);

            if (0 === $lookupOffset) {
                throw new InvalidFontException(\sprintf('GSUB lookup %d offset must not be NULL.', $index));
            }

            $lookups[] = self::compactLookup($reader, $offset + $lookupOffset, $index, $lookupCount, $glyphIds);
        }

        return LookupListTable::build($lookups, 7, 'GSUB');
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
            throw new InvalidFontException(\sprintf('GSUB lookup %d must contain at least one subtable.', $lookupIndex));
        }

        if (7 === $lookupType) {
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
                throw new InvalidFontException(\sprintf('GSUB lookup %d subtable offset must not be NULL.', $lookupIndex));
            }

            $subtable = $offset + $subtableOffset;
            $subtables[] = self::compactSubtable($reader, $lookupType, $subtable, $lookupIndex, $lookupCount, $glyphIds);
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
                throw new InvalidFontException(\sprintf('GSUB lookup %d extension offset must not be NULL.', $lookupIndex));
            }

            $extension = $offset + $subtableOffset;

            if (1 !== $reader->uint16($extension)) {
                throw new UnsupportedFontException(\sprintf('GSUB lookup %d extension format is not supported.', $lookupIndex));
            }

            $actualLookupType = $reader->uint16($extension + 2);

            if (7 === $actualLookupType || (null !== $extensionLookupType && $actualLookupType !== $extensionLookupType)) {
                throw new UnsupportedFontException(\sprintf('GSUB lookup %d extension types are invalid or inconsistent.', $lookupIndex));
            }

            $extensionOffset = $reader->uint32($extension + 4);

            if ($extensionOffset < 8) {
                throw new InvalidFontException(\sprintf('GSUB lookup %d extension offset is invalid.', $lookupIndex));
            }

            $extensionLookupType = $actualLookupType;
            $extensions[] = self::compactSubtable(
                $reader,
                $actualLookupType,
                $extension + $extensionOffset,
                $lookupIndex,
                $lookupCount,
                $glyphIds,
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

    private static function compactSubtable(
        BinaryReader $reader,
        int $lookupType,
        int $offset,
        int $lookupIndex,
        int $lookupCount,
        GlyphIdMap $glyphIds,
    ): string {
        return match ($lookupType) {
            1 => self::compactSingleSubstitution($reader, $offset, $lookupIndex, $glyphIds),
            2 => self::compactMultipleSubstitution($reader, $offset, $lookupIndex, $glyphIds),
            3 => self::compactAlternateSubstitution($reader, $offset, $lookupIndex, $glyphIds),
            4 => self::compactLigatureSubstitution($reader, $offset, $lookupIndex, $glyphIds),
            5 => self::compactContext($reader, $offset, $lookupIndex, $lookupCount, $glyphIds),
            6 => self::compactChainedContext($reader, $offset, $lookupIndex, $lookupCount, $glyphIds),
            8 => self::compactReverseChainedSingleSubstitution($reader, $offset, $lookupIndex, $glyphIds),
            default => throw new UnsupportedFontException(\sprintf(
                'Compacting GSUB lookup %d type %d is not supported yet.',
                $lookupIndex,
                $lookupType,
            )),
        };
    }

    private static function compactAlternateSubstitution(
        BinaryReader $reader,
        int $offset,
        int $lookupIndex,
        GlyphIdMap $glyphIds,
    ): string {
        if (1 !== $reader->uint16($offset)) {
            throw new InvalidFontException(\sprintf('GSUB lookup %d type 3 format must be 1.', $lookupIndex));
        }

        $coverageOffset = $reader->uint16($offset + 2);
        $coverage = self::coverage($reader, $offset, $coverageOffset);
        $setCount = $reader->uint16($offset + 4);

        if ($setCount !== \count($coverage)) {
            throw new InvalidFontException(\sprintf('GSUB lookup %d alternate-set count does not match coverage.', $lookupIndex));
        }

        $headerLength = 6 + $setCount * 2;

        if ($coverageOffset < $headerLength) {
            throw new InvalidFontException(\sprintf('GSUB lookup %d alternate coverage offset is invalid.', $lookupIndex));
        }

        $inputs = [];
        $sets = [];

        foreach ($coverage as $coverageIndex => $oldInputGlyphId) {
            $setOffset = $reader->uint16($offset + 6 + $coverageIndex * 2);

            if ($setOffset < $headerLength) {
                throw new InvalidFontException(\sprintf('GSUB lookup %d alternate-set offset is invalid.', $lookupIndex));
            }

            $set = $offset + $setOffset;
            $alternateCount = $reader->uint16($set);
            $alternates = [];

            for ($index = 0; $index < $alternateCount; ++$index) {
                $newGlyphId = self::remapGlyphId(
                    $reader->uint16($set + 2 + $index * 2),
                    $glyphIds,
                    $lookupIndex,
                );

                if (null !== $newGlyphId) {
                    $alternates[] = $newGlyphId;
                }
            }

            $newInputGlyphId = self::remapGlyphId($oldInputGlyphId, $glyphIds, $lookupIndex);

            if (null === $newInputGlyphId || [] === $alternates) {
                continue;
            }

            $inputs[] = $newInputGlyphId;
            $sets[] = self::uint16(\count($alternates))
                . implode('', array_map(self::uint16(...), $alternates));
        }

        return self::buildContextSets(1, $inputs, $sets);
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
            default => throw new InvalidFontException(\sprintf(
                'GSUB lookup %d type 5 format must be 1, 2, or 3.',
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
        $coverageOffset = $reader->uint16($offset + 2);
        $coverage = self::coverage($reader, $offset, $coverageOffset);
        $setCount = $reader->uint16($offset + 4);

        if ($setCount !== \count($coverage)) {
            throw new InvalidFontException(\sprintf('GSUB lookup %d sequence rule-set count does not match coverage.', $lookupIndex));
        }

        $headerLength = 6 + $setCount * 2;

        if ($coverageOffset < $headerLength) {
            throw new InvalidFontException(\sprintf('GSUB lookup %d context coverage offset is invalid.', $lookupIndex));
        }

        $inputs = [];
        $sets = [];

        foreach ($coverage as $coverageIndex => $oldInputGlyphId) {
            $setOffset = $reader->uint16($offset + 6 + $coverageIndex * 2);

            if (0 === $setOffset) {
                continue;
            }

            if ($setOffset < $headerLength) {
                throw new InvalidFontException(\sprintf('GSUB lookup %d sequence rule-set offset is invalid.', $lookupIndex));
            }

            $set = $offset + $setOffset;
            $rules = [];

            for ($index = 0, $count = $reader->uint16($set); $index < $count; ++$index) {
                $ruleOffset = $reader->uint16($set + 2 + $index * 2);
                $ruleHeaderLength = 2 + $count * 2;

                if ($ruleOffset < $ruleHeaderLength) {
                    throw new InvalidFontException(\sprintf('GSUB lookup %d sequence rule offset is invalid.', $lookupIndex));
                }

                $rule = self::compactGlyphContextRule(
                    $reader,
                    $set + $ruleOffset,
                    $lookupIndex,
                    $lookupCount,
                    $glyphIds,
                );

                if (null !== $rule) {
                    $rules[] = $rule;
                }
            }

            $newInputGlyphId = self::remapGlyphId($oldInputGlyphId, $glyphIds, $lookupIndex);

            if (null === $newInputGlyphId || [] === $rules) {
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
        $sourceCoverageOffset = $reader->uint16($offset + 2);
        $sourceClassOffset = $reader->uint16($offset + 4);
        $sourceCoverage = self::coverage($reader, $offset, $sourceCoverageOffset);
        $coverage = self::remapGlyphs(
            $sourceCoverage,
            $glyphIds,
            $lookupIndex,
        );
        $sourceClasses = ClassDefinitionTable::parse($reader, $offset, $sourceClassOffset);
        $classes = self::remapClasses($sourceClasses, $glyphIds);
        $setCount = $reader->uint16($offset + 6);
        $headerLength = 8 + $setCount * 2;
        $sets = [];

        if ($sourceCoverageOffset < $headerLength || $sourceClassOffset < $headerLength) {
            throw new InvalidFontException(\sprintf('GSUB lookup %d context coverage or class offset is invalid.', $lookupIndex));
        }

        foreach ($sourceClasses as $glyphId => $_class) {
            if ($glyphId >= $glyphIds->sourceGlyphCount()) {
                throw new InvalidFontException(\sprintf(
                    'GSUB lookup %d references glyph %d outside the source font.',
                    $lookupIndex,
                    $glyphId,
                ));
            }
        }

        foreach ($sourceCoverage as $glyphId) {
            if (($sourceClasses[$glyphId] ?? 0) >= $setCount) {
                throw new InvalidFontException(\sprintf('GSUB lookup %d class sequence rule-set count is invalid.', $lookupIndex));
            }
        }

        for ($index = 0; $index < $setCount; ++$index) {
            $setOffset = $reader->uint16($offset + 8 + $index * 2);

            if (0 === $setOffset) {
                $sets[] = null;
                continue;
            }

            if ($setOffset < $headerLength) {
                throw new InvalidFontException(\sprintf('GSUB lookup %d class sequence rule-set offset is invalid.', $lookupIndex));
            }

            $set = $offset + $setOffset;
            $rules = [];

            for ($ruleIndex = 0, $count = $reader->uint16($set); $ruleIndex < $count; ++$ruleIndex) {
                $ruleOffset = $reader->uint16($set + 2 + $ruleIndex * 2);

                if ($ruleOffset < 2 + $count * 2) {
                    throw new InvalidFontException(\sprintf('GSUB lookup %d class sequence rule offset is invalid.', $lookupIndex));
                }

                $rules[] = self::copyClassContextRule(
                    $reader,
                    $set + $ruleOffset,
                    $lookupIndex,
                    $lookupCount,
                );
            }

            $sets[] = self::offsetList($rules);
        }

        $data = '';
        $cursor = $headerLength;

        foreach ($sets as $set) {
            if (null === $set) {
                continue;
            }

            $data .= $set;
            $cursor += \strlen($set);
        }

        $coverageData = self::buildCoverage($coverage);
        $classData = ClassDefinitionTable::build($classes);
        $coverageOffset = $cursor;
        $classOffset = $coverageOffset + \strlen($coverageData);
        $header = self::uint16(2)
            . self::offset16($coverageOffset)
            . self::offset16($classOffset)
            . self::uint16($setCount);
        $cursor = $headerLength;

        foreach ($sets as $set) {
            if (null === $set) {
                $header .= self::uint16(0);
                continue;
            }

            $header .= self::offset16($cursor);
            $cursor += \strlen($set);
        }

        return $header . $data . $coverageData . $classData;
    }

    private static function compactContextFormatThree(
        BinaryReader $reader,
        int $offset,
        int $lookupIndex,
        int $lookupCount,
        GlyphIdMap $glyphIds,
    ): string {
        $glyphCount = $reader->uint16($offset + 2);
        $sequenceLookupCount = $reader->uint16($offset + 4);

        if (0 === $glyphCount) {
            throw new InvalidFontException(\sprintf('GSUB lookup %d coverage sequence must not be empty.', $lookupIndex));
        }

        $headerLength = 6 + $glyphCount * 2 + $sequenceLookupCount * 4;
        $coverages = [];

        for ($index = 0; $index < $glyphCount; ++$index) {
            $coverageOffset = $reader->uint16($offset + 6 + $index * 2);

            if ($coverageOffset < $headerLength) {
                throw new InvalidFontException(\sprintf('GSUB lookup %d context coverage offset is invalid.', $lookupIndex));
            }

            $coverages[] = self::buildCoverage(self::remapGlyphs(
                self::coverage($reader, $offset, $coverageOffset),
                $glyphIds,
                $lookupIndex,
            ));
        }

        $recordsOffset = $offset + 6 + $glyphCount * 2;
        $records = self::sequenceLookupRecords(
            $reader,
            $recordsOffset,
            $sequenceLookupCount,
            $lookupIndex,
            $lookupCount,
            $glyphCount,
        );
        $header = self::uint16(3)
            . self::uint16($glyphCount)
            . self::uint16($sequenceLookupCount);
        $coverageData = '';
        $coverageOffset = $headerLength;

        foreach ($coverages as $coverage) {
            $header .= self::offset16($coverageOffset);
            $coverageData .= $coverage;
            $coverageOffset += \strlen($coverage);
        }

        return $header . $records . $coverageData;
    }

    private static function compactReverseChainedSingleSubstitution(
        BinaryReader $reader,
        int $offset,
        int $lookupIndex,
        GlyphIdMap $glyphIds,
    ): string {
        if (1 !== $reader->uint16($offset)) {
            throw new InvalidFontException(\sprintf('GSUB lookup %d type 8 format must be 1.', $lookupIndex));
        }

        $inputCoverageOffset = $reader->uint16($offset + 2);
        $cursor = $offset + 4;
        $backtrackOffsets = self::readCoverageOffsets($reader, $cursor);
        $lookaheadOffsets = self::readCoverageOffsets($reader, $cursor);
        $glyphCount = $reader->uint16($cursor);
        $substitutesOffset = $cursor + 2;
        $headerLength = $substitutesOffset + $glyphCount * 2 - $offset;
        $inputCoverage = self::coverage($reader, $offset, $inputCoverageOffset);

        if ($glyphCount !== \count($inputCoverage)) {
            throw new InvalidFontException(\sprintf('GSUB lookup %d reverse-substitution count does not match coverage.', $lookupIndex));
        }

        $inputs = [];
        $outputs = [];

        foreach ($inputCoverage as $index => $oldInputGlyphId) {
            $newInputGlyphId = self::remapGlyphId($oldInputGlyphId, $glyphIds, $lookupIndex);
            $newOutputGlyphId = self::remapGlyphId(
                $reader->uint16($substitutesOffset + $index * 2),
                $glyphIds,
                $lookupIndex,
            );

            if (null === $newInputGlyphId || null === $newOutputGlyphId) {
                continue;
            }

            $inputs[] = $newInputGlyphId;
            $outputs[] = $newOutputGlyphId;
        }

        $sequences = [];

        foreach ([$backtrackOffsets, $lookaheadOffsets] as $coverageOffsets) {
            $sequence = [];

            foreach ($coverageOffsets as $coverageOffset) {
                if ($coverageOffset < $headerLength) {
                    throw new InvalidFontException(\sprintf('GSUB lookup %d reverse-context coverage offset is invalid.', $lookupIndex));
                }

                $sequence[] = self::buildCoverage(self::remapGlyphs(
                    self::coverage($reader, $offset, $coverageOffset),
                    $glyphIds,
                    $lookupIndex,
                ));
            }

            $sequences[] = $sequence;
        }

        if ($inputCoverageOffset < $headerLength) {
            throw new InvalidFontException(\sprintf('GSUB lookup %d reverse input coverage offset is invalid.', $lookupIndex));
        }

        $newInputCoverage = self::buildCoverage($inputs);
        $newHeaderLength = 4
            + 2 + \count($sequences[0]) * 2
            + 2 + \count($sequences[1]) * 2
            + 2 + \count($outputs) * 2;
        $coverageData = '';
        $coverageOffset = $newHeaderLength;
        $header = self::uint16(1) . self::offset16($coverageOffset);
        $coverageData .= $newInputCoverage;
        $coverageOffset += \strlen($newInputCoverage);

        foreach ($sequences as $sequence) {
            $header .= self::uint16(\count($sequence));

            foreach ($sequence as $coverage) {
                $header .= self::offset16($coverageOffset);
                $coverageData .= $coverage;
                $coverageOffset += \strlen($coverage);
            }
        }

        return $header
            . self::uint16(\count($outputs))
            . implode('', array_map(self::uint16(...), $outputs))
            . $coverageData;
    }

    private static function compactGlyphContextRule(
        BinaryReader $reader,
        int $offset,
        int $lookupIndex,
        int $lookupCount,
        GlyphIdMap $glyphIds,
    ): ?string {
        $glyphCount = $reader->uint16($offset);
        $sequenceLookupCount = $reader->uint16($offset + 2);

        if (0 === $glyphCount) {
            throw new InvalidFontException(\sprintf('GSUB lookup %d sequence rule glyph count must not be zero.', $lookupIndex));
        }

        $inputs = [];
        $retained = true;

        for ($index = 1; $index < $glyphCount; ++$index) {
            $newGlyphId = self::remapGlyphId(
                $reader->uint16($offset + 4 + ($index - 1) * 2),
                $glyphIds,
                $lookupIndex,
            );

            if (null === $newGlyphId) {
                $retained = false;
                continue;
            }

            $inputs[] = $newGlyphId;
        }

        $records = self::sequenceLookupRecords(
            $reader,
            $offset + 4 + ($glyphCount - 1) * 2,
            $sequenceLookupCount,
            $lookupIndex,
            $lookupCount,
            $glyphCount,
        );

        if (!$retained) {
            return null;
        }

        return self::uint16($glyphCount)
            . self::uint16($sequenceLookupCount)
            . implode('', array_map(self::uint16(...), $inputs))
            . $records;
    }

    private static function copyClassContextRule(
        BinaryReader $reader,
        int $offset,
        int $lookupIndex,
        int $lookupCount,
    ): string {
        $glyphCount = $reader->uint16($offset);
        $sequenceLookupCount = $reader->uint16($offset + 2);

        if (0 === $glyphCount) {
            throw new InvalidFontException(\sprintf('GSUB lookup %d class sequence rule glyph count must not be zero.', $lookupIndex));
        }

        $inputs = $reader->string($offset + 4, ($glyphCount - 1) * 2);
        $records = self::sequenceLookupRecords(
            $reader,
            $offset + 4 + ($glyphCount - 1) * 2,
            $sequenceLookupCount,
            $lookupIndex,
            $lookupCount,
            $glyphCount,
        );

        return self::uint16($glyphCount)
            . self::uint16($sequenceLookupCount)
            . $inputs
            . $records;
    }

    private static function sequenceLookupRecords(
        BinaryReader $reader,
        int $offset,
        int $count,
        int $lookupIndex,
        int $lookupCount,
        int $inputCount,
    ): string {
        for ($index = 0; $index < $count; ++$index) {
            $sequenceIndex = $reader->uint16($offset + $index * 4);
            $nestedLookupIndex = $reader->uint16($offset + $index * 4 + 2);

            if ($sequenceIndex >= $inputCount) {
                throw new InvalidFontException(\sprintf(
                    'GSUB lookup %d sequence record references missing input position %d.',
                    $lookupIndex,
                    $sequenceIndex,
                ));
            }

            if ($nestedLookupIndex >= $lookupCount) {
                throw new InvalidFontException(\sprintf(
                    'GSUB lookup %d sequence record references missing lookup %d.',
                    $lookupIndex,
                    $nestedLookupIndex,
                ));
            }
        }

        return $reader->string($offset, $count * 4);
    }

    /**
     * @return list<int>
     */
    private static function readCoverageOffsets(BinaryReader $reader, int &$cursor): array
    {
        $count = $reader->uint16($cursor);
        $offsets = [];

        for ($index = 0; $index < $count; ++$index) {
            $offsets[] = $reader->uint16($cursor + 2 + $index * 2);
        }

        $cursor += 2 + $count * 2;

        return $offsets;
    }

    /**
     * @param list<int>    $inputs
     * @param list<string> $sets
     */
    private static function buildContextSets(int $format, array $inputs, array $sets): string
    {
        $headerLength = 6 + \count($sets) * 2;
        $header = self::uint16($format);
        $data = '';
        $cursor = $headerLength;

        foreach ($sets as $set) {
            $data .= $set;
            $cursor += \strlen($set);
        }

        $coverage = self::buildCoverage($inputs);
        $coverageOffset = $cursor;
        $cursor = $headerLength;
        $header .= self::offset16($coverageOffset) . self::uint16(\count($sets));

        foreach ($sets as $set) {
            $header .= self::offset16($cursor);
            $cursor += \strlen($set);
        }

        return $header . $data . $coverage;
    }

    private static function compactMultipleSubstitution(
        BinaryReader $reader,
        int $offset,
        int $lookupIndex,
        GlyphIdMap $glyphIds,
    ): string {
        if (1 !== $reader->uint16($offset)) {
            throw new UnsupportedFontException(\sprintf('Compacting GSUB lookup %d type 2 requires format 1.', $lookupIndex));
        }

        $coverage = self::coverage($reader, $offset, $reader->uint16($offset + 2));
        $sequenceCount = $reader->uint16($offset + 4);

        if ($sequenceCount !== \count($coverage)) {
            throw new InvalidFontException(\sprintf('GSUB lookup %d sequence count does not match coverage.', $lookupIndex));
        }

        $inputs = [];
        $sequences = [];

        foreach ($coverage as $coverageIndex => $oldInputGlyphId) {
            $newInputGlyphId = $glyphIds->newId($oldInputGlyphId);
            $sequenceOffset = $reader->uint16($offset + 6 + $coverageIndex * 2);

            if (null === $newInputGlyphId) {
                continue;
            }

            if (0 === $sequenceOffset) {
                throw new InvalidFontException(\sprintf('GSUB lookup %d sequence offset must not be NULL.', $lookupIndex));
            }

            $sequence = $offset + $sequenceOffset;
            $glyphCount = $reader->uint16($sequence);
            $outputs = [];

            for ($index = 0; $index < $glyphCount; ++$index) {
                $newOutputGlyphId = $glyphIds->newId($reader->uint16($sequence + 2 + $index * 2));

                if (null === $newOutputGlyphId) {
                    continue 2;
                }

                $outputs[] = $newOutputGlyphId;
            }

            $inputs[] = $newInputGlyphId;
            $sequences[] = self::uint16(\count($outputs))
                . implode('', array_map(self::uint16(...), $outputs));
        }

        $headerLength = 6 + \count($sequences) * 2;
        $header = self::uint16(1);
        $data = '';
        $cursor = $headerLength;

        foreach ($sequences as $sequence) {
            $data .= $sequence;
            $cursor += \strlen($sequence);
        }

        $coverageData = self::buildCoverage($inputs);
        $coverageOffset = $cursor;
        $cursor = $headerLength;
        $header .= self::offset16($coverageOffset) . self::uint16(\count($sequences));

        foreach ($sequences as $sequence) {
            $header .= self::offset16($cursor);
            $cursor += \strlen($sequence);
        }

        return $header . $data . $coverageData;
    }

    private static function compactSingleSubstitution(
        BinaryReader $reader,
        int $offset,
        int $lookupIndex,
        GlyphIdMap $glyphIds,
    ): string {
        $format = $reader->uint16($offset);

        if (!\in_array($format, [1, 2], true)) {
            throw new UnsupportedFontException(\sprintf(
                'Compacting GSUB lookup %d type 1 format %d is not supported.',
                $lookupIndex,
                $format,
            ));
        }

        $coverage = self::coverage($reader, $offset, $reader->uint16($offset + 2));
        $substitutes = [];

        if (1 === $format) {
            $delta = $reader->int16($offset + 4);

            foreach ($coverage as $oldGlyphId) {
                $substitutes[] = ($oldGlyphId + $delta) & 0xFFFF;
            }
        } else {
            $glyphCount = $reader->uint16($offset + 4);

            if ($glyphCount !== \count($coverage)) {
                throw new InvalidFontException(\sprintf('GSUB lookup %d single-substitution count does not match coverage.', $lookupIndex));
            }

            for ($index = 0; $index < $glyphCount; ++$index) {
                $substitutes[] = $reader->uint16($offset + 6 + $index * 2);
            }
        }

        $inputs = [];
        $outputs = [];

        foreach ($coverage as $index => $oldInputGlyphId) {
            $newInputGlyphId = $glyphIds->newId($oldInputGlyphId);
            $newOutputGlyphId = $glyphIds->newId($substitutes[$index]);

            if (null === $newInputGlyphId || null === $newOutputGlyphId) {
                continue;
            }

            $inputs[] = $newInputGlyphId;
            $outputs[] = $newOutputGlyphId;
        }

        $coverageData = self::buildCoverage($inputs);
        $headerLength = 6 + \count($outputs) * 2;

        return self::uint16(2)
            . self::offset16($headerLength)
            . self::uint16(\count($outputs))
            . implode('', array_map(self::uint16(...), $outputs))
            . $coverageData;
    }

    private static function compactLigatureSubstitution(
        BinaryReader $reader,
        int $offset,
        int $lookupIndex,
        GlyphIdMap $glyphIds,
    ): string {
        if (1 !== $reader->uint16($offset)) {
            throw new UnsupportedFontException(\sprintf('Compacting GSUB lookup %d type 4 format is not supported.', $lookupIndex));
        }

        $coverage = self::coverage($reader, $offset, $reader->uint16($offset + 2));
        $setCount = $reader->uint16($offset + 4);

        if ($setCount !== \count($coverage)) {
            throw new InvalidFontException(\sprintf('GSUB lookup %d ligature-set count does not match coverage.', $lookupIndex));
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
            $ligatures = [];

            for ($index = 0, $count = $reader->uint16($set); $index < $count; ++$index) {
                $ligatureOffset = $reader->uint16($set + 2 + $index * 2);

                if (0 === $ligatureOffset) {
                    throw new InvalidFontException(\sprintf('GSUB lookup %d ligature offset must not be NULL.', $lookupIndex));
                }

                $ligature = $set + $ligatureOffset;
                $newLigatureGlyphId = $glyphIds->newId($reader->uint16($ligature));
                $componentCount = $reader->uint16($ligature + 2);
                $components = [];

                for ($componentIndex = 0; $componentIndex < $componentCount - 1; ++$componentIndex) {
                    $component = $glyphIds->newId($reader->uint16($ligature + 4 + $componentIndex * 2));

                    if (null === $component) {
                        continue 2;
                    }

                    $components[] = $component;
                }

                if (null !== $newLigatureGlyphId && $componentCount >= 2) {
                    $ligatures[] = self::uint16($newLigatureGlyphId)
                        . self::uint16($componentCount)
                        . implode('', array_map(self::uint16(...), $components));
                }
            }

            if ([] === $ligatures) {
                continue;
            }

            $inputs[] = $newInputGlyphId;
            $sets[] = self::offsetList($ligatures);
        }

        $headerLength = 6 + \count($sets) * 2;
        $header = self::uint16(1);
        $data = '';
        $cursor = $headerLength;

        foreach ($sets as $set) {
            $data .= $set;
            $cursor += \strlen($set);
        }

        $coverageData = self::buildCoverage($inputs);
        $coverageOffset = $cursor;
        $cursor = $headerLength;
        $header .= self::offset16($coverageOffset) . self::uint16(\count($sets));

        foreach ($sets as $set) {
            $header .= self::offset16($cursor);
            $cursor += \strlen($set);
        }

        return $header . $data . $coverageData;
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
                'Compacting GSUB lookup %d type 6 uses an unsupported format.',
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
        $coverage = self::coverage($reader, $offset, $reader->uint16($offset + 2));
        $setCount = $reader->uint16($offset + 4);

        if ($setCount !== \count($coverage)) {
            throw new InvalidFontException(\sprintf('GSUB lookup %d chained rule-set count does not match coverage.', $lookupIndex));
        }

        $inputs = [];
        $sets = [];

        foreach ($coverage as $coverageIndex => $oldInputGlyphId) {
            $newInputGlyphId = $glyphIds->newId($oldInputGlyphId);
            $setOffset = $reader->uint16($offset + 6 + $coverageIndex * 2);

            if (0 === $setOffset) {
                continue;
            }

            $set = $offset + $setOffset;
            $rules = [];

            for ($index = 0, $count = $reader->uint16($set); $index < $count; ++$index) {
                $ruleOffset = $reader->uint16($set + 2 + $index * 2);

                if (0 === $ruleOffset) {
                    throw new InvalidFontException(\sprintf('GSUB lookup %d chained rule offset must not be NULL.', $lookupIndex));
                }

                $rule = self::compactGlyphChainedRule(
                    $reader,
                    $set + $ruleOffset,
                    $lookupIndex,
                    $lookupCount,
                    $glyphIds,
                );

                if (null !== $rule) {
                    $rules[] = $rule;
                }
            }

            if (null === $newInputGlyphId || [] === $rules) {
                continue;
            }

            $inputs[] = $newInputGlyphId;
            $sets[] = self::offsetList($rules);
        }

        return self::buildChainedContextSets(1, $inputs, $sets);
    }

    private static function compactChainedContextFormatTwo(
        BinaryReader $reader,
        int $offset,
        int $lookupIndex,
        int $lookupCount,
        GlyphIdMap $glyphIds,
    ): string {
        $sourceCoverage = self::coverage($reader, $offset, $reader->uint16($offset + 2));
        $coverage = self::remapGlyphs(
            $sourceCoverage,
            $glyphIds,
        );
        $backtrackClassOffset = $reader->uint16($offset + 4);
        $backtrackClasses = self::remapClasses(
            0 === $backtrackClassOffset
                ? []
                : ClassDefinitionTable::parse($reader, $offset, $backtrackClassOffset),
            $glyphIds,
        );
        $sourceInputClasses = ClassDefinitionTable::parse($reader, $offset, $reader->uint16($offset + 6));
        $inputClasses = self::remapClasses($sourceInputClasses, $glyphIds);
        $lookaheadClassOffset = $reader->uint16($offset + 8);
        $lookaheadClasses = self::remapClasses(
            0 === $lookaheadClassOffset
                ? []
                : ClassDefinitionTable::parse($reader, $offset, $lookaheadClassOffset),
            $glyphIds,
        );
        $setCount = $reader->uint16($offset + 10);
        $sets = [];

        foreach ($sourceCoverage as $glyphId) {
            if (($sourceInputClasses[$glyphId] ?? 0) >= $setCount) {
                throw new InvalidFontException(\sprintf('GSUB lookup %d chained class rule-set count is invalid.', $lookupIndex));
            }
        }

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
                    throw new InvalidFontException(\sprintf('GSUB lookup %d chained class rule offset must not be NULL.', $lookupIndex));
                }

                $rules[] = self::copyClassChainedRule(
                    $reader,
                    $set + $ruleOffset,
                    $lookupIndex,
                    $lookupCount,
                );
            }

            $sets[] = self::offsetList($rules);
        }

        $headerLength = 12 + $setCount * 2;
        $header = self::uint16(2);
        $data = '';
        $cursor = $headerLength;

        foreach ($sets as $set) {
            if (null === $set) {
                continue;
            }

            $data .= $set;
            $cursor += \strlen($set);
        }

        $coverageData = self::buildCoverage($coverage);
        $backtrackData = ClassDefinitionTable::build($backtrackClasses);
        $inputData = ClassDefinitionTable::build($inputClasses);
        $lookaheadData = ClassDefinitionTable::build($lookaheadClasses);
        $coverageOffset = $cursor;
        $backtrackOffset = $coverageOffset + \strlen($coverageData);
        $inputOffset = $backtrackOffset + \strlen($backtrackData);
        $lookaheadOffset = $inputOffset + \strlen($inputData);
        $header .= self::offset16($coverageOffset)
            . self::offset16($backtrackOffset)
            . self::offset16($inputOffset)
            . self::offset16($lookaheadOffset)
            . self::uint16($setCount);
        $cursor = $headerLength;

        foreach ($sets as $set) {
            if (null === $set) {
                $header .= self::uint16(0);
                continue;
            }

            $header .= self::offset16($cursor);
            $cursor += \strlen($set);
        }

        return $header . $data . $coverageData . $backtrackData . $inputData . $lookaheadData;
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
        $lookahead = self::coverageSequence($reader, $offset, $cursor, $glyphIds);
        $substitutionCount = $reader->uint16($cursor);

        if ([] === $inputs) {
            throw new InvalidFontException(\sprintf('GSUB lookup %d chained coverage input sequence must not be empty.', $lookupIndex));
        }

        $records = self::sequenceLookupRecords(
            $reader,
            $cursor + 2,
            $substitutionCount,
            $lookupIndex,
            $lookupCount,
            \count($inputs),
        );
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

        return $header . self::uint16($substitutionCount) . $records . $coverageData;
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
        $retained = null !== $backtrack;

        $inputCount = $reader->uint16($cursor);

        if (0 === $inputCount) {
            throw new InvalidFontException('GSUB chained glyph rule input count must not be zero.');
        }

        $cursor += 2;
        $inputs = [];

        for ($index = 1; $index < $inputCount; ++$index) {
            $glyphId = $glyphIds->newId($reader->uint16($cursor));
            $cursor += 2;

            if (null === $glyphId) {
                $retained = false;
                continue;
            }

            $inputs[] = $glyphId;
        }

        $lookahead = self::remapRuleGlyphs($reader, $cursor, $glyphIds);
        $retained = $retained && null !== $lookahead;

        $substitutionCount = $reader->uint16($cursor);
        $records = self::sequenceLookupRecords(
            $reader,
            $cursor + 2,
            $substitutionCount,
            $lookupIndex,
            $lookupCount,
            $inputCount,
        );

        if (!$retained || null === $backtrack || null === $lookahead) {
            return null;
        }

        return self::uint16(\count($backtrack))
            . implode('', array_map(self::uint16(...), $backtrack))
            . self::uint16($inputCount)
            . implode('', array_map(self::uint16(...), $inputs))
            . self::uint16(\count($lookahead))
            . implode('', array_map(self::uint16(...), $lookahead))
            . self::uint16($substitutionCount)
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
        $retained = true;

        for ($index = 0; $index < $count; ++$index) {
            $glyphId = $glyphIds->newId($reader->uint16($cursor));
            $cursor += 2;

            if (null === $glyphId) {
                $retained = false;
                continue;
            }

            $glyphs[] = $glyphId;
        }

        return $retained ? $glyphs : null;
    }

    private static function copyClassChainedRule(
        BinaryReader $reader,
        int $offset,
        int $lookupIndex,
        int $lookupCount,
    ): string {
        $cursor = $offset;
        $backtrackCount = $reader->uint16($cursor);
        $cursor += 2 + $backtrackCount * 2;
        $inputCount = $reader->uint16($cursor);

        if (0 === $inputCount) {
            throw new InvalidFontException(\sprintf('GSUB lookup %d chained class input count must not be zero.', $lookupIndex));
        }

        $cursor += 2 + ($inputCount - 1) * 2;
        $lookaheadCount = $reader->uint16($cursor);
        $cursor += 2 + $lookaheadCount * 2;
        $substitutionCount = $reader->uint16($cursor);
        self::sequenceLookupRecords(
            $reader,
            $cursor + 2,
            $substitutionCount,
            $lookupIndex,
            $lookupCount,
            $inputCount,
        );
        $cursor += 2 + $substitutionCount * 4;

        return $reader->string($offset, $cursor - $offset);
    }

    /**
     * @param list<int>    $inputs
     * @param list<string> $sets
     */
    private static function buildChainedContextSets(int $format, array $inputs, array $sets): string
    {
        $headerLength = 6 + \count($sets) * 2;
        $header = self::uint16($format);
        $data = '';
        $cursor = $headerLength;

        foreach ($sets as $set) {
            $data .= $set;
            $cursor += \strlen($set);
        }

        $coverage = self::buildCoverage($inputs);
        $coverageOffset = $cursor;
        $cursor = $headerLength;
        $header .= self::offset16($coverageOffset) . self::uint16(\count($sets));

        foreach ($sets as $set) {
            $header .= self::offset16($cursor);
            $cursor += \strlen($set);
        }

        return $header . $data . $coverage;
    }

    /**
     * @param list<int> $glyphs
     *
     * @return list<int>
     */
    private static function remapGlyphs(array $glyphs, GlyphIdMap $glyphIds, ?int $lookupIndex = null): array
    {
        $remapped = [];

        foreach ($glyphs as $oldGlyphId) {
            $newGlyphId = null === $lookupIndex
                ? $glyphIds->newId($oldGlyphId)
                : self::remapGlyphId($oldGlyphId, $glyphIds, $lookupIndex);

            if (null !== $newGlyphId) {
                $remapped[] = $newGlyphId;
            }
        }

        return $remapped;
    }

    private static function remapGlyphId(int $oldGlyphId, GlyphIdMap $glyphIds, int $lookupIndex): ?int
    {
        if ($oldGlyphId >= $glyphIds->sourceGlyphCount()) {
            throw new InvalidFontException(\sprintf(
                'GSUB lookup %d references glyph %d outside the source font.',
                $lookupIndex,
                $oldGlyphId,
            ));
        }

        return $glyphIds->newId($oldGlyphId);
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
     * @return list<string>
     */
    private static function coverageSequence(
        BinaryReader $reader,
        int $subtableOffset,
        int &$cursor,
        GlyphIdMap $glyphIds,
    ): array {
        $count = $reader->uint16($cursor);
        $coverages = [];

        for ($index = 0; $index < $count; ++$index) {
            $coverageOffset = $reader->uint16($cursor + 2 + $index * 2);
            $glyphs = [];

            foreach (self::coverage($reader, $subtableOffset, $coverageOffset) as $oldGlyphId) {
                $newGlyphId = $glyphIds->newId($oldGlyphId);

                if (null !== $newGlyphId) {
                    $glyphs[] = $newGlyphId;
                }
            }

            $coverages[] = self::buildCoverage($glyphs);
        }

        $cursor += 2 + $count * 2;

        return $coverages;
    }

    /**
     * @return list<int>
     */
    private static function coverage(BinaryReader $reader, int $baseOffset, int $relativeOffset): array
    {
        if (0 === $relativeOffset) {
            throw new InvalidFontException('GSUB coverage offset must not be NULL.');
        }

        $offset = $baseOffset + $relativeOffset;
        $format = $reader->uint16($offset);

        if (1 === $format) {
            $glyphs = [];

            for ($index = 0, $count = $reader->uint16($offset + 2); $index < $count; ++$index) {
                $glyphs[] = $reader->uint16($offset + 4 + $index * 2);
            }

            return $glyphs;
        }

        if (2 !== $format) {
            throw new UnsupportedFontException(\sprintf('GSUB coverage format %d is not supported.', $format));
        }

        $glyphsByIndex = [];

        for ($rangeIndex = 0, $count = $reader->uint16($offset + 2); $rangeIndex < $count; ++$rangeIndex) {
            $rangeOffset = $offset + 4 + $rangeIndex * 6;
            $start = $reader->uint16($rangeOffset);
            $end = $reader->uint16($rangeOffset + 2);
            $coverageIndex = $reader->uint16($rangeOffset + 4);

            if ($end < $start) {
                throw new InvalidFontException('GSUB coverage range is reversed.');
            }

            for ($glyphId = $start; $glyphId <= $end; ++$glyphId) {
                $glyphsByIndex[$coverageIndex++] = $glyphId;
            }
        }

        ksort($glyphsByIndex, \SORT_NUMERIC);

        return array_values($glyphsByIndex);
    }

    /**
     * @param list<int> $glyphs
     */
    private static function buildCoverage(array $glyphs): string
    {
        sort($glyphs, \SORT_NUMERIC);

        return self::uint16(1)
            . self::uint16(\count($glyphs))
            . implode('', array_map(self::uint16(...), $glyphs));
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
            throw new UnsupportedFontException('Compacted GSUB data exceeds a 16-bit OpenType offset.');
        }

        return self::uint16($value);
    }

    private static function uint16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }

}
