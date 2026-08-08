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

namespace Alto\Font\Variation\Table;

use Alto\Font\Binary\BinaryReader;
use Alto\Font\Exception\InvalidFontException;
use Alto\Font\Variation\FontVariations;
use Alto\Font\Variation\ItemStore\TupleRegion;
use Alto\Font\Variation\ItemStore\TupleVariation;
use Alto\Font\Variation\NormalizedCoordinates;
use Alto\Font\Variation\VariationDeltas;

final readonly class GvarTable
{
    private const int LONG_OFFSETS = 0x0001;
    private const int TUPLES_SHARE_POINT_NUMBERS = 0x8000;
    private const int TUPLE_COUNT_MASK = 0x0FFF;
    private const int EMBEDDED_PEAK_TUPLE = 0x8000;
    private const int INTERMEDIATE_REGION = 0x4000;
    private const int PRIVATE_POINT_NUMBERS = 0x2000;
    private const int TUPLE_INDEX_MASK = 0x0FFF;
    private const int POINTS_ARE_WORDS = 0x80;
    private const int POINT_RUN_COUNT_MASK = 0x7F;
    private const int DELTAS_ARE_ZERO = 0x80;
    private const int DELTAS_ARE_WORDS = 0x40;
    private const int DELTA_RUN_COUNT_MASK = 0x3F;

    /**
     * @param list<int> $glyphOffsets
     */
    private function __construct(
        private BinaryReader $reader,
        private FontVariations $variations,
        private array $glyphOffsets,
        private int $glyphVariationDataArrayOffset,
        private int $sharedTupleCount,
        private int $sharedTuplesOffset,
    ) {}

    public static function parse(BinaryReader $reader, FontVariations $variations, int $glyphCount): self
    {
        $majorVersion = $reader->uint16(0);
        $minorVersion = $reader->uint16(2);

        if (1 !== $majorVersion || 0 !== $minorVersion) {
            throw new InvalidFontException(\sprintf('Unsupported gvar table version %d.%d.', $majorVersion, $minorVersion));
        }

        $axisCount = $reader->uint16(4);

        if ($axisCount !== \count($variations->axes)) {
            throw new InvalidFontException(\sprintf('gvar axis count %d does not match fvar axis count %d.', $axisCount, \count($variations->axes)));
        }

        $sharedTupleCount = $reader->uint16(6);
        $sharedTuplesOffset = $reader->uint32(8);
        $tableGlyphCount = $reader->uint16(12);
        $flags = $reader->uint16(14);
        $glyphVariationDataArrayOffset = $reader->uint32(16);

        if ($tableGlyphCount !== $glyphCount) {
            throw new InvalidFontException(\sprintf('gvar glyph count %d does not match maxp glyph count %d.', $tableGlyphCount, $glyphCount));
        }

        $offsets = [];
        $offsetCursor = 20;

        for ($i = 0; $i <= $glyphCount; ++$i) {
            if (0 !== ($flags & self::LONG_OFFSETS)) {
                $offsets[] = $reader->uint32($offsetCursor);
                $offsetCursor += 4;
                continue;
            }

            $offsets[] = $reader->uint16($offsetCursor) * 2;
            $offsetCursor += 2;
        }

        return new self($reader, $variations, $offsets, $glyphVariationDataArrayOffset, $sharedTupleCount, $sharedTuplesOffset);
    }

    public function deltasForGlyph(int $glyphId, int $pointCount, NormalizedCoordinates $coordinates): VariationDeltas
    {
        $variations = $this->tupleVariationsForGlyph($glyphId, $pointCount);
        $deltas = VariationDeltas::zero($pointCount);

        foreach ($variations as $variation) {
            $deltas = $deltas->add($variation->deltas($pointCount, $coordinates, $this->variations));
        }

        return $deltas;
    }

    /**
     * @return list<TupleVariation>
     */
    public function tupleVariationsForGlyph(int $glyphId, int $pointCount): array
    {
        $start = $this->glyphOffsets[$glyphId] ?? null;
        $end = $this->glyphOffsets[$glyphId + 1] ?? null;

        if (null === $start || null === $end || $start === $end) {
            return [];
        }

        $glyphDataOffset = $this->glyphVariationDataArrayOffset + $start;
        $tupleVariationCountField = $this->reader->uint16($glyphDataOffset);
        $tupleVariationCount = $tupleVariationCountField & self::TUPLE_COUNT_MASK;
        $dataOffset = $this->reader->uint16($glyphDataOffset + 2);
        $headerCursor = $glyphDataOffset + 4;
        $dataCursor = $glyphDataOffset + $dataOffset;
        $sharedPointNumbers = null;
        $headers = [];

        for ($i = 0; $i < $tupleVariationCount; ++$i) {
            $variationDataSize = $this->reader->uint16($headerCursor);
            $tupleIndex = $this->reader->uint16($headerCursor + 2);
            $headerCursor += 4;
            $peak = $this->readTuple($tupleIndex, $headerCursor);
            $startTuple = null;
            $endTuple = null;

            if (0 !== ($tupleIndex & self::INTERMEDIATE_REGION)) {
                $startTuple = $this->readTuple(self::EMBEDDED_PEAK_TUPLE, $headerCursor);
                $endTuple = $this->readTuple(self::EMBEDDED_PEAK_TUPLE, $headerCursor);
            }

            $headers[] = [$variationDataSize, $tupleIndex, $peak, $startTuple, $endTuple];
        }

        if (0 !== ($tupleVariationCountField & self::TUPLES_SHARE_POINT_NUMBERS)) {
            $sharedPointNumbers = $this->readPackedPointNumbers($dataCursor, $pointCount);
        }

        $tupleVariations = [];

        foreach ($headers as [$variationDataSize, $tupleIndex, $peak, $startTuple, $endTuple]) {
            $tupleDataStart = $dataCursor;
            $pointNumbers = $sharedPointNumbers;

            if (0 !== ($tupleIndex & self::PRIVATE_POINT_NUMBERS)) {
                $pointNumbers = $this->readPackedPointNumbers($dataCursor, $pointCount);
            }

            $deltaCount = null === $pointNumbers ? $pointCount : \count($pointNumbers);
            $xDeltas = $this->readPackedDeltas($dataCursor, $deltaCount);
            $yDeltas = $this->readPackedDeltas($dataCursor, $deltaCount);
            $dataCursor = $tupleDataStart + $variationDataSize;

            $tupleVariations[] = new TupleVariation(
                region: null === $startTuple || null === $endTuple ? TupleRegion::fromPeak($peak) : new TupleRegion($startTuple, $peak, $endTuple),
                pointNumbers: $pointNumbers,
                xDeltas: $xDeltas,
                yDeltas: $yDeltas,
            );
        }

        return $tupleVariations;
    }

    /**
     * @return list<float>
     */
    private function readTuple(int $tupleIndex, int &$cursor): array
    {
        if (0 !== ($tupleIndex & self::EMBEDDED_PEAK_TUPLE)) {
            $tuple = [];

            foreach ($this->variations->axes as $_) {
                $tuple[] = $this->reader->fixed2Dot14($cursor);
                $cursor += 2;
            }

            return $tuple;
        }

        $sharedTupleIndex = $tupleIndex & self::TUPLE_INDEX_MASK;

        if ($sharedTupleIndex >= $this->sharedTupleCount) {
            throw new InvalidFontException(\sprintf('gvar shared tuple index %d is out of bounds.', $sharedTupleIndex));
        }

        $sharedCursor = $this->sharedTuplesOffset + $sharedTupleIndex * \count($this->variations->axes) * 2;
        $tuple = [];

        foreach ($this->variations->axes as $_) {
            $tuple[] = $this->reader->fixed2Dot14($sharedCursor);
            $sharedCursor += 2;
        }

        return $tuple;
    }

    /**
     * @return list<int>|null
     */
    private function readPackedPointNumbers(int &$cursor, int $pointCount): ?array
    {
        $count = $this->reader->uint8($cursor++);

        if (0 === $count) {
            return null;
        }

        if (0 !== ($count & 0x80)) {
            $count = (($count & 0x7F) << 8) | $this->reader->uint8($cursor++);
        }

        $points = [];
        $point = 0;

        while (\count($points) < $count) {
            $control = $this->reader->uint8($cursor++);
            $runCount = ($control & self::POINT_RUN_COUNT_MASK) + 1;
            $words = 0 !== ($control & self::POINTS_ARE_WORDS);

            for ($i = 0; $i < $runCount && \count($points) < $count; ++$i) {
                $point += $words ? $this->reader->uint16($cursor) : $this->reader->uint8($cursor);
                $cursor += $words ? 2 : 1;

                if ($point >= $pointCount) {
                    throw new InvalidFontException(\sprintf('gvar point number %d is outside point count %d.', $point, $pointCount));
                }

                $points[] = $point;
            }
        }

        return $points;
    }

    /**
     * @return list<int>
     */
    private function readPackedDeltas(int &$cursor, int $deltaCount): array
    {
        $deltas = [];

        while (\count($deltas) < $deltaCount) {
            $control = $this->reader->uint8($cursor++);
            $runCount = ($control & self::DELTA_RUN_COUNT_MASK) + 1;

            if (0 !== ($control & self::DELTAS_ARE_ZERO)) {
                for ($i = 0; $i < $runCount && \count($deltas) < $deltaCount; ++$i) {
                    $deltas[] = 0;
                }

                continue;
            }

            if (0 !== ($control & self::DELTAS_ARE_WORDS)) {
                for ($i = 0; $i < $runCount && \count($deltas) < $deltaCount; ++$i) {
                    $deltas[] = $this->reader->int16($cursor);
                    $cursor += 2;
                }

                continue;
            }

            for ($i = 0; $i < $runCount && \count($deltas) < $deltaCount; ++$i) {
                $deltas[] = $this->reader->int8($cursor++);
            }
        }

        return $deltas;
    }
}
