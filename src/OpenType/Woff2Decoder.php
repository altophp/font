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

/**
 * @internal
 */
/**
 * @author Simon André <smn.andre@gmail.com>
 */
final class Woff2Decoder
{
    private const int ARG_1_AND_2_ARE_WORDS = 0x0001;
    private const int MORE_COMPONENTS = 0x0020;
    private const int WE_HAVE_A_SCALE = 0x0008;
    private const int WE_HAVE_AN_X_AND_Y_SCALE = 0x0040;
    private const int WE_HAVE_A_TWO_BY_TWO = 0x0080;
    private const int WE_HAVE_INSTRUCTIONS = 0x0100;
    private const int MAX_DECOMPRESSED_TABLE_BLOCK_SIZE = 100 * 1024 * 1024;

    public static function decode(BinaryReader $woff2): string
    {
        $flavor = $woff2->string(4, 4);

        if ('ttcf' === $flavor) {
            throw new UnsupportedFontException('WOFF2 font collections are not supported.');
        }

        $declaredLength = $woff2->uint32(8);
        $numTables = $woff2->uint16(12);
        $totalCompressedSize = $woff2->uint32(20);

        if ($declaredLength !== $woff2->length()) {
            throw new InvalidFontException('WOFF2 declared length does not match file length.');
        }

        if (0 === $numTables) {
            throw new InvalidFontException('WOFF2 declares no font tables.');
        }

        if (0 !== $woff2->uint16(14)) {
            throw new InvalidFontException('WOFF2 reserved header field must be zero.');
        }

        $cursor = 48;
        $entries = [];
        $tags = [];

        for ($i = 0; $i < $numTables; ++$i) {
            $flags = $woff2->uint8($cursor++);
            $tagIndex = $flags & 0x3F;
            $transformVersion = $flags >> 6;

            if (0x3F === $tagIndex) {
                $tag = $woff2->string($cursor, 4);
                $cursor += 4;
            } else {
                $tag = Woff2KnownTags::at($tagIndex);
            }

            if (isset($tags[$tag])) {
                throw new InvalidFontException(\sprintf('WOFF2 contains duplicate table "%s".', $tag));
            }

            $tags[$tag] = true;
            $originalLength = self::readUIntBase128($woff2, $cursor);
            $transformed = !self::isNullTransform($tag, $transformVersion);
            $transformLength = $transformed ? self::readUIntBase128($woff2, $cursor) : null;

            if ($transformed) {
                self::assertSupportedTransform($tag, $transformVersion);
            }

            $dataLength = $transformLength ?? $originalLength;

            if ($originalLength > self::MAX_DECOMPRESSED_TABLE_BLOCK_SIZE || $dataLength > self::MAX_DECOMPRESSED_TABLE_BLOCK_SIZE) {
                throw new InvalidFontException(\sprintf('WOFF2 table "%s" declares an implausible decompressed size.', $tag));
            }

            $entries[] = [
                'tag' => $tag,
                'originalLength' => $originalLength,
                'transformLength' => $transformLength,
                'transformVersion' => $transformVersion,
                'transformed' => $transformed,
                'dataLength' => $dataLength,
            ];
        }

        self::assertTransformPairs($entries);
        $totalDataLength = array_sum(array_column($entries, 'dataLength'));

        if ($totalDataLength > self::MAX_DECOMPRESSED_TABLE_BLOCK_SIZE) {
            throw new InvalidFontException('WOFF2 declares an implausible total decompressed table size.');
        }

        self::validateBlockLayout($woff2, $cursor, $totalCompressedSize);
        $compressedData = $woff2->string($cursor, $totalCompressedSize);
        $tableBlock = self::brotliDecompress($compressedData);

        if (\strlen($tableBlock) !== $totalDataLength) {
            throw new InvalidFontException('WOFF2 decompressed to an unexpected total length.');
        }

        $tables = [];
        $transformedTables = [];
        $blockCursor = 0;

        foreach ($entries as $entry) {
            $table = substr($tableBlock, $blockCursor, $entry['dataLength']);
            $blockCursor += $entry['dataLength'];

            if ($entry['transformed']) {
                $transformedTables[$entry['tag']] = $table;
            } else {
                $tables[$entry['tag']] = $table;
            }
        }

        $xMins = null;

        if (isset($transformedTables['glyf'])) {
            [$tables['glyf'], $tables['loca'], $xMins, $numGlyphs, $indexFormat] = self::reconstructGlyf($transformedTables['glyf']);
            self::validateGlyfContext($tables, $entries, $numGlyphs, $indexFormat);
        }

        if (isset($transformedTables['hmtx'])) {
            $xMins ??= self::readGlyphXMins($tables);
            $tables['hmtx'] = self::reconstructHmtx($transformedTables['hmtx'], $tables, $xMins);
            $expectedLength = self::entry($entries, 'hmtx')['originalLength'];

            if (\strlen($tables['hmtx']) !== $expectedLength) {
                throw new InvalidFontException('Reconstructed WOFF2 hmtx table has an unexpected length.');
            }
        }

        return SfntBuilder::build($flavor, $tables);
    }

