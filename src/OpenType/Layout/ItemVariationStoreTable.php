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

/**
 * Relocates an item variation store without changing delta-set indexes.
 *
 * @author Simon André <smn.andre@gmail.com>
 *
 * @internal
 */
final readonly class ItemVariationStoreTable
{
    /**
     * Source ranges are absolute, sorted, half-open byte intervals. Gaps and
     * unrelated subtables are not included in the serialized output.
     *
     * @return array{data: string, ranges: list<array{int, int}>}
     */
    public static function parse(BinaryReader $reader, int $offset, ?int $expectedAxisCount = null): array
    {
        if ($offset < 0 || $offset + 8 > $reader->length() || 1 !== $reader->uint16($offset)) {
            throw new InvalidFontException('ItemVariationStore is invalid.');
        }

        $dataCount = $reader->uint16($offset + 6);
        $headerLength = 8 + $dataCount * 4;
        $headerEnd = $offset + $headerLength;
        $regionListOffset = $reader->uint32($offset + 2);

        if ($headerEnd > $reader->length() || $regionListOffset < $headerLength) {
            throw new InvalidFontException('ItemVariationStore header is invalid.');
        }

        $regionStart = $offset + $regionListOffset;

        if ($regionStart + 4 > $reader->length()) {
            throw new InvalidFontException('ItemVariationStore region list offset is invalid.');
        }

        $axisCount = $reader->uint16($regionStart);
        $regionCount = $reader->uint16($regionStart + 2);

        if (null !== $expectedAxisCount && $axisCount !== $expectedAxisCount) {
            throw new InvalidFontException('ItemVariationStore axis count does not match fvar.');
        }

        if (0 !== ($regionCount & 0x8000)) {
            throw new InvalidFontException('ItemVariationStore region count uses reserved bits.');
        }

        $regionLength = 4 + $axisCount * $regionCount * 6;

        if ($regionStart + $regionLength > $reader->length()) {
            throw new InvalidFontException('ItemVariationStore region list length is invalid.');
        }

        $ranges = [[$offset, $headerEnd], [$regionStart, $regionStart + $regionLength]];
        $payload = $reader->string($regionStart, $regionLength);
        $outputOffsets = [];
        $relocated = [];

        for ($index = 0; $index < $dataCount; ++$index) {
            $dataOffset = $reader->uint32($offset + 8 + $index * 4);

            if (0 === $dataOffset) {
                $outputOffsets[] = 0;
                continue;
            }

            if (!isset($relocated[$dataOffset])) {
                $dataStart = $offset + $dataOffset;

                if ($dataOffset < $headerLength || $dataStart + 6 > $reader->length()) {
                    throw new InvalidFontException('ItemVariationStore data offset is invalid.');
                }

                $dataEnd = self::dataEnd($reader, $dataStart, $regionCount);
                $ranges[] = [$dataStart, $dataEnd];
                $relocated[$dataOffset] = $headerLength + \strlen($payload);
                $payload .= $reader->string($dataStart, $dataEnd - $dataStart);
            }

            $outputOffsets[] = $relocated[$dataOffset];
        }

        usort($ranges, static fn(array $left, array $right): int => $left[0] <=> $right[0]);
        $previousEnd = 0;

        foreach ($ranges as [$start, $end]) {
            if ($start < $previousEnd) {
                throw new InvalidFontException('ItemVariationStore structures overlap.');
            }

            $previousEnd = $end;
        }

        $header = pack('nNn', 1, $headerLength, $dataCount);

        foreach ($outputOffsets as $outputOffset) {
            $header .= pack('N', $outputOffset);
        }

        return ['data' => $header . $payload, 'ranges' => $ranges];
    }

    private static function dataEnd(BinaryReader $reader, int $offset, int $regionCount): int
    {
        $itemCount = $reader->uint16($offset);
        $wordDeltaCountField = $reader->uint16($offset + 2);
        $wordDeltaCount = $wordDeltaCountField & 0x7FFF;
        $regionIndexCount = $reader->uint16($offset + 4);

        if ($wordDeltaCount > $regionIndexCount) {
            throw new InvalidFontException('ItemVariationStore word delta count is invalid.');
        }

        $headerEnd = $offset + 6 + $regionIndexCount * 2;

        if ($headerEnd > $reader->length()) {
            throw new InvalidFontException('ItemVariationStore data header length is invalid.');
        }

        for ($index = 0; $index < $regionIndexCount; ++$index) {
            if ($reader->uint16($offset + 6 + $index * 2) >= $regionCount) {
                throw new InvalidFontException('ItemVariationStore region index is invalid.');
            }
        }

        $rowLength = ($regionIndexCount + $wordDeltaCount) * (0 !== ($wordDeltaCountField & 0x8000) ? 2 : 1);
        $end = $headerEnd + $itemCount * $rowLength;

        if ($end > $reader->length()) {
            throw new InvalidFontException('ItemVariationStore data length is invalid.');
        }

        return $end;
    }
}
