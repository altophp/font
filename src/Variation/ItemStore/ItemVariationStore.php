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

namespace Alto\Font\Variation\ItemStore;

use Alto\Font\Binary\BinaryReader;
use Alto\Font\Exception\InvalidFontException;
use Alto\Font\Variation\FontVariations;
use Alto\Font\Variation\NormalizedCoordinates;

final readonly class ItemVariationStore
{
    private const int LONG_WORDS = 0x8000;
    private const int WORD_DELTA_COUNT_MASK = 0x7FFF;
    private const int NO_VARIATION_INDEX = 0xFFFF;

    /**
     * @param list<TupleRegion>                                                $regions
     * @param list<array{regions: list<int>, deltaSets: list<list<int>>}|null> $data
     */
    private function __construct(
        private FontVariations $variations,
        private array $regions,
        private array $data,
    ) {}

    public static function parse(BinaryReader $reader, FontVariations $variations): self
    {
        $format = $reader->uint16(0);

        if (1 !== $format) {
            throw new InvalidFontException(\sprintf('Unsupported ItemVariationStore format %d.', $format));
        }

        $variationRegionListOffset = $reader->uint32(2);
        $itemVariationDataCount = $reader->uint16(6);
        $itemVariationDataOffsets = [];
        $cursor = 8;

        for ($i = 0; $i < $itemVariationDataCount; ++$i) {
            $itemVariationDataOffsets[] = $reader->uint32($cursor);
            $cursor += 4;
        }

        $regions = self::parseVariationRegionList($reader, $variationRegionListOffset, $variations);
        $data = [];

        foreach ($itemVariationDataOffsets as $offset) {
            $data[] = 0 === $offset ? null : self::parseItemVariationData($reader, $offset, $regions);
        }

        return new self($variations, $regions, $data);
    }

    public function delta(int $outerIndex, int $innerIndex, NormalizedCoordinates $coordinates): float
    {
        if (self::NO_VARIATION_INDEX === $outerIndex && self::NO_VARIATION_INDEX === $innerIndex) {
            return 0.0;
        }

        if (!\array_key_exists($outerIndex, $this->data)) {
            throw new InvalidFontException(\sprintf('Item variation outer index %d is out of bounds.', $outerIndex));
        }

        $data = $this->data[$outerIndex];

        if (null === $data) {
            return 0.0;
        }

        $deltaSet = $data['deltaSets'][$innerIndex] ?? throw new InvalidFontException(\sprintf('Item variation inner index %d is out of bounds.', $innerIndex));
        $delta = 0.0;

        foreach ($data['regions'] as $index => $regionIndex) {
            $region = $this->regions[$regionIndex] ?? throw new InvalidFontException(\sprintf('Variation region index %d is out of bounds.', $regionIndex));
            $delta += ($deltaSet[$index] ?? 0) * $region->scalar($coordinates, $this->variations);
        }

        return $delta;
    }

    /**
     * @return list<TupleRegion>
     */
    private static function parseVariationRegionList(BinaryReader $reader, int $offset, FontVariations $variations): array
    {
        $axisCount = $reader->uint16($offset);
        $regionCount = $reader->uint16($offset + 2);

        if ($axisCount !== \count($variations->axes)) {
            throw new InvalidFontException(\sprintf('VariationRegionList axis count %d does not match fvar axis count %d.', $axisCount, \count($variations->axes)));
        }

        if (0 !== ($regionCount & 0x8000)) {
            throw new InvalidFontException('VariationRegionList region count uses reserved bits.');
        }

        $cursor = $offset + 4;
        $regions = [];

        for ($i = 0; $i < $regionCount; ++$i) {
            $start = [];
            $peak = [];
            $end = [];

            for ($j = 0; $j < $axisCount; ++$j) {
                $start[] = $reader->fixed2Dot14($cursor);
                $peak[] = $reader->fixed2Dot14($cursor + 2);
                $end[] = $reader->fixed2Dot14($cursor + 4);
                $cursor += 6;
            }

            $regions[] = new TupleRegion($start, $peak, $end);
        }

        return $regions;
    }

    /**
     * @param list<TupleRegion> $regions
     *
     * @return array{regions: list<int>, deltaSets: list<list<int>>}
     */
    private static function parseItemVariationData(BinaryReader $reader, int $offset, array $regions): array
    {
        $itemCount = $reader->uint16($offset);
        $wordDeltaCountField = $reader->uint16($offset + 2);
        $longWords = 0 !== ($wordDeltaCountField & self::LONG_WORDS);
        $wordDeltaCount = $wordDeltaCountField & self::WORD_DELTA_COUNT_MASK;
        $regionIndexCount = $reader->uint16($offset + 4);

        if ($wordDeltaCount > $regionIndexCount) {
            throw new InvalidFontException('ItemVariationData word delta count exceeds region index count.');
        }

        $cursor = $offset + 6;
        $regionIndexes = [];

        for ($i = 0; $i < $regionIndexCount; ++$i) {
            $regionIndex = $reader->uint16($cursor);
            $cursor += 2;

            if (!isset($regions[$regionIndex])) {
                throw new InvalidFontException(\sprintf('Variation region index %d is out of bounds.', $regionIndex));
            }

            $regionIndexes[] = $regionIndex;
        }

        $deltaSets = [];

        for ($i = 0; $i < $itemCount; ++$i) {
            $deltaSet = [];

            for ($j = 0; $j < $regionIndexCount; ++$j) {
                if ($j < $wordDeltaCount) {
                    $deltaSet[] = $longWords ? $reader->int32($cursor) : $reader->int16($cursor);
                    $cursor += $longWords ? 4 : 2;
                    continue;
                }

                $deltaSet[] = $longWords ? $reader->int16($cursor) : $reader->int8($cursor);
                $cursor += $longWords ? 2 : 1;
            }

            $deltaSets[] = $deltaSet;
        }

        return ['regions' => $regionIndexes, 'deltaSets' => $deltaSets];
    }
}