    /**
     * @param list<array{tag: string, originalLength: int, transformLength: ?int, transformVersion: int, transformed: bool, dataLength: int}> $entries
     */
    private static function assertTransformPairs(array $entries): void
    {
        $glyfIndex = null;
        $locaIndex = null;

        foreach ($entries as $index => $entry) {
            if ('glyf' === $entry['tag'] && $entry['transformed']) {
                $glyfIndex = $index;
            }

            if ('loca' === $entry['tag'] && $entry['transformed']) {
                $locaIndex = $index;

                if (0 !== $entry['dataLength']) {
                    throw new InvalidFontException('Transformed WOFF2 loca table must have zero data length.');
                }
            }
        }

        if ((null === $glyfIndex) !== (null === $locaIndex)) {
            throw new InvalidFontException('WOFF2 glyf and loca tables must use matching transforms.');
        }

        if (null !== $glyfIndex && $locaIndex !== $glyfIndex + 1) {
            throw new InvalidFontException('Transformed WOFF2 loca table must immediately follow glyf.');
        }
    }

    private static function isNullTransform(string $tag, int $transformVersion): bool
    {
        if ('glyf' === $tag || 'loca' === $tag) {
            return 3 === $transformVersion;
        }

        return 0 === $transformVersion;
    }

    private static function assertSupportedTransform(string $tag, int $transformVersion): void
    {
        if ((('glyf' === $tag || 'loca' === $tag) && 0 === $transformVersion)
            || ('hmtx' === $tag && 1 === $transformVersion)) {
            return;
        }

        throw new UnsupportedFontException(\sprintf('WOFF2 table "%s" uses unsupported transform version %d.', $tag, $transformVersion));
    }

