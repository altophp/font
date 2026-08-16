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

/**
 * @internal
 */
final readonly class Woff2TransformEncoder
{
    private const int ON_CURVE_POINT = 0x01;
    private const int X_SHORT_VECTOR = 0x02;
    private const int Y_SHORT_VECTOR = 0x04;
    private const int REPEAT_FLAG = 0x08;
    private const int X_IS_SAME_OR_POSITIVE_X_SHORT_VECTOR = 0x10;
    private const int Y_IS_SAME_OR_POSITIVE_Y_SHORT_VECTOR = 0x20;
    private const int OVERLAP_SIMPLE = 0x40;
    private const int CUBIC_POINT = 0x80;
    private const int ARG_1_AND_2_ARE_WORDS = 0x0001;
    private const int WE_HAVE_A_SCALE = 0x0008;
    private const int MORE_COMPONENTS = 0x0020;
    private const int WE_HAVE_AN_X_AND_Y_SCALE = 0x0040;
    private const int WE_HAVE_A_TWO_BY_TWO = 0x0080;
    private const int WE_HAVE_INSTRUCTIONS = 0x0100;

    /**
     * @return array{list<string>, array<string, array{data: string, originalLength: int, reconstructedLength: int, transformVersion: int}>}
     */
    public static function encode(SfntDocument $document): array
    {
        $entries = [];

        foreach ($document->tableTags() as $tag) {
            $table = $document->table($tag);

            if (null === $table) {
                throw new InvalidFontException(\sprintf('SFNT table "%s" disappeared while transforming WOFF2.', $tag));
            }

            $entries[$tag] = [
                'data' => $table,
                'originalLength' => \strlen($table),
                'reconstructedLength' => \strlen($table),
                'transformVersion' => \in_array($tag, ['glyf', 'loca'], true) ? 3 : 0,
            ];
        }

        $glyphData = self::glyphData($entries);

        if (null !== $glyphData['transformed']) {
            $entries['glyf'] = [
                'data' => $glyphData['transformed'],
                'originalLength' => $entries['glyf']['originalLength'],
                'reconstructedLength' => $glyphData['reconstructedLength'],
                'transformVersion' => 0,
            ];
            $entries['loca'] = [
                'data' => '',
                'originalLength' => $entries['loca']['originalLength'],
                'reconstructedLength' => $entries['loca']['reconstructedLength'],
                'transformVersion' => 0,
            ];
        }

        if (isset($entries['hmtx'])) {
            $transformedHmtx = self::transformHmtx($entries, $glyphData['xMins']);

            if (null !== $transformedHmtx) {
                $entries['hmtx'] = [
                    'data' => $transformedHmtx,
                    'originalLength' => $entries['hmtx']['originalLength'],
                    'reconstructedLength' => $entries['hmtx']['reconstructedLength'],
                    'transformVersion' => 1,
                ];
            }
        }

        $tags = array_keys($entries);

        if (isset($entries['glyf'], $entries['loca'])) {
            $tags = array_values(array_filter($tags, static fn(string $tag): bool => 'loca' !== $tag));
            $glyfIndex = array_search('glyf', $tags, true);

            if (!\is_int($glyfIndex)) {
                throw new \LogicException('Transformed WOFF2 loca table has no glyf table.');
            }

            array_splice($tags, $glyfIndex + 1, 0, ['loca']);
        }

        return [$tags, $entries];
    }

    /**
     * @param array<string, array{data: string, originalLength: int, reconstructedLength: int, transformVersion: int}> $entries
     *
     * @return array{transformed: ?string, reconstructedLength: int, xMins: list<int>}
     */
    private static function glyphData(array $entries): array
    {
        foreach (['glyf', 'head', 'loca', 'maxp'] as $tag) {
            if (!isset($entries[$tag])) {
                return ['transformed' => null, 'reconstructedLength' => 0, 'xMins' => []];
            }
        }

        $glyf = new BinaryReader($entries['glyf']['data'], 'WOFF2 source glyf table');
        $head = new BinaryReader($entries['head']['data'], 'WOFF2 source head table');
        $loca = new BinaryReader($entries['loca']['data'], 'WOFF2 source loca table');
        $maxp = new BinaryReader($entries['maxp']['data'], 'WOFF2 source maxp table');
        $numGlyphs = $maxp->uint16(4);
        $indexFormat = $head->int16(50);

        if (!\in_array($indexFormat, [0, 1], true)) {
            throw new InvalidFontException(\sprintf('SFNT head table uses invalid loca format %d.', $indexFormat));
        }

        $locaEntrySize = 0 === $indexFormat ? 2 : 4;
        $expectedLocaLength = ($numGlyphs + 1) * $locaEntrySize;

        if ($loca->length() !== $expectedLocaLength) {
            throw new InvalidFontException('SFNT loca table length does not match the glyph count.');
        }

        $offsets = [];

        for ($glyphId = 0; $glyphId <= $numGlyphs; ++$glyphId) {
            $offset = 0 === $indexFormat ? $loca->uint16($glyphId * 2) * 2 : $loca->uint32($glyphId * 4);

            if (($offsets[$glyphId - 1] ?? 0) > $offset || $offset > $glyf->length()) {
                throw new InvalidFontException('SFNT loca offsets are invalid.');
            }

            $offsets[] = $offset;
        }

        $streams = [
            'nContour' => '',
            'nPoints' => '',
            'flag' => '',
            'glyph' => '',
            'composite' => '',
            'bbox' => '',
            'instruction' => '',
        ];
        $bboxBitmap = str_repeat("\0", intdiv($numGlyphs + 31, 32) * 4);
        $overlapBitmap = str_repeat("\0", intdiv($numGlyphs + 7, 8));
        $hasOverlap = false;
        $xMins = [];
        $transformable = true;
        $reconstructedLength = 0;

        for ($glyphId = 0; $glyphId < $numGlyphs; ++$glyphId) {
            $length = $offsets[$glyphId + 1] - $offsets[$glyphId];

            if (0 === $length) {
                $streams['nContour'] .= self::int16(0);
                $xMins[] = 0;

                continue;
            }

            $glyph = new BinaryReader(
                $glyf->string($offsets[$glyphId], $length),
                \sprintf('WOFF2 source glyph %d', $glyphId),
            );
            $numberOfContours = $glyph->int16(0);
            $streams['nContour'] .= self::int16($numberOfContours);

            if (0 === $numberOfContours) {
                $xMins[] = $glyph->length() >= 4 ? $glyph->int16(2) : 0;
                $transformable = false;

                continue;
            }

            $xMins[] = $glyph->int16(2);

            if ($numberOfContours > 0) {
                $glyphReconstructedLength = 0;
                $transformable = self::encodeSimpleGlyph(
                    $glyph,
                    $numberOfContours,
                    $glyphId,
                    $streams,
                    $bboxBitmap,
                    $overlapBitmap,
                    $hasOverlap,
                    $glyphReconstructedLength,
                ) && $transformable;
                $reconstructedLength += self::round4($glyphReconstructedLength);

                continue;
            }

            if (-1 === $numberOfContours) {
                $glyphReconstructedLength = 0;
                $transformable = self::encodeCompositeGlyph($glyph, $glyphId, $streams, $bboxBitmap, $glyphReconstructedLength) && $transformable;
                $reconstructedLength += self::round4($glyphReconstructedLength);

                continue;
            }

            throw new InvalidFontException(\sprintf('SFNT glyph %d uses invalid contour count %d.', $glyphId, $numberOfContours));
        }

        if (!$transformable) {
            return ['transformed' => null, 'reconstructedLength' => 0, 'xMins' => $xMins];
        }

        if (0 === $indexFormat && $reconstructedLength > 0x1FFFE) {
            return ['transformed' => null, 'reconstructedLength' => 0, 'xMins' => $xMins];
        }

        $streams['bbox'] = $bboxBitmap . $streams['bbox'];
        $header = self::uint16(0)
            . self::uint16($hasOverlap ? 1 : 0)
            . self::uint16($numGlyphs)
            . self::uint16($indexFormat);

        foreach ($streams as $stream) {
            $header .= self::uint32(\strlen($stream));
        }

        return [
            'transformed' => $header . implode('', $streams) . ($hasOverlap ? $overlapBitmap : ''),
            'reconstructedLength' => $reconstructedLength,
            'xMins' => $xMins,
        ];
    }

    /**
     * @param array{nContour: string, nPoints: string, flag: string, glyph: string, composite: string, bbox: string, instruction: string} $streams
     */
    private static function encodeSimpleGlyph(
        BinaryReader $glyph,
        int $numberOfContours,
        int $glyphId,
        array &$streams,
        string &$bboxBitmap,
        string &$overlapBitmap,
        bool &$hasOverlap,
        int &$reconstructedLength,
    ): bool {
        $cursor = 10;
        $endPoints = [];
        $lastEndPoint = -1;

        for ($contour = 0; $contour < $numberOfContours; ++$contour) {
            $endPoint = $glyph->uint16($cursor);
            $cursor += 2;

            if ($endPoint <= $lastEndPoint) {
                throw new InvalidFontException(\sprintf('SFNT glyph %d has invalid contour endpoints.', $glyphId));
            }

            $streams['nPoints'] .= self::uint255($endPoint - $lastEndPoint);
            $endPoints[] = $endPoint;
            $lastEndPoint = $endPoint;
        }

        $instructionLength = $glyph->uint16($cursor);
        $cursor += 2;
        $instructions = $glyph->string($cursor, $instructionLength);
        $cursor += $instructionLength;
        $pointCount = $lastEndPoint + 1;
        $flags = [];

        while (\count($flags) < $pointCount) {
            $flag = $glyph->uint8($cursor++);
            $repeatCount = 0 !== ($flag & self::REPEAT_FLAG) ? $glyph->uint8($cursor++) : 0;

            if (\count($flags) + $repeatCount + 1 > $pointCount) {
                throw new InvalidFontException(\sprintf('SFNT glyph %d repeats flags beyond its point count.', $glyphId));
            }

            for ($repeat = 0; $repeat <= $repeatCount; ++$repeat) {
                $flags[] = $flag;
            }
        }

        if ([] === $flags) {
            throw new InvalidFontException(\sprintf('SFNT glyph %d contains no points.', $glyphId));
        }

        if ([] !== array_filter($flags, static fn(int $flag): bool => 0 !== ($flag & self::CUBIC_POINT))) {
            return false;
        }

        $xCoordinates = [];
        $x = 0;

        foreach ($flags as $flag) {
            if (0 !== ($flag & self::X_SHORT_VECTOR)) {
                $delta = $glyph->uint8($cursor++);
                $delta = 0 !== ($flag & self::X_IS_SAME_OR_POSITIVE_X_SHORT_VECTOR) ? $delta : -$delta;
            } elseif (0 !== ($flag & self::X_IS_SAME_OR_POSITIVE_X_SHORT_VECTOR)) {
                $delta = 0;
            } else {
                $delta = $glyph->int16($cursor);
                $cursor += 2;
            }

            $x += $delta;
            self::assertInt16($x, 'SFNT glyph x coordinate');
            $xCoordinates[] = $x;
        }

        $yCoordinates = [];
        $y = 0;

        foreach ($flags as $flag) {
            if (0 !== ($flag & self::Y_SHORT_VECTOR)) {
                $delta = $glyph->uint8($cursor++);
                $delta = 0 !== ($flag & self::Y_IS_SAME_OR_POSITIVE_Y_SHORT_VECTOR) ? $delta : -$delta;
            } elseif (0 !== ($flag & self::Y_IS_SAME_OR_POSITIVE_Y_SHORT_VECTOR)) {
                $delta = 0;
            } else {
                $delta = $glyph->int16($cursor);
                $cursor += 2;
            }

            $y += $delta;
            self::assertInt16($y, 'SFNT glyph y coordinate');
            $yCoordinates[] = $y;
        }

        if (!self::hasOnlyZeroPadding($glyph, $cursor)) {
            return false;
        }

        $reconstructedLength = self::simpleGlyphLength(
            $numberOfContours,
            $instructionLength,
            $flags,
            $xCoordinates,
            $yCoordinates,
            0 !== ($flags[0] & self::OVERLAP_SIMPLE),
        );

        self::encodeTriplets($flags, $xCoordinates, $yCoordinates, $streams);
        $streams['glyph'] .= self::uint255($instructionLength);
        $streams['instruction'] .= $instructions;
        $bounds = [$glyph->int16(2), $glyph->int16(4), $glyph->int16(6), $glyph->int16(8)];
        $calculatedBounds = [min($xCoordinates), min($yCoordinates), max($xCoordinates), max($yCoordinates)];

        if ($bounds !== $calculatedBounds) {
            self::setBitmapBit($bboxBitmap, $glyphId);
            $streams['bbox'] .= self::boundingBox($bounds);
        }

        if (0 !== ($flags[0] & self::OVERLAP_SIMPLE)) {
            self::setBitmapBit($overlapBitmap, $glyphId);
            $hasOverlap = true;
        }

        return true;
    }

    /**
     * @param array{nContour: string, nPoints: string, flag: string, glyph: string, composite: string, bbox: string, instruction: string} $streams
     */
    private static function encodeCompositeGlyph(
        BinaryReader $glyph,
        int $glyphId,
        array &$streams,
        string &$bboxBitmap,
        int &$reconstructedLength,
    ): bool {
        $cursor = 10;
        $componentStart = $cursor;
        $hasInstructions = false;

        do {
            $flags = $glyph->uint16($cursor);
            $cursor += 4;
            $cursor += 0 !== ($flags & self::ARG_1_AND_2_ARE_WORDS) ? 4 : 2;

            if (0 !== ($flags & self::WE_HAVE_A_SCALE)) {
                $cursor += 2;
            } elseif (0 !== ($flags & self::WE_HAVE_AN_X_AND_Y_SCALE)) {
                $cursor += 4;
            } elseif (0 !== ($flags & self::WE_HAVE_A_TWO_BY_TWO)) {
                $cursor += 8;
            }

            $glyph->string($cursor, 0);
            $hasInstructions = $hasInstructions || 0 !== ($flags & self::WE_HAVE_INSTRUCTIONS);
        } while (0 !== ($flags & self::MORE_COMPONENTS));

        $componentLength = $cursor - $componentStart;
        $streams['composite'] .= $glyph->string($componentStart, $componentLength);

        if ($hasInstructions) {
            $instructionLength = $glyph->uint16($cursor);
            $cursor += 2;
            $streams['glyph'] .= self::uint255($instructionLength);
            $streams['instruction'] .= $glyph->string($cursor, $instructionLength);
            $cursor += $instructionLength;
        }

        if (!self::hasOnlyZeroPadding($glyph, $cursor)) {
            return false;
        }

        $reconstructedLength = $cursor;

        self::setBitmapBit($bboxBitmap, $glyphId);
        $streams['bbox'] .= self::boundingBox([
            $glyph->int16(2),
            $glyph->int16(4),
            $glyph->int16(6),
            $glyph->int16(8),
        ]);

        return true;
    }

    /**
     * @param list<int>                                                                                                                             $flags
     * @param list<int>                                                                                                                             $xCoordinates
     * @param list<int>                                                                                                                             $yCoordinates
     * @param array{nContour: string, nPoints: string, flag: string, glyph: string, composite: string, bbox: string, instruction: string} $streams
     */
    private static function encodeTriplets(array $flags, array $xCoordinates, array $yCoordinates, array &$streams): void
    {
        $previousX = $previousY = 0;

        foreach ($flags as $index => $pointFlag) {
            $x = $xCoordinates[$index] - $previousX;
            $y = $yCoordinates[$index] - $previousY;
            $previousX = $xCoordinates[$index];
            $previousY = $yCoordinates[$index];
            $absX = abs($x);
            $absY = abs($y);
            $onCurveBit = 0 !== ($pointFlag & self::ON_CURVE_POINT) ? 0 : 0x80;
            $xSignBit = $x < 0 ? 0 : 1;
            $ySignBit = $y < 0 ? 0 : 1;
            $xySignBits = $xSignBit + 2 * $ySignBit;

            if (0 === $x && $absY < 1280) {
                $streams['flag'] .= self::byte($onCurveBit + (($absY & 0xF00) >> 7) + $ySignBit);
                $streams['glyph'] .= self::byte($absY & 0xFF);
            } elseif (0 === $y && $absX < 1280) {
                $streams['flag'] .= self::byte($onCurveBit + 10 + (($absX & 0xF00) >> 7) + $xSignBit);
                $streams['glyph'] .= self::byte($absX & 0xFF);
            } elseif ($absX < 65 && $absY < 65) {
                $streams['flag'] .= self::byte($onCurveBit + 20 + (($absX - 1) & 0x30) + ((($absY - 1) & 0x30) >> 2) + $xySignBits);
                $streams['glyph'] .= self::byte(((($absX - 1) & 0x0F) << 4) | (($absY - 1) & 0x0F));
            } elseif ($absX < 769 && $absY < 769) {
                $streams['flag'] .= self::byte($onCurveBit + 84 + 12 * ((($absX - 1) & 0x300) >> 8) + ((($absY - 1) & 0x300) >> 6) + $xySignBits);
                $streams['glyph'] .= self::byte(($absX - 1) & 0xFF) . self::byte(($absY - 1) & 0xFF);
            } elseif ($absX < 4096 && $absY < 4096) {
                $streams['flag'] .= self::byte($onCurveBit + 120 + $xySignBits);
                $streams['glyph'] .= self::byte($absX >> 4) . self::byte((($absX & 0x0F) << 4) | ($absY >> 8)) . self::byte($absY & 0xFF);
            } else {
                $streams['flag'] .= self::byte($onCurveBit + 124 + $xySignBits);
                $streams['glyph'] .= self::uint16($absX) . self::uint16($absY);
            }
        }
    }

    /**
     * @param array<string, array{data: string, originalLength: int, reconstructedLength: int, transformVersion: int}> $entries
     * @param list<int>                                                                        $xMins
     */
    private static function transformHmtx(array $entries, array $xMins): ?string
    {
        foreach (['glyf', 'hhea', 'maxp'] as $tag) {
            if (!isset($entries[$tag])) {
                return null;
            }
        }

        $hhea = new BinaryReader($entries['hhea']['data'], 'WOFF2 source hhea table');
        $maxp = new BinaryReader($entries['maxp']['data'], 'WOFF2 source maxp table');
        $hmtx = new BinaryReader($entries['hmtx']['data'], 'WOFF2 source hmtx table');
        $numGlyphs = $maxp->uint16(4);
        $numberOfHMetrics = $hhea->uint16(34);

        if (0 === $numberOfHMetrics || $numberOfHMetrics > $numGlyphs || \count($xMins) !== $numGlyphs) {
            throw new InvalidFontException('SFNT hmtx metrics counts are invalid.');
        }

        $expectedLength = $numberOfHMetrics * 4 + ($numGlyphs - $numberOfHMetrics) * 2;

        if ($hmtx->length() !== $expectedLength) {
            return null;
        }

        $advanceWidths = '';
        $proportionalBearings = '';
        $trailingBearings = '';
        $proportionalMatch = true;
        $trailingMatch = true;

        for ($glyphId = 0; $glyphId < $numGlyphs; ++$glyphId) {
            if ($glyphId < $numberOfHMetrics) {
                $advanceWidths .= $hmtx->string($glyphId * 4, 2);
                $bearing = $hmtx->int16($glyphId * 4 + 2);
                $proportionalBearings .= self::int16($bearing);
                $proportionalMatch = $proportionalMatch && $bearing === $xMins[$glyphId];
            } else {
                $bearing = $hmtx->int16($numberOfHMetrics * 4 + ($glyphId - $numberOfHMetrics) * 2);
                $trailingBearings .= self::int16($bearing);
                $trailingMatch = $trailingMatch && $bearing === $xMins[$glyphId];
            }
        }

        if (!$proportionalMatch && !$trailingMatch) {
            return null;
        }

        $flags = ($proportionalMatch ? 0x01 : 0) | ($trailingMatch ? 0x02 : 0);

        return \chr($flags)
            . $advanceWidths
            . ($proportionalMatch ? '' : $proportionalBearings)
            . ($trailingMatch ? '' : $trailingBearings);
    }

    private static function setBitmapBit(string &$bitmap, int $index): void
    {
        $byteIndex = intdiv($index, 8);
        $bitmap[$byteIndex] = self::byte(\ord($bitmap[$byteIndex]) | (0x80 >> ($index & 7)));
    }

    private static function hasOnlyZeroPadding(BinaryReader $glyph, int $cursor): bool
    {
        $trailingLength = $glyph->length() - $cursor;

        return $trailingLength >= 0 && str_repeat("\0", $trailingLength) === $glyph->string($cursor, $trailingLength);
    }

    /**
     * @param list<int> $sourceFlags
     * @param list<int> $xCoordinates
     * @param list<int> $yCoordinates
     */
    private static function simpleGlyphLength(
        int $numberOfContours,
        int $instructionLength,
        array $sourceFlags,
        array $xCoordinates,
        array $yCoordinates,
        bool $overlap,
    ): int {
        $flags = [];
        $coordinateLength = 0;
        $previousX = $previousY = 0;

        foreach ($sourceFlags as $index => $sourceFlag) {
            $flag = 0 !== ($sourceFlag & self::ON_CURVE_POINT) ? self::ON_CURVE_POINT : 0;

            if (0 === $index && $overlap) {
                $flag |= self::OVERLAP_SIMPLE;
            }

            $xDelta = $xCoordinates[$index] - $previousX;
            $yDelta = $yCoordinates[$index] - $previousY;
            $previousX = $xCoordinates[$index];
            $previousY = $yCoordinates[$index];

            if (0 === $xDelta) {
                $flag |= self::X_IS_SAME_OR_POSITIVE_X_SHORT_VECTOR;
            } elseif (abs($xDelta) <= 0xFF) {
                $flag |= self::X_SHORT_VECTOR;
                $flag |= $xDelta > 0 ? self::X_IS_SAME_OR_POSITIVE_X_SHORT_VECTOR : 0;
                ++$coordinateLength;
            } else {
                $coordinateLength += 2;
            }

            if (0 === $yDelta) {
                $flag |= self::Y_IS_SAME_OR_POSITIVE_Y_SHORT_VECTOR;
            } elseif (abs($yDelta) <= 0xFF) {
                $flag |= self::Y_SHORT_VECTOR;
                $flag |= $yDelta > 0 ? self::Y_IS_SAME_OR_POSITIVE_Y_SHORT_VECTOR : 0;
                ++$coordinateLength;
            } else {
                $coordinateLength += 2;
            }

            $flags[] = $flag;
        }

        $flagLength = 0;

        for ($index = 0, $count = \count($flags); $index < $count;) {
            $runLength = 1;

            while ($index + $runLength < $count && $flags[$index + $runLength] === $flags[$index] && $runLength < 256) {
                ++$runLength;
            }

            $flagLength += $runLength > 1 ? 2 : 1;
            $index += $runLength;
        }

        return 12 + $numberOfContours * 2 + $instructionLength + $flagLength + $coordinateLength;
    }

    private static function round4(int $value): int
    {
        return ($value + 3) & ~3;
    }

    private static function uint255(int $value): string
    {
        if ($value < 0 || $value > 0xFFFF) {
            throw new InvalidFontException(\sprintf('WOFF2 255UInt16 value %d is out of range.', $value));
        }

        if ($value < 253) {
            return \chr($value);
        }

        if ($value < 506) {
            return "\xFF" . \chr($value - 253);
        }

        if ($value < 762) {
            return "\xFE" . \chr($value - 506);
        }

        return "\xFD" . self::uint16($value);
    }

    /**
     * @param array{0: int, 1: int, 2: int, 3: int} $bounds
     */
    private static function boundingBox(array $bounds): string
    {
        return self::int16($bounds[0]) . self::int16($bounds[1]) . self::int16($bounds[2]) . self::int16($bounds[3]);
    }

    private static function assertInt16(int $value, string $label): void
    {
        if ($value < -32768 || $value > 32767) {
            throw new InvalidFontException(\sprintf('%s value %d exceeds int16 bounds.', $label, $value));
        }
    }

    private static function uint16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }

    private static function byte(int $value): string
    {
        if ($value < 0 || $value > 0xFF) {
            throw new InvalidFontException(\sprintf('WOFF2 byte value %d is out of range.', $value));
        }

        return \chr($value);
    }

    private static function int16(int $value): string
    {
        self::assertInt16($value, 'WOFF2 int16');

        return pack('n', $value & 0xFFFF);
    }

    private static function uint32(int $value): string
    {
        return pack('N', $value & 0xFFFFFFFF);
    }
}
