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
use Alto\Font\Subset\UnicodeSet;

/**
 * Parses and queries an OpenType character mapping table.
 *
 * The table is kept as its segments (format 4) or groups (format 12), sorted
 * by codepoint, so memory is proportional to the segment count. A codepoint is
 * resolved by binary search at lookup time; mapped pairs are produced lazily.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class CmapTable implements \Countable
{
    private const int FORMAT_4 = 4;
    private const int FORMAT_12 = 12;

    /**
     * @param list<int> $starts       first codepoint of each segment, strictly increasing
     * @param list<int> $ends         last codepoint of each segment, strictly increasing
     * @param list<int> $glyphStarts  format 12: glyph ID of each segment's first codepoint
     * @param list<int> $deltas       format 4: unsigned idDelta of each segment
     * @param list<int> $rangeOffsets format 4: idRangeOffset of each segment
     * @param string    $glyphIdArray format 4: bytes from the idRangeOffset array to the subtable end
     */
    private function __construct(
        private int $format,
        private array $starts,
        private array $ends,
        private array $glyphStarts,
        private array $deltas,
        private array $rangeOffsets,
        private string $glyphIdArray,
        private int $codepointCount,
    ) {}

    public function glyphIdForCodepoint(int $codepoint): ?int
    {
        $ends = $this->ends;
        $low = 0;
        $high = \count($ends) - 1;

        while ($low <= $high) {
            $middle = ($low + $high) >> 1;

            if ($ends[$middle] < $codepoint) {
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        if (!isset($this->starts[$low]) || $this->starts[$low] > $codepoint) {
            return null;
        }

        if (self::FORMAT_12 === $this->format) {
            return $this->glyphStarts[$low] + ($codepoint - $this->starts[$low]);
        }

        if (0 === $this->rangeOffsets[$low]) {
            return ($codepoint + $this->deltas[$low]) & 0xFFFF;
        }

        return $this->glyphIdFromArray($low, $codepoint);
    }

    /**
     * Yields mapped codepoint => glyph ID pairs in codepoint order, restricted
     * to the given set when one is provided. Only the yielded pairs are
     * materialized, so a caller never pays for the whole map.
     *
     * @return \Generator<int, int>
     */
    public function mappings(?UnicodeSet $unicodes = null): \Generator
    {
        if (null === $unicodes) {
            foreach ($this->ends as $segment => $end) {
                yield from $this->segmentMappings($segment, $this->starts[$segment], $end);
            }

            return;
        }

        $ranges = $unicodes->ranges;
        $rangeCount = \count($ranges);
        $rangeIndex = 0;

        foreach ($this->ends as $segment => $end) {
            $start = $this->starts[$segment];

            while ($rangeIndex < $rangeCount && $ranges[$rangeIndex]->end < $start) {
                ++$rangeIndex;
            }

            for ($index = $rangeIndex; $index < $rangeCount && $ranges[$index]->start <= $end; ++$index) {
                yield from $this->segmentMappings(
                    $segment,
                    max($start, $ranges[$index]->start),
                    min($end, $ranges[$index]->end),
                );
            }
        }
    }

    /**
     * Number of mapped codepoints, computed from the segments.
     */
    public function count(): int
    {
        return max(0, $this->codepointCount);
    }

    public static function parse(BinaryReader $reader): self
    {
        if (0 !== $reader->uint16(0)) {
            throw new InvalidFontException('Unsupported cmap table version.');
        }

        $numTables = $reader->uint16(2);
        $bestFormat4Offset = null;
        $bestFormat12Offset = null;

        for ($i = 0; $i < $numTables; ++$i) {
            $recordOffset = 4 + $i * 8;
            $platformId = $reader->uint16($recordOffset);
            $encodingId = $reader->uint16($recordOffset + 2);
            $subtableOffset = $reader->uint32($recordOffset + 4);
            $format = $reader->uint16($subtableOffset);

            if (12 === $format && self::isPreferredCmap($platformId, $encodingId)) {
                $bestFormat12Offset = $subtableOffset;
            }

            if (4 === $format && self::isPreferredCmap($platformId, $encodingId)) {
                $bestFormat4Offset = $subtableOffset;
            }
        }

        if (null !== $bestFormat12Offset) {
            return self::parseFormat12($reader, $bestFormat12Offset);
        }

        if (null !== $bestFormat4Offset) {
            return self::parseFormat4($reader, $bestFormat4Offset);
        }

        throw new InvalidFontException('No supported cmap format 4 or 12 subtable found.');
    }

    private static function isPreferredCmap(int $platformId, int $encodingId): bool
    {
        return 0 === $platformId || (3 === $platformId && \in_array($encodingId, [1, 10], true));
    }

    private static function parseFormat4(BinaryReader $reader, int $offset): self
    {
        $length = $reader->uint16($offset + 2);
        $segCount = intdiv($reader->uint16($offset + 6), 2);
        $endCodeOffset = $offset + 14;
        $startCodeOffset = $endCodeOffset + $segCount * 2 + 2;
        $idDeltaOffset = $startCodeOffset + $segCount * 2;
        $idRangeOffsetOffset = $idDeltaOffset + $segCount * 2;
        $glyphIdArray = $reader->string(
            $idRangeOffsetOffset,
            max(0, min($offset + $length, $reader->length()) - $idRangeOffsetOffset),
        );
        $starts = [];
        $ends = [];
        $deltas = [];
        $rangeOffsets = [];
        $previousEnd = -1;
        $codepointCount = 0;

        for ($segment = 0; $segment < $segCount; ++$segment) {
            $endCode = $reader->uint16($endCodeOffset + $segment * 2);
            $startCode = $reader->uint16($startCodeOffset + $segment * 2);

            if ($startCode > $endCode) {
                throw new InvalidFontException(\sprintf('cmap format 4 segment %d starts after it ends.', $segment));
            }

            if ($startCode <= $previousEnd) {
                throw new InvalidFontException('cmap format 4 segments must be sorted and must not overlap.');
            }

            $previousEnd = $endCode;

            if (0xFFFF === $startCode && 0xFFFF === $endCode) {
                continue;
            }

            $rangeOffset = $reader->uint16($idRangeOffsetOffset + $segment * 2);
            $starts[] = $startCode;
            $ends[] = $endCode;
            $deltas[] = $reader->uint16($idDeltaOffset + $segment * 2);
            $rangeOffsets[] = $rangeOffset;

            if (0 === $rangeOffset) {
                $codepointCount += $endCode - $startCode + 1;
                continue;
            }

            // Codepoints whose glyph index lies beyond the subtable stay unmapped.
            $lastMapped = $startCode + intdiv(\strlen($glyphIdArray) - 2 - $segment * 2 - $rangeOffset, 2);
            $codepointCount += max(0, min($endCode, $lastMapped) - $startCode + 1);
        }

        return new self(self::FORMAT_4, $starts, $ends, [], $deltas, $rangeOffsets, $glyphIdArray, $codepointCount);
    }

    private static function parseFormat12(BinaryReader $reader, int $offset): self
    {
        if (0 !== $reader->uint16($offset + 2)) {
            throw new InvalidFontException('Unsupported cmap format 12 reserved value.');
        }

        $groups = $reader->uint32($offset + 12);
        $starts = [];
        $ends = [];
        $glyphStarts = [];
        $previousEnd = -1;
        $codepointCount = 0;

        for ($group = 0; $group < $groups; ++$group) {
            $groupOffset = $offset + 16 + $group * 12;
            $startCharCode = $reader->uint32($groupOffset);
            $endCharCode = $reader->uint32($groupOffset + 4);

            if ($startCharCode > $endCharCode) {
                throw new InvalidFontException(\sprintf('cmap format 12 group %d starts after it ends.', $group));
            }

            if ($endCharCode > 0x10FFFF) {
                throw new InvalidFontException(\sprintf('cmap format 12 group %d ends beyond U+10FFFF.', $group));
            }

            if ($startCharCode <= $previousEnd) {
                throw new InvalidFontException('cmap format 12 groups must be sorted and must not overlap.');
            }

            $previousEnd = $endCharCode;
            $starts[] = $startCharCode;
            $ends[] = $endCharCode;
            $glyphStarts[] = $reader->uint32($groupOffset + 8);
            $codepointCount += $endCharCode - $startCharCode + 1;
        }

        return new self(self::FORMAT_12, $starts, $ends, $glyphStarts, [], [], '', $codepointCount);
    }

    /**
     * @return \Generator<int, int>
     */
    private function segmentMappings(int $segment, int $from, int $to): \Generator
    {
        if (self::FORMAT_12 === $this->format) {
            $glyphId = $this->glyphStarts[$segment] + ($from - $this->starts[$segment]);

            for ($codepoint = $from; $codepoint <= $to; ++$codepoint) {
                yield $codepoint => $glyphId++;
            }

            return;
        }

        if (0 === $this->rangeOffsets[$segment]) {
            $delta = $this->deltas[$segment];

            for ($codepoint = $from; $codepoint <= $to; ++$codepoint) {
                yield $codepoint => ($codepoint + $delta) & 0xFFFF;
            }

            return;
        }

        for ($codepoint = $from; $codepoint <= $to; ++$codepoint) {
            $glyphId = $this->glyphIdFromArray($segment, $codepoint);

            if (null === $glyphId) {
                return;
            }

            yield $codepoint => $glyphId;
        }
    }

    /**
     * Resolves a format 4 codepoint through the glyphIdArray; null when the
     * computed index lies outside the subtable.
     */
    private function glyphIdFromArray(int $segment, int $codepoint): ?int
    {
        $glyphOffset = $segment * 2 + $this->rangeOffsets[$segment] + ($codepoint - $this->starts[$segment]) * 2;

        if ($glyphOffset + 2 > \strlen($this->glyphIdArray)) {
            return null;
        }

        $glyphId = (\ord($this->glyphIdArray[$glyphOffset]) << 8) | \ord($this->glyphIdArray[$glyphOffset + 1]);

        return 0 === $glyphId ? 0 : ($glyphId + $this->deltas[$segment]) & 0xFFFF;
    }
}