    /**
     * @return array{0: string, 1: string, 2: list<int>, 3: int, 4: int}
     */
    private static function reconstructGlyf(string $data): array
    {
        $reader = new BinaryReader($data, 'WOFF2 transformed glyf');

        if ($reader->uint16(0) !== 0) {
            throw new InvalidFontException('WOFF2 transformed glyf version must be zero.');
        }

        $optionFlags = $reader->uint16(2);

        if (0 !== ($optionFlags & ~0x0001)) {
            throw new InvalidFontException('WOFF2 transformed glyf option flags contain reserved bits.');
        }

        $numGlyphs = $reader->uint16(4);
        $indexFormat = $reader->uint16(6);

        if (!\in_array($indexFormat, [0, 1], true)) {
            throw new InvalidFontException(\sprintf('WOFF2 transformed glyf uses invalid loca format %d.', $indexFormat));
        }

        $streamNames = ['nContour', 'nPoints', 'flag', 'glyph', 'composite', 'bbox', 'instruction'];
        $streamSizes = [];

        foreach ($streamNames as $index => $name) {
            $streamSizes[$name] = $reader->uint32(8 + $index * 4);
        }

        if ($streamSizes['nContour'] !== $numGlyphs * 2) {
            throw new InvalidFontException('WOFF2 nContour stream length does not match glyph count.');
        }

        $offset = 36;
        $streams = [];

        foreach ($streamNames as $name) {
            $streams[$name] = $reader->string($offset, $streamSizes[$name]);
            $offset += $streamSizes[$name];
        }

        $overlapSize = 0 !== ($optionFlags & 0x0001) ? intdiv($numGlyphs + 7, 8) : 0;
        $overlapBitmap = $reader->string($offset, $overlapSize);
        $offset += $overlapSize;

        if ($offset !== $reader->length()) {
            throw new InvalidFontException('WOFF2 transformed glyf table has trailing data.');
        }

        $bboxBitmapSize = intdiv($numGlyphs + 31, 32) * 4;

        if ($streamSizes['bbox'] < $bboxBitmapSize) {
            throw new InvalidFontException('WOFF2 transformed glyf bbox stream is truncated.');
        }

        $bboxBitmap = substr($streams['bbox'], 0, $bboxBitmapSize);
        $bboxData = substr($streams['bbox'], $bboxBitmapSize);
        $nContourReader = new BinaryReader($streams['nContour'], 'WOFF2 nContour stream');
        $nPointsReader = new BinaryReader($streams['nPoints'], 'WOFF2 nPoints stream');
        $flagReader = new BinaryReader($streams['flag'], 'WOFF2 flag stream');
        $glyphReader = new BinaryReader($streams['glyph'], 'WOFF2 glyph stream');
        $compositeReader = new BinaryReader($streams['composite'], 'WOFF2 composite stream');
        $bboxReader = new BinaryReader($bboxData, 'WOFF2 bbox stream');
        $instructionReader = new BinaryReader($streams['instruction'], 'WOFF2 instruction stream');
        $nPointsCursor = $flagCursor = $glyphCursor = $compositeCursor = $bboxCursor = $instructionCursor = 0;
        $glyf = '';
        $offsets = [];
        $xMins = [];

        for ($glyphId = 0; $glyphId < $numGlyphs; ++$glyphId) {
            $offsets[] = \strlen($glyf);
            $numberOfContours = $nContourReader->int16($glyphId * 2);
            $hasBoundingBox = self::bitmapBit($bboxBitmap, $glyphId);

            if (0 === $numberOfContours) {
                if ($hasBoundingBox) {
                    throw new InvalidFontException(\sprintf('Empty WOFF2 glyph %d has an explicit bounding box.', $glyphId));
                }

                $xMins[] = 0;
                continue;
            }

            if ($numberOfContours > 0) {
                $endPoints = [];
                $endPoint = -1;

                for ($contour = 0; $contour < $numberOfContours; ++$contour) {
                    $pointCount = self::read255UInt16($nPointsReader, $nPointsCursor);

                    if (0 === $pointCount) {
                        throw new InvalidFontException(\sprintf('WOFF2 glyph %d contains an empty contour.', $glyphId));
                    }

                    $endPoint += $pointCount;
                    $endPoints[] = $endPoint;
                }

                $totalPoints = $endPoint + 1;
                $tripletFlags = $flagReader->string($flagCursor, $totalPoints);
                $flagCursor += $totalPoints;
                [$xCoordinates, $yCoordinates, $onCurve] = self::decodeTriplets($tripletFlags, $glyphReader, $glyphCursor);

                if ([] === $xCoordinates || [] === $yCoordinates) {
                    throw new InvalidFontException(\sprintf('WOFF2 glyph %d contains no points.', $glyphId));
                }

                $instructionLength = self::read255UInt16($glyphReader, $glyphCursor);
                $instructions = $instructionReader->string($instructionCursor, $instructionLength);
                $instructionCursor += $instructionLength;
                $bounds = $hasBoundingBox
                    ? self::readBoundingBox($bboxReader, $bboxCursor)
                    : [min($xCoordinates), min($yCoordinates), max($xCoordinates), max($yCoordinates)];
                $overlap = '' !== $overlapBitmap && self::bitmapBit($overlapBitmap, $glyphId);
                $glyph = self::encodeSimpleGlyph($numberOfContours, $bounds, $endPoints, $instructions, $xCoordinates, $yCoordinates, $onCurve, $overlap);
            } elseif (-1 === $numberOfContours) {
                if (!$hasBoundingBox) {
                    throw new InvalidFontException(\sprintf('Composite WOFF2 glyph %d has no explicit bounding box.', $glyphId));
                }

                $componentStart = $compositeCursor;
                $hasInstructions = false;

                do {
                    $flags = $compositeReader->uint16($compositeCursor);
                    $componentGlyphId = $compositeReader->uint16($compositeCursor + 2);

                    if ($componentGlyphId >= $numGlyphs) {
                        throw new InvalidFontException(\sprintf('WOFF2 glyph %d references glyph %d outside the font.', $glyphId, $componentGlyphId));
                    }

                    $compositeCursor += 4;
                    $compositeCursor += 0 !== ($flags & self::ARG_1_AND_2_ARE_WORDS) ? 4 : 2;

                    if (0 !== ($flags & self::WE_HAVE_A_SCALE)) {
                        $compositeCursor += 2;
                    } elseif (0 !== ($flags & self::WE_HAVE_AN_X_AND_Y_SCALE)) {
                        $compositeCursor += 4;
                    } elseif (0 !== ($flags & self::WE_HAVE_A_TWO_BY_TWO)) {
                        $compositeCursor += 8;
                    }

                    $compositeReader->string($compositeCursor, 0);
                    $hasInstructions = $hasInstructions || 0 !== ($flags & self::WE_HAVE_INSTRUCTIONS);
                } while (0 !== ($flags & self::MORE_COMPONENTS));

                $components = $compositeReader->string($componentStart, $compositeCursor - $componentStart);
                $instructions = '';

                if ($hasInstructions) {
                    $instructionLength = self::read255UInt16($glyphReader, $glyphCursor);
                    $instructions = self::uint16($instructionLength) . $instructionReader->string($instructionCursor, $instructionLength);
                    $instructionCursor += $instructionLength;
                }

                $bounds = self::readBoundingBox($bboxReader, $bboxCursor);
                $glyph = self::int16(-1) . self::boundingBox($bounds) . $components . $instructions;
            } else {
                throw new InvalidFontException(\sprintf('WOFF2 glyph %d uses invalid contour count %d.', $glyphId, $numberOfContours));
            }

            $xMins[] = $bounds[0];
            $glyf .= self::pad2($glyph);
        }

        $offsets[] = \strlen($glyf);
        self::assertConsumed($nPointsCursor, $nPointsReader, 'nPoints');
        self::assertConsumed($flagCursor, $flagReader, 'flag');
        self::assertConsumed($glyphCursor, $glyphReader, 'glyph');
        self::assertConsumed($compositeCursor, $compositeReader, 'composite');
        self::assertConsumed($bboxCursor, $bboxReader, 'bbox');
        self::assertConsumed($instructionCursor, $instructionReader, 'instruction');

        return [$glyf, self::encodeLoca($offsets, $indexFormat), $xMins, $numGlyphs, $indexFormat];
    }

