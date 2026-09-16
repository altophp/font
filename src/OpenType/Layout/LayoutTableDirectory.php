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

/**
 * Parses and canonically rebuilds the top-level structures of a layout table.
 *
 * @author Simon André <smn.andre@gmail.com>
 *
 * @internal
 */
final readonly class LayoutTableDirectory
{
    private function __construct(
        private int $minorVersion,
        private string $scriptList,
        private string $featureList,
        public int $lookupListOffset,
        private ?string $featureVariations,
    ) {}

    public static function parse(BinaryReader $reader, string $tag): self
    {
        $majorVersion = $reader->uint16(0);
        $minorVersion = $reader->uint16(2);

        if (1 !== $majorVersion || !\in_array($minorVersion, [0, 1], true)) {
            throw new UnsupportedFontException(\sprintf(
                'Compact %s output supports versions 1.0 and 1.1 only.',
                $tag,
            ));
        }

        $headerLength = 0 === $minorVersion ? 10 : 14;
        $scriptListOffset = $reader->uint16(4);
        $featureListOffset = $reader->uint16(6);
        $lookupListOffset = $reader->uint16(8);
        $featureVariationsOffset = 1 === $minorVersion ? $reader->uint32(10) : 0;
        $offsets = [
            'script list' => $scriptListOffset,
            'feature list' => $featureListOffset,
            'lookup list' => $lookupListOffset,
        ];

        if (0 !== $featureVariationsOffset) {
            $offsets['feature variations'] = $featureVariationsOffset;
        }

        foreach ($offsets as $name => $offset) {
            if ($offset < $headerLength || $offset >= $reader->length()) {
                throw new InvalidFontException(\sprintf('%s %s offset is invalid.', $tag, $name));
            }
        }

        if (\count(array_unique($offsets)) !== \count($offsets)) {
            throw new InvalidFontException(\sprintf('%s top-level section offsets must be distinct.', $tag));
        }

        $lookupCount = $reader->uint16($lookupListOffset);
        [$featureList, $featureTags] = self::parseFeatureList($reader, $featureListOffset, $lookupCount, $tag);
        $scriptList = self::parseScriptList($reader, $scriptListOffset, \count($featureTags), $tag);
        $featureVariations = 0 === $featureVariationsOffset
            ? null
            : self::parseFeatureVariations(
                $reader,
                $featureVariationsOffset,
                $featureTags,
                $lookupCount,
                $tag,
            );

        return new self(
            $minorVersion,
            $scriptList,
            $featureList,
            $lookupListOffset,
            $featureVariations,
        );
    }

    public function build(string $lookupList): string
    {
        $headerLength = 0 === $this->minorVersion ? 10 : 14;
        $scriptListOffset = $headerLength;
        $featureListOffset = $scriptListOffset + \strlen($this->scriptList);
        $lookupListOffset = $featureListOffset + \strlen($this->featureList);
        $featureVariationsOffset = null === $this->featureVariations
            ? 0
            : $lookupListOffset + \strlen($lookupList);
        $header = self::uint16(1)
            . self::uint16($this->minorVersion)
            . self::offset16($scriptListOffset)
            . self::offset16($featureListOffset)
            . self::offset16($lookupListOffset);

        if (1 === $this->minorVersion) {
            $header .= self::offset32($featureVariationsOffset);
        }

        return $header
            . $this->scriptList
            . $this->featureList
            . $lookupList
            . ($this->featureVariations ?? '');
    }

    /**
     * @return array{string, list<string>}
     */
    private static function parseFeatureList(
        BinaryReader $reader,
        int $offset,
        int $lookupCount,
        string $tag,
    ): array {
        $featureCount = $reader->uint16($offset);
        $features = [];
        $featureTags = [];

        for ($index = 0; $index < $featureCount; ++$index) {
            $record = $offset + 2 + $index * 6;
            $featureTag = $reader->string($record, 4);
            $featureOffset = $reader->uint16($record + 4);

            if (0 === $featureOffset) {
                throw new InvalidFontException(\sprintf('%s feature %d offset must not be NULL.', $tag, $index));
            }

            $featureTags[] = $featureTag;
            $features[] = self::parseFeature($reader, $offset + $featureOffset, $featureTag, $lookupCount, $tag);
        }

        $headerLength = 2 + $featureCount * 6;
        $header = self::uint16($featureCount);
        $data = '';
        $cursor = $headerLength;

        foreach ($features as $index => $feature) {
            $header .= $featureTags[$index] . self::offset16($cursor);
            $data .= $feature;
            $cursor += \strlen($feature);
        }

        return [$header . $data, $featureTags];
    }

    private static function parseScriptList(
        BinaryReader $reader,
        int $offset,
        int $featureCount,
        string $tag,
    ): string {
        $scriptCount = $reader->uint16($offset);
        $scripts = [];
        $scriptTags = [];

        for ($index = 0; $index < $scriptCount; ++$index) {
            $record = $offset + 2 + $index * 6;
            $scriptTag = $reader->string($record, 4);
            $scriptOffset = $reader->uint16($record + 4);

            if (0 === $scriptOffset) {
                throw new InvalidFontException(\sprintf('%s script %d offset must not be NULL.', $tag, $index));
            }

            $scriptTags[] = $scriptTag;
            $scripts[] = self::parseScript($reader, $offset + $scriptOffset, $featureCount, $tag);
        }

        $headerLength = 2 + $scriptCount * 6;
        $header = self::uint16($scriptCount);
        $data = '';
        $cursor = $headerLength;

        foreach ($scripts as $index => $script) {
            $header .= $scriptTags[$index] . self::offset16($cursor);
            $data .= $script;
            $cursor += \strlen($script);
        }

        return $header . $data;
    }

    private static function parseScript(
        BinaryReader $reader,
        int $offset,
        int $featureCount,
        string $tag,
    ): string {
        $defaultLangSysOffset = $reader->uint16($offset);
        $langSysCount = $reader->uint16($offset + 2);
        $defaultLangSys = 0 === $defaultLangSysOffset
            ? null
            : self::parseLangSys($reader, $offset + $defaultLangSysOffset, $featureCount, $tag);
        $langSystems = [];
        $languageTags = [];

        for ($index = 0; $index < $langSysCount; ++$index) {
            $record = $offset + 4 + $index * 6;
            $languageTag = $reader->string($record, 4);
            $langSysOffset = $reader->uint16($record + 4);

            if (0 === $langSysOffset) {
                throw new InvalidFontException(\sprintf('%s language-system %d offset must not be NULL.', $tag, $index));
            }

            $languageTags[] = $languageTag;
            $langSystems[] = self::parseLangSys($reader, $offset + $langSysOffset, $featureCount, $tag);
        }

        $headerLength = 4 + $langSysCount * 6;
        $header = self::uint16(null === $defaultLangSys ? 0 : $headerLength)
            . self::uint16($langSysCount);
        $data = $defaultLangSys ?? '';
        $cursor = $headerLength + \strlen($data);

        foreach ($langSystems as $index => $langSys) {
            $header .= $languageTags[$index] . self::offset16($cursor);
            $data .= $langSys;
            $cursor += \strlen($langSys);
        }

        return $header . $data;
    }

    private static function parseLangSys(
        BinaryReader $reader,
        int $offset,
        int $featureCount,
        string $tag,
    ): string {
        if (0 !== $reader->uint16($offset)) {
            throw new UnsupportedFontException(\sprintf('%s language-system lookup-order data is not supported.', $tag));
        }

        $requiredFeatureIndex = $reader->uint16($offset + 2);
        $featureIndexCount = $reader->uint16($offset + 4);

        if (0xFFFF !== $requiredFeatureIndex && $requiredFeatureIndex >= $featureCount) {
            throw new InvalidFontException(\sprintf('%s required feature index is out of range.', $tag));
        }

        $result = self::uint16(0)
            . self::uint16($requiredFeatureIndex)
            . self::uint16($featureIndexCount);

        for ($index = 0; $index < $featureIndexCount; ++$index) {
            $featureIndex = $reader->uint16($offset + 6 + $index * 2);

            if ($featureIndex >= $featureCount) {
                throw new InvalidFontException(\sprintf('%s feature index is out of range.', $tag));
            }

            $result .= self::uint16($featureIndex);
        }

        return $result;
    }

    private static function parseFeature(
        BinaryReader $reader,
        int $offset,
        string $featureTag,
        int $lookupCount,
        string $tableTag,
    ): string {
        $featureParamsOffset = $reader->uint16($offset);
        $lookupIndexCount = $reader->uint16($offset + 2);
        $result = self::uint16(0) . self::uint16($lookupIndexCount);

        for ($index = 0; $index < $lookupIndexCount; ++$index) {
            $lookupIndex = $reader->uint16($offset + 4 + $index * 2);

            if ($lookupIndex >= $lookupCount) {
                throw new InvalidFontException(\sprintf('%s feature lookup index is out of range.', $tableTag));
            }

            $result .= self::uint16($lookupIndex);
        }

        if (0 === $featureParamsOffset) {
            return $result;
        }

        $params = self::parseFeatureParams($reader, $offset + $featureParamsOffset, $featureTag, $tableTag);

        return self::offset16(4 + $lookupIndexCount * 2) . substr($result, 2) . $params;
    }

    private static function parseFeatureParams(
        BinaryReader $reader,
        int $offset,
        string $featureTag,
        string $tableTag,
    ): string {
        if ('size' === $featureTag) {
            return $reader->string($offset, 10);
        }

        if (1 === preg_match('/^ss(?:0[1-9]|1[0-9]|20)$/D', $featureTag)) {
            if (0 !== $reader->uint16($offset)) {
                throw new UnsupportedFontException(\sprintf('%s feature %s parameters version is not supported.', $tableTag, $featureTag));
            }

            return $reader->string($offset, 4);
        }

        if (1 === preg_match('/^cv(?:0[1-9]|[1-9][0-9])$/D', $featureTag)) {
            if (0 !== $reader->uint16($offset)) {
                throw new UnsupportedFontException(\sprintf('%s feature %s parameters format is not supported.', $tableTag, $featureTag));
            }

            return $reader->string($offset, 14 + $reader->uint16($offset + 12) * 3);
        }

        throw new UnsupportedFontException(\sprintf(
            '%s feature %s uses unsupported feature parameters.',
            $tableTag,
            $featureTag,
        ));
    }

    /**
     * @param list<string> $featureTags
     */
    private static function parseFeatureVariations(
        BinaryReader $reader,
        int $offset,
        array $featureTags,
        int $lookupCount,
        string $tag,
    ): string {
        if (1 !== $reader->uint16($offset) || 0 !== $reader->uint16($offset + 2)) {
            throw new UnsupportedFontException(\sprintf('%s FeatureVariations version is not supported.', $tag));
        }

        $recordCount = $reader->uint32($offset + 4);
        $records = [];

        for ($index = 0; $index < $recordCount; ++$index) {
            $record = $offset + 8 + $index * 8;
            $conditionSetOffset = $reader->uint32($record);
            $substitutionOffset = $reader->uint32($record + 4);
            $records[] = [
                0 === $conditionSetOffset ? null : self::parseConditionSet($reader, $offset + $conditionSetOffset, $tag),
                0 === $substitutionOffset ? null : self::parseFeatureSubstitution(
                    $reader,
                    $offset + $substitutionOffset,
                    $featureTags,
                    $lookupCount,
                    $tag,
                ),
            ];
        }

        $headerLength = 8 + $recordCount * 8;
        $header = self::uint16(1) . self::uint16(0) . self::uint32($recordCount);
        $data = '';
        $cursor = $headerLength;

        foreach ($records as [$conditionSet, $substitution]) {
            $header .= null === $conditionSet ? self::uint32(0) : self::offset32($cursor);

            if (null !== $conditionSet) {
                $data .= $conditionSet;
                $cursor += \strlen($conditionSet);
            }

            $header .= null === $substitution ? self::uint32(0) : self::offset32($cursor);

            if (null !== $substitution) {
                $data .= $substitution;
                $cursor += \strlen($substitution);
            }
        }

        return $header . $data;
    }

    private static function parseConditionSet(BinaryReader $reader, int $offset, string $tag): string
    {
        $conditionCount = $reader->uint16($offset);
        $conditions = [];

        for ($index = 0; $index < $conditionCount; ++$index) {
            $conditionOffset = $reader->uint32($offset + 2 + $index * 4);

            if (0 === $conditionOffset) {
                throw new InvalidFontException(\sprintf('%s variation condition offset must not be NULL.', $tag));
            }

            $condition = $offset + $conditionOffset;

            if (1 !== $reader->uint16($condition)) {
                throw new UnsupportedFontException(\sprintf('%s variation condition format is not supported.', $tag));
            }

            $conditions[] = $reader->string($condition, 8);
        }

        $headerLength = 2 + $conditionCount * 4;
        $header = self::uint16($conditionCount);
        $data = '';
        $cursor = $headerLength;

        foreach ($conditions as $condition) {
            $header .= self::offset32($cursor);
            $data .= $condition;
            $cursor += \strlen($condition);
        }

        return $header . $data;
    }

    /**
     * @param list<string> $featureTags
     */
    private static function parseFeatureSubstitution(
        BinaryReader $reader,
        int $offset,
        array $featureTags,
        int $lookupCount,
        string $tag,
    ): string {
        if (1 !== $reader->uint16($offset) || 0 !== $reader->uint16($offset + 2)) {
            throw new UnsupportedFontException(\sprintf('%s feature-table substitution version is not supported.', $tag));
        }

        $substitutionCount = $reader->uint16($offset + 4);
        $substitutions = [];
        $previousFeatureIndex = null;

        for ($index = 0; $index < $substitutionCount; ++$index) {
            $record = $offset + 6 + $index * 6;
            $featureIndex = $reader->uint16($record);
            $alternateFeatureOffset = $reader->uint32($record + 2);

            if (!isset($featureTags[$featureIndex])) {
                throw new InvalidFontException(\sprintf('%s variation feature index is out of range.', $tag));
            }

            if (null !== $previousFeatureIndex && $featureIndex <= $previousFeatureIndex) {
                throw new InvalidFontException(\sprintf('%s variation feature indices must be strictly increasing.', $tag));
            }

            if (0 === $alternateFeatureOffset) {
                throw new InvalidFontException(\sprintf('%s alternate feature offset must not be NULL.', $tag));
            }

            $substitutions[] = [
                $featureIndex,
                self::parseFeature(
                    $reader,
                    $offset + $alternateFeatureOffset,
                    $featureTags[$featureIndex],
                    $lookupCount,
                    $tag,
                ),
            ];
            $previousFeatureIndex = $featureIndex;
        }

        $headerLength = 6 + $substitutionCount * 6;
        $header = self::uint16(1) . self::uint16(0) . self::uint16($substitutionCount);
        $data = '';
        $cursor = $headerLength;

        foreach ($substitutions as [$featureIndex, $alternateFeature]) {
            $header .= self::uint16($featureIndex) . self::offset32($cursor);
            $data .= $alternateFeature;
            $cursor += \strlen($alternateFeature);
        }

        return $header . $data;
    }

    private static function offset16(int $value): string
    {
        if ($value < 0 || $value > 0xFFFF) {
            throw new UnsupportedFontException('Compacted layout data exceeds a 16-bit OpenType offset.');
        }

        return self::uint16($value);
    }

    private static function offset32(int $value): string
    {
        if ($value < 0 || $value > 0xFFFFFFFF) {
            throw new UnsupportedFontException('Compacted layout data exceeds a 32-bit OpenType offset.');
        }

        return self::uint32($value);
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
