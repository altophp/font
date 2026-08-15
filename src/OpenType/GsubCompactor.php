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

/**
 * @internal
 */
final readonly class GsubCompactor
{
    public static function compact(string $gsub, GlyphIdMap $glyphIds): string
    {
        $reader = new BinaryReader($gsub, 'GSUB compaction source');

        if (1 !== $reader->uint16(0) || 0 !== $reader->uint16(2)) {
            throw new UnsupportedFontException('Compact GSUB output currently supports version 1.0 only.');
        }

        $scriptListOffset = $reader->uint16(4);
        $featureListOffset = $reader->uint16(6);
        $lookupListOffset = $reader->uint16(8);

        if ($scriptListOffset < 10
            || $featureListOffset <= $scriptListOffset
            || $lookupListOffset <= $featureListOffset
            || $lookupListOffset >= $reader->length()
        ) {
            throw new UnsupportedFontException('Compact GSUB output requires ordered script, feature, and lookup lists.');
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
                throw new InvalidFontException(\sprintf('GSUB lookup %d offset must not be NULL.', $index));
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
            throw new InvalidFontException(\sprintf('GSUB lookup %d must contain at least one subtable.', $lookupIndex));
        }

        if (7 === $lookupType) {
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
                throw new InvalidFontException(\sprintf('GSUB lookup %d subtable offset must not be NULL.', $lookupIndex));
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
                $glyphIds,
            );
        }

        $headerLength = 6 + $subtableCount * 2 + (0 !== ($lookupFlag & 0x0010) ? 2 : 0);
        $header = self::uint16(7) . self::uint16($lookupFlag) . self::uint16($subtableCount);
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
            1 => self::compactSingleSubstitution($reader, $offset, $lookupIndex, $glyphIds),
            2 => self::compactMultipleSubstitution($reader, $offset, $lookupIndex, $glyphIds),
            4 => self::compactLigatureSubstitution($reader, $offset, $lookupIndex, $glyphIds),
            6 => self::compactChainedContext($reader, $offset, $lookupIndex, $glyphIds),
            default => throw new UnsupportedFontException(\sprintf(
                'Compacting GSUB lookup %d type %d is not supported yet.',
                $lookupIndex,
                $lookupType,
            )),
        };
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
        GlyphIdMap $glyphIds,
    ): string {
        return match ($reader->uint16($offset)) {
            1 => self::compactChainedContextFormatOne($reader, $offset, $lookupIndex, $glyphIds),
            2 => self::compactChainedContextFormatTwo($reader, $offset, $lookupIndex, $glyphIds),
            3 => self::compactChainedContextFormatThree($reader, $offset, $glyphIds),
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

            if (null === $newInputGlyphId || 0 === $setOffset) {
                continue;
            }

            $set = $offset + $setOffset;
            $rules = [];

            for ($index = 0, $count = $reader->uint16($set); $index < $count; ++$index) {
                $ruleOffset = $reader->uint16($set + 2 + $index * 2);

                if (0 === $ruleOffset) {
                    throw new InvalidFontException(\sprintf('GSUB lookup %d chained rule offset must not be NULL.', $lookupIndex));
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

        return self::buildChainedContextSets(1, $inputs, $sets);
    }

    private static function compactChainedContextFormatTwo(
        BinaryReader $reader,
        int $offset,
        int $lookupIndex,
        GlyphIdMap $glyphIds,
    ): string {
        $coverage = self::remapGlyphs(
            self::coverage($reader, $offset, $reader->uint16($offset + 2)),
            $glyphIds,
        );
        $backtrackClasses = self::remapClasses(
            ClassDefinitionTable::parse($reader, $offset, $reader->uint16($offset + 4)),
            $glyphIds,
        );
        $inputClasses = self::remapClasses(
            ClassDefinitionTable::parse($reader, $offset, $reader->uint16($offset + 6)),
            $glyphIds,
        );
        $lookaheadClasses = self::remapClasses(
            ClassDefinitionTable::parse($reader, $offset, $reader->uint16($offset + 8)),
            $glyphIds,
        );
        $setCount = $reader->uint16($offset + 10);
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
                    throw new InvalidFontException(\sprintf('GSUB lookup %d chained class rule offset must not be NULL.', $lookupIndex));
                }

                $rules[] = self::copyClassChainedRule($reader, $set + $ruleOffset, $lookupIndex);
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
        GlyphIdMap $glyphIds,
    ): string {

        $cursor = $offset + 2;
        $backtrack = self::coverageSequence($reader, $offset, $cursor, $glyphIds);
        $inputs = self::coverageSequence($reader, $offset, $cursor, $glyphIds);
        $lookahead = self::coverageSequence($reader, $offset, $cursor, $glyphIds);
        $substitutionCount = $reader->uint16($cursor);
        $records = $reader->string($cursor + 2, $substitutionCount * 4);
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
        GlyphIdMap $glyphIds,
    ): ?string {
        $cursor = $offset;
        $backtrack = self::remapRuleGlyphs($reader, $cursor, $glyphIds);

        if (null === $backtrack) {
            return null;
        }

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
                return null;
            }

            $inputs[] = $glyphId;
        }

        $lookahead = self::remapRuleGlyphs($reader, $cursor, $glyphIds);

        if (null === $lookahead) {
            return null;
        }

        $substitutionCount = $reader->uint16($cursor);
        $records = $reader->string($cursor + 2, $substitutionCount * 4);

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

    private static function copyClassChainedRule(BinaryReader $reader, int $offset, int $lookupIndex): string
    {
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
    private static function remapGlyphs(array $glyphs, GlyphIdMap $glyphIds): array
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

    private static function uint32(int $value): string
    {
        return pack('N', $value & 0xFFFFFFFF);
    }
}