    /**
     * @return array{0: list<int>, 1: list<int>, 2: list<bool>}
     */
    private static function decodeTriplets(string $flags, BinaryReader $glyphReader, int &$cursor): array
    {
        $x = $y = 0;
        $xCoordinates = [];
        $yCoordinates = [];
        $onCurve = [];

        for ($i = 0, $count = \strlen($flags); $i < $count; ++$i) {
            $rawFlag = \ord($flags[$i]);
            $onCurve[] = 0 === ($rawFlag & 0x80);
            $flag = $rawFlag & 0x7F;

            if ($flag < 10) {
                $dx = 0;
                $dy = self::withSign($flag, (($flag & 14) << 7) + $glyphReader->uint8($cursor));
                ++$cursor;
            } elseif ($flag < 20) {
                $dx = self::withSign($flag, ((($flag - 10) & 14) << 7) + $glyphReader->uint8($cursor));
                $dy = 0;
                ++$cursor;
            } elseif ($flag < 84) {
                $b0 = $flag - 20;
                $b1 = $glyphReader->uint8($cursor++);
                $dx = self::withSign($flag, 1 + ($b0 & 0x30) + ($b1 >> 4));
                $dy = self::withSign($flag >> 1, 1 + (($b0 & 0x0C) << 2) + ($b1 & 0x0F));
            } elseif ($flag < 120) {
                $b0 = $flag - 84;
                $dx = self::withSign($flag, 1 + intdiv($b0, 12) * 256 + $glyphReader->uint8($cursor));
                $dy = self::withSign($flag >> 1, 1 + intdiv($b0 % 12, 4) * 256 + $glyphReader->uint8($cursor + 1));
                $cursor += 2;
            } elseif ($flag < 124) {
                $b0 = $glyphReader->uint8($cursor);
                $b1 = $glyphReader->uint8($cursor + 1);
                $b2 = $glyphReader->uint8($cursor + 2);
                $dx = self::withSign($flag, ($b0 << 4) + ($b1 >> 4));
                $dy = self::withSign($flag >> 1, (($b1 & 0x0F) << 8) + $b2);
                $cursor += 3;
            } else {
                $dx = self::withSign($flag, $glyphReader->uint16($cursor));
                $dy = self::withSign($flag >> 1, $glyphReader->uint16($cursor + 2));
                $cursor += 4;
            }

            $x += $dx;
            $y += $dy;
            self::assertInt16($x, 'WOFF2 glyph x coordinate');
            self::assertInt16($y, 'WOFF2 glyph y coordinate');
            $xCoordinates[] = $x;
            $yCoordinates[] = $y;
        }

        return [$xCoordinates, $yCoordinates, $onCurve];
    }

