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

namespace Alto\Font\OpenType\Table;

use Alto\Font\Binary\BinaryReader;
use Alto\Font\Exception\InvalidFontException;
use Alto\Font\Exception\UnsupportedFontException;
use Alto\Font\OpenType\Layout\ClassDefinitionTable;
use Alto\Font\OpenType\Layout\CoverageTable;
use Alto\Font\OpenType\Layout\LookupHeader;

/**
 * Builds a conservative glyph substitution graph.
 *
 * Glyph closure is computed without shaping text, retaining every supported
 * output that a rooted or satisfied contextual lookup may produce.
 *
 * @author Simon André <smn.andre@gmail.com>
 *
 * @internal
 */
final readonly class GsubTable
{
    /**
     * @param list<array<int, array<int, true>>> $lookupSubstitutions
     * @param list<GsubContextRule>              $contextRules
     * @param list<list<GsubContextRule>>        $lookupContextRules
     * @param array<int, true>                   $rootLookupIndexes
     */
    private function __construct(
        private array $lookupSubstitutions,
        private array $contextRules,
        private array $lookupContextRules,
        private array $rootLookupIndexes,
    ) {}

    public static function parse(BinaryReader $reader): self
    {
        $majorVersion = $reader->uint16(0);
        $minorVersion = $reader->uint16(2);

        if (1 !== $majorVersion || !\in_array($minorVersion, [0, 1], true)) {
            throw new UnsupportedFontException(\sprintf('GSUB table version %d.%d is not supported.', $majorVersion, $minorVersion));
        }

        $lookupListOffset = $reader->uint16(8);
        $featureListOffset = $reader->uint16(6);

        if (0 === $featureListOffset || 0 === $lookupListOffset) {
            throw new InvalidFontException('GSUB feature and lookup list offsets must not be NULL.');
        }

        $lookupCount = $reader->uint16($lookupListOffset);
        $rootLookupIndexes = self::featureLookupIndexes($reader, $featureListOffset, $lookupCount);

        if ($lookupCount > 0 && 1 === $minorVersion && 0 !== $reader->uint32(10)) {
            $rootLookupIndexes = array_fill_keys(range(0, $lookupCount - 1), true);
        }

        $lookupSubstitutions = [];
        $contextRules = [];
        $lookupContextRules = [];

        for ($lookupIndex = 0; $lookupIndex < $lookupCount; ++$lookupIndex) {
            $lookupOffset = $reader->uint16($lookupListOffset + 2 + $lookupIndex * 2);

            if (0 === $lookupOffset) {
                throw new InvalidFontException(\sprintf('GSUB lookup %d offset must not be NULL.', $lookupIndex));
            }

            $firstRuleIndex = \count($contextRules);
            $lookupSubstitutions[] = self::parseLookup(
                $reader,
                $lookupListOffset + $lookupOffset,
                $lookupIndex,
                $contextRules,
            );
            $lookupContextRules[] = array_slice($contextRules, $firstRuleIndex);
        }

        foreach ($contextRules as $rule) {
            foreach ($rule->lookupRecords as $record) {
                if ($record['lookupIndex'] >= $lookupCount) {
                    throw new InvalidFontException(\sprintf(
                        'GSUB contextual rule references lookup index %d outside lookup count %d.',
                        $record['lookupIndex'],
                        $lookupCount,
                    ));
                }
            }
        }

        return new self(
            $lookupSubstitutions,
            $contextRules,
            $lookupContextRules,
            $rootLookupIndexes,
        );
    }

    /**
     * @param array<int, true> $glyphs
     *
     * @return array<int, true>
     */
    public function glyphClosure(array $glyphs, int $glyphCount): array
    {
        foreach ($this->lookupSubstitutions as $lookup) {
            foreach ($lookup as $inputGlyphId => $outputGlyphs) {
                self::assertGlyphId($inputGlyphId, $glyphCount);

                foreach ($outputGlyphs as $outputGlyphId => $_retained) {
                    self::assertGlyphId($outputGlyphId, $glyphCount);
                }
            }
        }

        foreach ($this->contextRules as $rule) {
            foreach ($rule->conditions as $condition) {
                $condition->assertValid($glyphCount);
            }
        }

        $queue = array_keys($glyphs);
        $cursor = 0;
        $activeLookupIndexes = $this->rootLookupIndexes;

        while (true) {
            while ($cursor < \count($queue)) {
                $glyphId = $queue[$cursor++];
                self::assertGlyphId($glyphId, $glyphCount);

                foreach ($activeLookupIndexes as $lookupIndex => $_active) {
                    foreach ($this->lookupSubstitutions[$lookupIndex][$glyphId] ?? [] as $outputGlyphId => $_retained) {
                        self::retain($outputGlyphId, $glyphs, $queue);
                    }
                }
            }

            $activated = false;

            foreach ($activeLookupIndexes as $lookupIndex => $_active) {
                foreach ($this->lookupContextRules[$lookupIndex] as $rule) {
                    foreach ($rule->conditions as $condition) {
                        if (!$condition->intersects($glyphs)) {
                            continue 2;
                        }
                    }

                    foreach ($rule->lookupRecords as $record) {
                        $referencedLookupIndex = $record['lookupIndex'];

                        if (!isset($activeLookupIndexes[$referencedLookupIndex])) {
                            $activeLookupIndexes[$referencedLookupIndex] = true;
                            $activated = true;
                        }
                    }
                }
            }

            if ($activated) {
                $cursor = 0;
                continue;
            }

            if ($cursor >= \count($queue)) {
                break;
            }
        }

        return $glyphs;
    }

    /**
     * @param list<GsubContextRule> $contextRules
     *
     * @return array<int, array<int, true>>
     */
    private static function parseLookup(BinaryReader $reader, int $offset, int $lookupIndex, array &$contextRules): array
    {
        $header = LookupHeader::parse($reader, $offset, 'GSUB', $lookupIndex);
        $lookupType = $header->type;

        if ($lookupType < 1 || $lookupType > 8) {
            throw new UnsupportedFontException(\sprintf('GSUB lookup %d uses unsupported type %d.', $lookupIndex, $lookupType));
        }

        $substitutions = [];

        foreach ($header->subtableOffsets as $subtableOffset) {
            self::parseSubtable($reader, $lookupType, $offset + $subtableOffset, $lookupIndex, $substitutions, $contextRules);
        }

        return $substitutions;
    }

    /**
     * @param array<int, array<int, true>> $substitutions
     * @param list<GsubContextRule>        $contextRules
     */
    private static function parseSubtable(
        BinaryReader $reader,
        int $lookupType,
        int $offset,
        int $lookupIndex,
        array &$substitutions,
        array &$contextRules,
    ): void {
        switch ($lookupType) {
            case 1:
                self::parseSingleSubstitution($reader, $offset, $lookupIndex, $substitutions);
                break;
            case 2:
                self::parseMultipleSubstitution($reader, $offset, $lookupIndex, $substitutions);
                break;
            case 3:
                self::parseAlternateSubstitution($reader, $offset, $lookupIndex, $substitutions);
                break;
            case 4:
                self::parseLigatureSubstitution($reader, $offset, $lookupIndex, $substitutions);
                break;
            case 5:
                self::parseContextSubstitution($reader, $offset, $lookupIndex, $contextRules);
                break;
            case 6:
                self::parseChainedContextSubstitution($reader, $offset, $lookupIndex, $contextRules);
                break;
            case 7:
                self::parseExtensionSubstitution($reader, $offset, $lookupIndex, $substitutions, $contextRules);
                break;
            case 8:
                self::parseReverseSubstitution($reader, $offset, $lookupIndex, $substitutions);
                break;
            default:
                throw new UnsupportedFontException(\sprintf('GSUB lookup %d uses unsupported type %d.', $lookupIndex, $lookupType));
        }
    }

    /**
     * @param array<int, array<int, true>> $substitutions
     */
    private static function parseSingleSubstitution(BinaryReader $reader, int $offset, int $lookupIndex, array &$substitutions): void
    {
        $format = $reader->uint16($offset);

        if (!\in_array($format, [1, 2], true)) {
            throw self::unsupportedFormat($lookupIndex, 1, $format);
        }

        $coverage = self::coverage($reader, $offset, $reader->uint16($offset + 2));

        if (1 === $format) {
            $delta = $reader->int16($offset + 4);

            foreach ($coverage as $glyphId) {
                self::addSubstitutions($substitutions, $glyphId, [($glyphId + $delta) & 0xFFFF]);
            }

            return;
        }

        $glyphCount = $reader->uint16($offset + 4);
        self::assertMatchingCount($lookupIndex, 'single substitution', \count($coverage), $glyphCount);

        foreach ($coverage as $coverageIndex => $glyphId) {
            self::addSubstitutions($substitutions, $glyphId, [$reader->uint16($offset + 6 + $coverageIndex * 2)]);
        }
    }

    /**
     * @param array<int, array<int, true>> $substitutions
     */
    private static function parseMultipleSubstitution(BinaryReader $reader, int $offset, int $lookupIndex, array &$substitutions): void
    {
        $format = $reader->uint16($offset);

        if (1 !== $format) {
            throw self::unsupportedFormat($lookupIndex, 2, $format);
        }

        $coverage = self::coverage($reader, $offset, $reader->uint16($offset + 2));
        $sequenceCount = $reader->uint16($offset + 4);
        self::assertMatchingCount($lookupIndex, 'multiple substitution', \count($coverage), $sequenceCount);

        foreach ($coverage as $coverageIndex => $glyphId) {
            $sequenceOffset = $reader->uint16($offset + 6 + $coverageIndex * 2);

            if (0 === $sequenceOffset) {
                throw new InvalidFontException(\sprintf('GSUB lookup %d multiple-substitution sequence offset must not be NULL.', $lookupIndex));
            }

            $sequence = $offset + $sequenceOffset;
            $glyphCount = $reader->uint16($sequence);
            $outputs = [];

            for ($index = 0; $index < $glyphCount; ++$index) {
                $outputs[] = $reader->uint16($sequence + 2 + $index * 2);
            }

            self::addSubstitutions($substitutions, $glyphId, $outputs);
        }
    }

    /**
     * @param array<int, array<int, true>> $substitutions
     */
    private static function parseAlternateSubstitution(BinaryReader $reader, int $offset, int $lookupIndex, array &$substitutions): void
    {
        $format = $reader->uint16($offset);

        if (1 !== $format) {
            throw self::unsupportedFormat($lookupIndex, 3, $format);
        }

        $coverage = self::coverage($reader, $offset, $reader->uint16($offset + 2));
        $setCount = $reader->uint16($offset + 4);
        self::assertMatchingCount($lookupIndex, 'alternate substitution', \count($coverage), $setCount);

        foreach ($coverage as $coverageIndex => $glyphId) {
            $setOffset = $reader->uint16($offset + 6 + $coverageIndex * 2);

            if (0 === $setOffset) {
                throw new InvalidFontException(\sprintf('GSUB lookup %d alternate-set offset must not be NULL.', $lookupIndex));
            }

            $set = $offset + $setOffset;
            $glyphCount = $reader->uint16($set);
            $outputs = [];

            for ($index = 0; $index < $glyphCount; ++$index) {
                $outputs[] = $reader->uint16($set + 2 + $index * 2);
            }

            self::addSubstitutions($substitutions, $glyphId, $outputs);
        }
    }

    /**
     * @param array<int, array<int, true>> $substitutions
     */
    private static function parseLigatureSubstitution(BinaryReader $reader, int $offset, int $lookupIndex, array &$substitutions): void
    {
        $format = $reader->uint16($offset);

        if (1 !== $format) {
            throw self::unsupportedFormat($lookupIndex, 4, $format);
        }

        $coverage = self::coverage($reader, $offset, $reader->uint16($offset + 2));
        $setCount = $reader->uint16($offset + 4);
        self::assertMatchingCount($lookupIndex, 'ligature substitution', \count($coverage), $setCount);

        foreach ($coverage as $coverageIndex => $glyphId) {
            $setOffset = $reader->uint16($offset + 6 + $coverageIndex * 2);

            if (0 === $setOffset) {
                throw new InvalidFontException(\sprintf('GSUB lookup %d ligature-set offset must not be NULL.', $lookupIndex));
            }

            $set = $offset + $setOffset;
            $ligatureCount = $reader->uint16($set);
            $outputs = [];

            for ($ligatureIndex = 0; $ligatureIndex < $ligatureCount; ++$ligatureIndex) {
                $ligatureOffset = $reader->uint16($set + 2 + $ligatureIndex * 2);

                if (0 === $ligatureOffset) {
                    throw new InvalidFontException(\sprintf('GSUB lookup %d ligature offset must not be NULL.', $lookupIndex));
                }

                $ligature = $set + $ligatureOffset;
                $outputs[] = $reader->uint16($ligature);
                $componentCount = $reader->uint16($ligature + 2);

                if ($componentCount < 2) {
                    throw new InvalidFontException(\sprintf('GSUB lookup %d ligature must contain at least two components.', $lookupIndex));
                }

                for ($componentIndex = 0; $componentIndex < $componentCount - 1; ++$componentIndex) {
                    $outputs[] = $reader->uint16($ligature + 4 + $componentIndex * 2);
                }
            }

            self::addSubstitutions($substitutions, $glyphId, $outputs);
        }
    }

    /**
     * @param list<GsubContextRule> $contextRules
     */
    private static function parseContextSubstitution(BinaryReader $reader, int $offset, int $lookupIndex, array &$contextRules): void
    {
        $format = $reader->uint16($offset);

        match ($format) {
            1 => self::parseContextFormatOne($reader, $offset, $lookupIndex, $contextRules),
            2 => self::parseContextFormatTwo($reader, $offset, $lookupIndex, $contextRules),
            3 => self::parseContextFormatThree($reader, $offset, $lookupIndex, $contextRules),
            default => throw self::unsupportedFormat($lookupIndex, 5, $format),
        };
    }

    /**
     * @param list<GsubContextRule> $contextRules
     */
    private static function parseContextFormatOne(BinaryReader $reader, int $offset, int $lookupIndex, array &$contextRules): void
    {
        $coverage = self::coverage($reader, $offset, $reader->uint16($offset + 2));
        $setCount = $reader->uint16($offset + 4);
        self::assertMatchingCount($lookupIndex, 'context rule-set', \count($coverage), $setCount);

        foreach ($coverage as $coverageIndex => $firstGlyphId) {
            $setOffset = $reader->uint16($offset + 6 + $coverageIndex * 2);

            if (0 === $setOffset) {
                continue;
            }

            $set = $offset + $setOffset;
            $ruleCount = $reader->uint16($set);

            for ($ruleIndex = 0; $ruleIndex < $ruleCount; ++$ruleIndex) {
                $ruleOffset = $reader->uint16($set + 2 + $ruleIndex * 2);

                if (0 === $ruleOffset) {
                    throw new InvalidFontException(\sprintf('GSUB lookup %d context-rule offset must not be NULL.', $lookupIndex));
                }

                $rule = $set + $ruleOffset;
                $inputCount = $reader->uint16($rule);
                $substitutionCount = $reader->uint16($rule + 2);

                if ($inputCount < 1) {
                    throw new InvalidFontException(\sprintf('GSUB lookup %d context rule must contain an input glyph.', $lookupIndex));
                }

                $inputs = [GsubGlyphSet::explicit([$firstGlyphId])];
                $cursor = $rule + 4;

                for ($inputIndex = 1; $inputIndex < $inputCount; ++$inputIndex) {
                    $inputs[] = GsubGlyphSet::explicit([$reader->uint16($cursor)]);
                    $cursor += 2;
                }

                $contextRules[] = new GsubContextRule(
                    $inputs,
                    $inputs,
                    self::lookupRecords($reader, $cursor, $substitutionCount, $inputCount, $lookupIndex),
                );
            }
        }
    }

    /**
     * @param list<GsubContextRule> $contextRules
     */
    private static function parseContextFormatTwo(BinaryReader $reader, int $offset, int $lookupIndex, array &$contextRules): void
    {
        $coverage = self::coverage($reader, $offset, $reader->uint16($offset + 2));
        $classes = self::classDefinition($reader, $offset, $reader->uint16($offset + 4));
        $setCount = $reader->uint16($offset + 6);

        for ($setIndex = 0; $setIndex < $setCount; ++$setIndex) {
            $setOffset = $reader->uint16($offset + 8 + $setIndex * 2);

            if (0 === $setOffset) {
                continue;
            }

            $trigger = GsubGlyphSet::fromClass($classes, $setIndex, $coverage);
            $set = $offset + $setOffset;
            $ruleCount = $reader->uint16($set);

            for ($ruleIndex = 0; $ruleIndex < $ruleCount; ++$ruleIndex) {
                $ruleOffset = $reader->uint16($set + 2 + $ruleIndex * 2);

                if (0 === $ruleOffset) {
                    throw new InvalidFontException(\sprintf('GSUB lookup %d context-class rule offset must not be NULL.', $lookupIndex));
                }

                $rule = $set + $ruleOffset;
                $inputCount = $reader->uint16($rule);
                $substitutionCount = $reader->uint16($rule + 2);

                if ($inputCount < 1) {
                    throw new InvalidFontException(\sprintf('GSUB lookup %d context-class rule must contain an input glyph.', $lookupIndex));
                }

                $inputs = [$trigger];
                $cursor = $rule + 4;

                for ($inputIndex = 1; $inputIndex < $inputCount; ++$inputIndex) {
                    $inputs[] = GsubGlyphSet::fromClass($classes, $reader->uint16($cursor));
                    $cursor += 2;
                }

                $contextRules[] = new GsubContextRule(
                    $inputs,
                    $inputs,
                    self::lookupRecords($reader, $cursor, $substitutionCount, $inputCount, $lookupIndex),
                );
            }
        }
    }

    /**
     * @param list<GsubContextRule> $contextRules
     */
    private static function parseContextFormatThree(BinaryReader $reader, int $offset, int $lookupIndex, array &$contextRules): void
    {
        $inputCount = $reader->uint16($offset + 2);
        $substitutionCount = $reader->uint16($offset + 4);

        if ($inputCount < 1) {
            throw new InvalidFontException(\sprintf('GSUB lookup %d context-coverage rule must contain an input glyph.', $lookupIndex));
        }

        $inputs = [];
        $cursor = $offset + 6;

        for ($inputIndex = 0; $inputIndex < $inputCount; ++$inputIndex) {
            $inputs[] = GsubGlyphSet::explicit(self::coverage($reader, $offset, $reader->uint16($cursor)));
            $cursor += 2;
        }

        $contextRules[] = new GsubContextRule(
            $inputs,
            $inputs,
            self::lookupRecords($reader, $cursor, $substitutionCount, $inputCount, $lookupIndex),
        );
    }

    /**
     * @param list<GsubContextRule> $contextRules
     */
    private static function parseChainedContextSubstitution(BinaryReader $reader, int $offset, int $lookupIndex, array &$contextRules): void
    {
        $format = $reader->uint16($offset);

        match ($format) {
            1 => self::parseChainedContextFormatOne($reader, $offset, $lookupIndex, $contextRules),
            2 => self::parseChainedContextFormatTwo($reader, $offset, $lookupIndex, $contextRules),
            3 => self::parseChainedContextFormatThree($reader, $offset, $lookupIndex, $contextRules),
            default => throw self::unsupportedFormat($lookupIndex, 6, $format),
        };
    }

    /**
     * @param list<GsubContextRule> $contextRules
     */
    private static function parseChainedContextFormatOne(BinaryReader $reader, int $offset, int $lookupIndex, array &$contextRules): void
    {
        $coverage = self::coverage($reader, $offset, $reader->uint16($offset + 2));
        $setCount = $reader->uint16($offset + 4);
        self::assertMatchingCount($lookupIndex, 'chained-context rule-set', \count($coverage), $setCount);

        foreach ($coverage as $coverageIndex => $firstGlyphId) {
            $setOffset = $reader->uint16($offset + 6 + $coverageIndex * 2);

            if (0 === $setOffset) {
                continue;
            }

            $set = $offset + $setOffset;
            $ruleCount = $reader->uint16($set);

            for ($ruleIndex = 0; $ruleIndex < $ruleCount; ++$ruleIndex) {
                $ruleOffset = $reader->uint16($set + 2 + $ruleIndex * 2);

                if (0 === $ruleOffset) {
                    throw new InvalidFontException(\sprintf('GSUB lookup %d chained-context rule offset must not be NULL.', $lookupIndex));
                }

                $rule = $set + $ruleOffset;
                $cursor = $rule;
                $backtrack = self::explicitGlyphSequence($reader, $cursor);
                $inputCount = $reader->uint16($cursor);
                $cursor += 2;

                if ($inputCount < 1) {
                    throw new InvalidFontException(\sprintf('GSUB lookup %d chained-context rule must contain an input glyph.', $lookupIndex));
                }

                $inputs = [GsubGlyphSet::explicit([$firstGlyphId])];

                for ($inputIndex = 1; $inputIndex < $inputCount; ++$inputIndex) {
                    $inputs[] = GsubGlyphSet::explicit([$reader->uint16($cursor)]);
                    $cursor += 2;
                }

                $lookahead = self::explicitGlyphSequence($reader, $cursor);
                $substitutionCount = $reader->uint16($cursor);
                $cursor += 2;
                $contextRules[] = new GsubContextRule(
                    $inputs,
                    [...$backtrack, ...$inputs, ...$lookahead],
                    self::lookupRecords($reader, $cursor, $substitutionCount, $inputCount, $lookupIndex),
                );
            }
        }
    }

    /**
     * @param list<GsubContextRule> $contextRules
     */
    private static function parseChainedContextFormatTwo(BinaryReader $reader, int $offset, int $lookupIndex, array &$contextRules): void
    {
        $coverage = self::coverage($reader, $offset, $reader->uint16($offset + 2));
        $backtrackClasses = self::optionalClassDefinition($reader, $offset, $reader->uint16($offset + 4));
        $inputClasses = self::classDefinition($reader, $offset, $reader->uint16($offset + 6));
        $lookaheadClasses = self::optionalClassDefinition($reader, $offset, $reader->uint16($offset + 8));
        $setCount = $reader->uint16($offset + 10);

        for ($setIndex = 0; $setIndex < $setCount; ++$setIndex) {
            $setOffset = $reader->uint16($offset + 12 + $setIndex * 2);

            if (0 === $setOffset) {
                continue;
            }

            $trigger = GsubGlyphSet::fromClass($inputClasses, $setIndex, $coverage);
            $set = $offset + $setOffset;
            $ruleCount = $reader->uint16($set);

            for ($ruleIndex = 0; $ruleIndex < $ruleCount; ++$ruleIndex) {
                $ruleOffset = $reader->uint16($set + 2 + $ruleIndex * 2);

                if (0 === $ruleOffset) {
                    throw new InvalidFontException(\sprintf('GSUB lookup %d chained-context class-rule offset must not be NULL.', $lookupIndex));
                }

                $rule = $set + $ruleOffset;
                $cursor = $rule;
                $backtrack = self::classSequence($reader, $cursor, $backtrackClasses);
                $inputCount = $reader->uint16($cursor);
                $cursor += 2;

                if ($inputCount < 1) {
                    throw new InvalidFontException(\sprintf('GSUB lookup %d chained-context class rule must contain an input glyph.', $lookupIndex));
                }

                $inputs = [$trigger];

                for ($inputIndex = 1; $inputIndex < $inputCount; ++$inputIndex) {
                    $inputs[] = GsubGlyphSet::fromClass($inputClasses, $reader->uint16($cursor));
                    $cursor += 2;
                }

                $lookahead = self::classSequence($reader, $cursor, $lookaheadClasses);
                $substitutionCount = $reader->uint16($cursor);
                $cursor += 2;
                $contextRules[] = new GsubContextRule(
                    $inputs,
                    [...$backtrack, ...$inputs, ...$lookahead],
                    self::lookupRecords($reader, $cursor, $substitutionCount, $inputCount, $lookupIndex),
                );
            }
        }
    }

    /**
     * @param list<GsubContextRule> $contextRules
     */
    private static function parseChainedContextFormatThree(BinaryReader $reader, int $offset, int $lookupIndex, array &$contextRules): void
    {
        $cursor = $offset + 2;
        $backtrack = self::coverageSequence($reader, $offset, $cursor);
        $inputs = self::coverageSequence($reader, $offset, $cursor);

        if ([] === $inputs) {
            throw new InvalidFontException(\sprintf('GSUB lookup %d chained-context coverage rule must contain an input glyph.', $lookupIndex));
        }

        $lookahead = self::coverageSequence($reader, $offset, $cursor);
        $substitutionCount = $reader->uint16($cursor);
        $cursor += 2;
        $contextRules[] = new GsubContextRule(
            $inputs,
            [...$backtrack, ...$inputs, ...$lookahead],
            self::lookupRecords($reader, $cursor, $substitutionCount, \count($inputs), $lookupIndex),
        );
    }

    /**
     * @param array<int, array<int, true>> $substitutions
     * @param list<GsubContextRule>        $contextRules
     */
    private static function parseExtensionSubstitution(
        BinaryReader $reader,
        int $offset,
        int $lookupIndex,
        array &$substitutions,
        array &$contextRules,
    ): void {
        $format = $reader->uint16($offset);

        if (1 !== $format) {
            throw self::unsupportedFormat($lookupIndex, 7, $format);
        }

        $extensionLookupType = $reader->uint16($offset + 2);
        $extensionOffset = $reader->uint32($offset + 4);

        if (0 === $extensionOffset) {
            throw new InvalidFontException(\sprintf('GSUB lookup %d extension offset must not be NULL.', $lookupIndex));
        }

        if (7 === $extensionLookupType) {
            throw new UnsupportedFontException(\sprintf('GSUB lookup %d contains a nested extension substitution.', $lookupIndex));
        }

        self::parseSubtable($reader, $extensionLookupType, $offset + $extensionOffset, $lookupIndex, $substitutions, $contextRules);
    }

    /**
     * @param array<int, array<int, true>> $substitutions
     */
    private static function parseReverseSubstitution(BinaryReader $reader, int $offset, int $lookupIndex, array &$substitutions): void
    {
        $format = $reader->uint16($offset);

        if (1 !== $format) {
            throw self::unsupportedFormat($lookupIndex, 8, $format);
        }

        $coverage = self::coverage($reader, $offset, $reader->uint16($offset + 2));
        $cursor = $offset + 4;
        $backtrackCount = $reader->uint16($cursor);
        $cursor += 2 + $backtrackCount * 2;
        $lookaheadCount = $reader->uint16($cursor);
        $cursor += 2 + $lookaheadCount * 2;
        $glyphCount = $reader->uint16($cursor);
        $cursor += 2;
        self::assertMatchingCount($lookupIndex, 'reverse-chain substitution', \count($coverage), $glyphCount);

        foreach ($coverage as $coverageIndex => $glyphId) {
            self::addSubstitutions($substitutions, $glyphId, [$reader->uint16($cursor + $coverageIndex * 2)]);
        }
    }

    /**
     * @return array<int, true>
     */
    private static function featureLookupIndexes(BinaryReader $reader, int $offset, int $lookupCount): array
    {
        $featureCount = $reader->uint16($offset);
        $lookupIndexes = [];

        for ($featureIndex = 0; $featureIndex < $featureCount; ++$featureIndex) {
            $recordOffset = $offset + 2 + $featureIndex * 6;
            $featureOffset = $reader->uint16($recordOffset + 4);

            if (0 === $featureOffset) {
                throw new InvalidFontException(\sprintf('GSUB feature %d offset must not be NULL.', $featureIndex));
            }

            $feature = $offset + $featureOffset;
            $featureLookupCount = $reader->uint16($feature + 2);

            for ($index = 0; $index < $featureLookupCount; ++$index) {
                $lookupIndex = $reader->uint16($feature + 4 + $index * 2);

                if ($lookupIndex >= $lookupCount) {
                    throw new InvalidFontException(\sprintf(
                        'GSUB feature %d references lookup index %d outside lookup count %d.',
                        $featureIndex,
                        $lookupIndex,
                        $lookupCount,
                    ));
                }

                $lookupIndexes[$lookupIndex] = true;
            }
        }

        return $lookupIndexes;
    }

    /**
     * @return list<int>
     */
    private static function coverage(BinaryReader $reader, int $subtableOffset, int $coverageOffset): array
    {
        return CoverageTable::parse($reader, $subtableOffset, $coverageOffset);
    }

    /**
     * @return array<int, int>
     */
    private static function classDefinition(BinaryReader $reader, int $subtableOffset, int $classDefinitionOffset): array
    {
        return ClassDefinitionTable::parse($reader, $subtableOffset, $classDefinitionOffset);
    }

    /**
     * @return array<int, int>
     */
    private static function optionalClassDefinition(
        BinaryReader $reader,
        int $subtableOffset,
        int $classDefinitionOffset,
    ): array {
        return 0 === $classDefinitionOffset
            ? []
            : self::classDefinition($reader, $subtableOffset, $classDefinitionOffset);
    }

    /**
     * @return list<GsubGlyphSet>
     */
    private static function explicitGlyphSequence(BinaryReader $reader, int &$cursor): array
    {
        $glyphCount = $reader->uint16($cursor);
        $cursor += 2;
        $glyphs = [];

        for ($index = 0; $index < $glyphCount; ++$index) {
            $glyphs[] = GsubGlyphSet::explicit([$reader->uint16($cursor)]);
            $cursor += 2;
        }

        return $glyphs;
    }

    /**
     * @param array<int, int> $classesByGlyph
     *
     * @return list<GsubGlyphSet>
     */
    private static function classSequence(BinaryReader $reader, int &$cursor, array $classesByGlyph): array
    {
        $classCount = $reader->uint16($cursor);
        $cursor += 2;
        $classes = [];

        for ($index = 0; $index < $classCount; ++$index) {
            $classes[] = GsubGlyphSet::fromClass($classesByGlyph, $reader->uint16($cursor));
            $cursor += 2;
        }

        return $classes;
    }

    /**
     * @return list<GsubGlyphSet>
     */
    private static function coverageSequence(BinaryReader $reader, int $subtableOffset, int &$cursor): array
    {
        $coverageCount = $reader->uint16($cursor);
        $cursor += 2;
        $coverages = [];

        for ($index = 0; $index < $coverageCount; ++$index) {
            $coverages[] = GsubGlyphSet::explicit(self::coverage($reader, $subtableOffset, $reader->uint16($cursor)));
            $cursor += 2;
        }

        return $coverages;
    }

    /**
     * @return list<array{sequenceIndex: int, lookupIndex: int}>
     */
    private static function lookupRecords(
        BinaryReader $reader,
        int &$cursor,
        int $recordCount,
        int $inputCount,
        int $lookupIndex,
    ): array {
        $records = [];

        for ($recordIndex = 0; $recordIndex < $recordCount; ++$recordIndex) {
            $sequenceIndex = $reader->uint16($cursor);
            $referencedLookupIndex = $reader->uint16($cursor + 2);
            $cursor += 4;

            if ($sequenceIndex >= $inputCount) {
                throw new InvalidFontException(\sprintf(
                    'GSUB lookup %d contextual record sequence index %d is outside input count %d.',
                    $lookupIndex,
                    $sequenceIndex,
                    $inputCount,
                ));
            }

            $records[] = [
                'sequenceIndex' => $sequenceIndex,
                'lookupIndex' => $referencedLookupIndex,
            ];
        }

        return $records;
    }

    /**
     * @param array<int, array<int, true>> $substitutions
     * @param list<int>                    $outputs
     */
    private static function addSubstitutions(array &$substitutions, int $inputGlyphId, array $outputs): void
    {
        foreach ($outputs as $outputGlyphId) {
            $substitutions[$inputGlyphId][$outputGlyphId] = true;
        }
    }

    /**
     * @param array<int, true> $glyphs
     * @param list<int>        $queue
     */
    private static function retain(int $glyphId, array &$glyphs, array &$queue): bool
    {
        if (isset($glyphs[$glyphId])) {
            return false;
        }

        $glyphs[$glyphId] = true;
        $queue[] = $glyphId;

        return true;
    }

    private static function assertMatchingCount(int $lookupIndex, string $label, int $coverageCount, int $recordCount): void
    {
        if ($coverageCount !== $recordCount) {
            throw new InvalidFontException(\sprintf(
                'GSUB lookup %d %s count %d does not match coverage count %d.',
                $lookupIndex,
                $label,
                $recordCount,
                $coverageCount,
            ));
        }
    }

    private static function assertGlyphId(int $glyphId, int $glyphCount): void
    {
        if ($glyphId < 0 || $glyphId >= $glyphCount) {
            throw new InvalidFontException(\sprintf('GSUB references invalid glyph ID %d for a font with %d glyphs.', $glyphId, $glyphCount));
        }
    }

    private static function unsupportedFormat(int $lookupIndex, int $lookupType, int $format): UnsupportedFontException
    {
        return new UnsupportedFontException(\sprintf('GSUB lookup %d type %d uses unsupported format %d.', $lookupIndex, $lookupType, $format));
    }
}