    /**
     * @param array{0: int, 1: int, 2: int, 3: int} $bounds
     * @param list<int>                                $endPoints
     * @param list<int>                                $xCoordinates
     * @param list<int>                                $yCoordinates
     * @param list<bool>                               $onCurve
     */
    private static function encodeSimpleGlyph(
        int $numberOfContours,
        array $bounds,
        array $endPoints,
        string $instructions,
        array $xCoordinates,
        array $yCoordinates,
        array $onCurve,
        bool $overlap,
    ): string {
        $glyph = self::int16($numberOfContours) . self::boundingBox($bounds);

        foreach ($endPoints as $endPoint) {
            $glyph .= self::uint16($endPoint);
        }

        $glyph .= self::uint16(\strlen($instructions)) . $instructions;

        foreach ($onCurve as $index => $isOnCurve) {
            $flag = $isOnCurve ? 0x01 : 0x00;

            if (0 === $index && $overlap) {
                $flag |= 0x40;
            }

            $glyph .= \chr($flag);
        }

        $previous = 0;

        foreach ($xCoordinates as $coordinate) {
            $glyph .= self::int16($coordinate - $previous);
            $previous = $coordinate;
        }

        $previous = 0;

        foreach ($yCoordinates as $coordinate) {
            $glyph .= self::int16($coordinate - $previous);
            $previous = $coordinate;
        }

        return $glyph;
    }

    /**
     * @param list<int> $offsets
     */
    private static function encodeLoca(array $offsets, int $indexFormat): string
    {
        $loca = '';

        foreach ($offsets as $offset) {
            if (0 === $indexFormat) {
                if (0 !== $offset % 2 || $offset > 0x1FFFE) {
                    throw new InvalidFontException('Reconstructed WOFF2 glyf offsets do not fit short loca format.');
                }

                $loca .= self::uint16(intdiv($offset, 2));
            } else {
                $loca .= self::uint32($offset);
            }
        }

        return $loca;
    }

    /**
     * @param array<string, string>                                                                                                                 $tables
     * @param list<array{tag: string, originalLength: int, transformLength: ?int, transformVersion: int, transformed: bool, dataLength: int}> $entries
     */
    private static function validateGlyfContext(array $tables, array $entries, int $numGlyphs, int $indexFormat): void
    {
        foreach (['head', 'maxp'] as $tag) {
            if (!isset($tables[$tag])) {
                throw new InvalidFontException(\sprintf('Transformed WOFF2 glyf requires table "%s".', $tag));
            }
        }

        $head = new BinaryReader($tables['head'], 'WOFF2 head table');
        $maxp = new BinaryReader($tables['maxp'], 'WOFF2 maxp table');

        if ($head->int16(50) !== $indexFormat) {
            throw new InvalidFontException('WOFF2 glyf index format does not match head table.');
        }

        if ($maxp->uint16(4) !== $numGlyphs) {
            throw new InvalidFontException('WOFF2 glyf glyph count does not match maxp table.');
        }

        $expectedLocaLength = ($numGlyphs + 1) * (0 === $indexFormat ? 2 : 4);

        if (self::entry($entries, 'loca')['originalLength'] !== $expectedLocaLength || \strlen($tables['loca']) !== $expectedLocaLength) {
            throw new InvalidFontException('Reconstructed WOFF2 loca table has an unexpected length.');
        }
    }

    /**
     * @param array<string, string> $tables
     * @param list<int>             $xMins
     */
    private static function reconstructHmtx(string $data, array $tables, array $xMins): string
    {
        foreach (['hhea', 'maxp'] as $tag) {
            if (!isset($tables[$tag])) {
                throw new InvalidFontException(\sprintf('Transformed WOFF2 hmtx requires table "%s".', $tag));
            }
        }

        $reader = new BinaryReader($data, 'WOFF2 transformed hmtx');
        $flags = $reader->uint8(0);

        if (0 !== ($flags & 0xFC) || 0 === ($flags & 0x03)) {
            throw new InvalidFontException('WOFF2 transformed hmtx flags are invalid.');
        }

        $numGlyphs = (new BinaryReader($tables['maxp'], 'WOFF2 maxp table'))->uint16(4);
        $numberOfHMetrics = (new BinaryReader($tables['hhea'], 'WOFF2 hhea table'))->uint16(34);

        if (0 === $numberOfHMetrics || $numberOfHMetrics > $numGlyphs || \count($xMins) !== $numGlyphs) {
            throw new InvalidFontException('WOFF2 transformed hmtx metrics counts are invalid.');
        }

        $cursor = 1;
        $advanceWidths = [];

        for ($i = 0; $i < $numberOfHMetrics; ++$i) {
            $advanceWidths[] = $reader->uint16($cursor);
            $cursor += 2;
        }

        $leftSideBearings = [];

        if (0 === ($flags & 0x01)) {
            for ($i = 0; $i < $numberOfHMetrics; ++$i) {
                $leftSideBearings[] = $reader->int16($cursor);
                $cursor += 2;
            }
        } else {
            $leftSideBearings = array_slice($xMins, 0, $numberOfHMetrics);
        }

        $trailingSideBearings = [];

        if (0 === ($flags & 0x02)) {
            for ($i = $numberOfHMetrics; $i < $numGlyphs; ++$i) {
                $trailingSideBearings[] = $reader->int16($cursor);
                $cursor += 2;
            }
        } else {
            $trailingSideBearings = array_slice($xMins, $numberOfHMetrics);
        }

        if ($cursor !== $reader->length()) {
            throw new InvalidFontException('WOFF2 transformed hmtx table has trailing data.');
        }

        $hmtx = '';

        foreach ($advanceWidths as $index => $advanceWidth) {
            $hmtx .= self::uint16($advanceWidth) . self::int16($leftSideBearings[$index]);
        }

        foreach ($trailingSideBearings as $leftSideBearing) {
            $hmtx .= self::int16($leftSideBearing);
        }

        return $hmtx;
    }

    /**
     * @param array<string, string> $tables
     *
     * @return list<int>
     */
    private static function readGlyphXMins(array $tables): array
    {
        foreach (['glyf', 'head', 'loca', 'maxp'] as $tag) {
            if (!isset($tables[$tag])) {
                throw new InvalidFontException(\sprintf('Transformed WOFF2 hmtx requires table "%s".', $tag));
            }
        }

        $glyf = new BinaryReader($tables['glyf'], 'WOFF2 glyf table');
        $head = new BinaryReader($tables['head'], 'WOFF2 head table');
        $loca = new BinaryReader($tables['loca'], 'WOFF2 loca table');
        $maxp = new BinaryReader($tables['maxp'], 'WOFF2 maxp table');
        $numGlyphs = $maxp->uint16(4);
        $indexFormat = $head->int16(50);
        $offsets = [];

        for ($i = 0; $i <= $numGlyphs; ++$i) {
            $offsets[] = 0 === $indexFormat ? $loca->uint16($i * 2) * 2 : $loca->uint32($i * 4);
        }

        $xMins = [];

        for ($i = 0; $i < $numGlyphs; ++$i) {
            if ($offsets[$i] > $offsets[$i + 1] || $offsets[$i + 1] > $glyf->length()) {
                throw new InvalidFontException('WOFF2 loca offsets are invalid.');
            }

            $xMins[] = $offsets[$i] === $offsets[$i + 1] ? 0 : $glyf->int16($offsets[$i] + 2);
        }

        return $xMins;
    }

    private static function read255UInt16(BinaryReader $reader, int &$cursor): int
    {
        $code = $reader->uint8($cursor++);

        if (253 === $code) {
            $value = $reader->uint16($cursor);
            $cursor += 2;

            return $value;
        }

        if (254 === $code) {
            return 506 + $reader->uint8($cursor++);
        }

        if (255 === $code) {
            return 253 + $reader->uint8($cursor++);
        }

        return $code;
    }

    private static function readUIntBase128(BinaryReader $reader, int &$cursor): int
    {
        $accumulator = 0;

        for ($i = 0; $i < 5; ++$i) {
            $byte = $reader->uint8($cursor++);

            if (0 === $i && 0x80 === $byte) {
                throw new InvalidFontException('WOFF2 UIntBase128 value has leading zeros.');
            }

            if (0 !== ($accumulator & 0xFE000000)) {
                throw new InvalidFontException('WOFF2 UIntBase128 value exceeds 32 bits.');
            }

            $accumulator = ($accumulator << 7) | ($byte & 0x7F);

            if (0 === ($byte & 0x80)) {
                return $accumulator;
            }
        }

        throw new InvalidFontException('WOFF2 UIntBase128 sequence exceeds 5 bytes.');
    }

    private static function brotliDecompress(string $compressedData): string
    {
        foreach (['brotli_uncompress', 'brotli_uncompress_data'] as $function) {
            if (\function_exists($function)) {
                $decompressed = $function($compressedData);

                if (\is_string($decompressed)) {
                    return $decompressed;
                }
            }
        }

        $input = tmpfile();

        if (false === $input) {
            throw new UnsupportedFontException('WOFF2 Brotli decompression could not create temporary streams.');
        }

        $output = tmpfile();

        if (false === $output) {
            fclose($input);

            throw new UnsupportedFontException('WOFF2 Brotli decompression could not create temporary streams.');
        }

        $errors = tmpfile();

        if (false === $errors) {
            fclose($input);
            fclose($output);

            throw new UnsupportedFontException('WOFF2 Brotli decompression could not create temporary streams.');
        }

        try {
            self::writeAll($input, $compressedData);

            if (!rewind($input)) {
                throw new InvalidFontException('WOFF2 Brotli input could not be prepared.');
            }

            $process = @proc_open(
                ['brotli', '--decompress', '--stdout'],
                [$input, $output, $errors],
                $pipes,
            );

            if (!\is_resource($process)) {
                throw new UnsupportedFontException('WOFF2 Brotli decompression requires ext-brotli or the brotli binary.');
            }

            $exitCode = proc_close($process);
            rewind($output);
            rewind($errors);
            $decompressed = stream_get_contents($output);
            $error = stream_get_contents($errors);

            if (0 !== $exitCode || !\is_string($decompressed)) {
                $detail = \is_string($error) ? trim($error) : '';

                throw new InvalidFontException('WOFF2 Brotli decompression failed.' . ('' === $detail ? '' : ' ' . $detail));
            }

            return $decompressed;
        } finally {
            fclose($input);
            fclose($output);
            fclose($errors);
        }
    }

    private static function validateBlockLayout(BinaryReader $woff2, int $compressedOffset, int $compressedLength): void
    {
        $compressedEnd = $compressedOffset + $compressedLength;
        $fontDataEnd = self::round4($compressedEnd);

        if ($compressedEnd < $compressedOffset || $fontDataEnd > $woff2->length()) {
            throw new InvalidFontException('WOFF2 compressed font data exceeds the file bounds.');
        }

        self::assertZeroPadding($woff2, $compressedEnd, $fontDataEnd);

        $metaOffset = $woff2->uint32(28);
        $metaLength = $woff2->uint32(32);
        $metaOriginalLength = $woff2->uint32(36);
        $privateOffset = $woff2->uint32(40);
        $privateLength = $woff2->uint32(44);
        $nextOffset = $fontDataEnd;

        if (0 === $metaOffset) {
            if (0 !== $metaLength || 0 !== $metaOriginalLength) {
                throw new InvalidFontException('WOFF2 metadata lengths require a metadata offset.');
            }
        } else {
            if (0 === $metaLength || 0 === $metaOriginalLength || $metaOffset !== $nextOffset) {
                throw new InvalidFontException('WOFF2 metadata block has an invalid offset or length.');
            }

            $metaEnd = $metaOffset + $metaLength;

            if ($metaEnd < $metaOffset || $metaEnd > $woff2->length()) {
                throw new InvalidFontException('WOFF2 metadata block exceeds the file bounds.');
            }

            $nextOffset = self::round4($metaEnd);
            self::assertZeroPadding($woff2, $metaEnd, $nextOffset);
        }

        if (0 === $privateOffset) {
            if (0 !== $privateLength) {
                throw new InvalidFontException('WOFF2 private-data length requires a private-data offset.');
            }

            $expectedLength = 0 === $metaOffset ? $fontDataEnd : $metaOffset + $metaLength;

            if ($woff2->length() !== $expectedLength) {
                throw new InvalidFontException('WOFF2 contains unexpected trailing data.');
            }

            return;
        }

        if (0 === $privateLength || $privateOffset !== $nextOffset || $privateOffset + $privateLength !== $woff2->length()) {
            throw new InvalidFontException('WOFF2 private-data block has an invalid offset or length.');
        }
    }

    private static function assertZeroPadding(BinaryReader $reader, int $start, int $end): void
    {
        if ($end > $reader->length()) {
            throw new InvalidFontException('WOFF2 padding exceeds the file bounds.');
        }

        if ($end > $start && str_repeat("\0", $end - $start) !== $reader->string($start, $end - $start)) {
            throw new InvalidFontException('WOFF2 block padding must contain only NULL bytes.');
        }
    }

    private static function round4(int $value): int
    {
        return ($value + 3) & ~3;
    }

    /**
     * @param resource $stream
     */
    private static function writeAll($stream, string $data): void
    {
        $offset = 0;
        $length = \strlen($data);

        while ($offset < $length) {
            $written = fwrite($stream, substr($data, $offset, min(1048576, $length - $offset)));

            if (false === $written || 0 === $written) {
                throw new InvalidFontException('WOFF2 Brotli input could not be prepared.');
            }

            $offset += $written;
        }
    }

    /**
     * @param array{0: int, 1: int, 2: int, 3: int} $bounds
     */
    private static function boundingBox(array $bounds): string
    {
        return self::int16($bounds[0]) . self::int16($bounds[1]) . self::int16($bounds[2]) . self::int16($bounds[3]);
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private static function readBoundingBox(BinaryReader $reader, int &$cursor): array
    {
        $bounds = [
            $reader->int16($cursor),
            $reader->int16($cursor + 2),
            $reader->int16($cursor + 4),
            $reader->int16($cursor + 6),
        ];
        $cursor += 8;

        return $bounds;
    }

    private static function bitmapBit(string $bitmap, int $index): bool
    {
        $byteIndex = intdiv($index, 8);

        if ($byteIndex >= \strlen($bitmap)) {
            throw new InvalidFontException('WOFF2 bitmap is truncated.');
        }

        return 0 !== (\ord($bitmap[$byteIndex]) & (0x80 >> ($index % 8)));
    }

    private static function withSign(int $flags, int $value): int
    {
        return 0 !== ($flags & 1) ? $value : -$value;
    }

    private static function assertInt16(int $value, string $label): void
    {
        if ($value < -32768 || $value > 32767) {
            throw new InvalidFontException(\sprintf('%s is outside the signed 16-bit range.', $label));
        }
    }

    private static function int16(int $value): string
    {
        self::assertInt16($value, 'Font value');

        return pack('n', $value & 0xFFFF);
    }

    private static function uint16(int $value): string
    {
        if ($value < 0 || $value > 0xFFFF) {
            throw new InvalidFontException('Font value is outside the unsigned 16-bit range.');
        }

        return pack('n', $value);
    }

    private static function uint32(int $value): string
    {
        return pack('N', $value & 0xFFFFFFFF);
    }

    private static function pad2(string $data): string
    {
        return $data . (0 === \strlen($data) % 2 ? '' : "\0");
    }

    private static function assertConsumed(int $cursor, BinaryReader $reader, string $stream): void
    {
        if ($cursor !== $reader->length()) {
            throw new InvalidFontException(\sprintf('WOFF2 %s stream has trailing data.', $stream));
        }
    }

    /**
     * @param list<array{tag: string, originalLength: int, transformLength: ?int, transformVersion: int, transformed: bool, dataLength: int}> $entries
     *
     * @return array{tag: string, originalLength: int, transformLength: ?int, transformVersion: int, transformed: bool, dataLength: int}
     */
    private static function entry(array $entries, string $tag): array
    {
        foreach ($entries as $entry) {
            if ($entry['tag'] === $tag) {
                return $entry;
            }
        }

        throw new InvalidFontException(\sprintf('WOFF2 table "%s" is missing.', $tag));
    }
}
