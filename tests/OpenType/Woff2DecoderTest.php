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

namespace Alto\Font\Tests\OpenType;

use Alto\Font\Binary\BinaryReader;
use Alto\Font\Compression\BrotliProcessCompressor;
use Alto\Font\Exception\InvalidFontException;
use Alto\Font\Exception\UnsupportedFontException;
use Alto\Font\Font;
use Alto\Font\OpenType\SfntDocument;
use Alto\Font\OpenType\SfntFont;
use Alto\Font\OpenType\Woff2Decoder;
use Alto\Font\OpenType\Woff2KnownTags;
use Alto\Font\OpenType\Woff2TransformEncoder;
use Alto\Font\Tests\Fixtures\TinyTrueTypeFont;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Woff2Decoder::class)]
final class Woff2DecoderTest extends TestCase
{
    public function testItDecodesARealTransformedWoff2Font(): void
    {
        $woff2 = file_get_contents(__DIR__ . '/../Fixtures/Fonts/Inter-Regular-latin.woff2');
        self::assertIsString($woff2);

        $sfnt = Woff2Decoder::decode(new BinaryReader($woff2, 'Inter WOFF2'));
        $font = SfntFont::parse($sfnt);
        $glyphId = $font->glyphIdForCodepoint(233);

        self::assertNotNull($glyphId);
        self::assertSame(518, $font->face()->glyphCount);
        self::assertSame(1194, $font->glyphMetrics($glyphId)->advanceWidth);
        self::assertCount(2, $font->glyphOutline($glyphId)->contours);
    }

    #[DataProvider('invalidHeaders')]
    public function testItRejectsInvalidHeaders(int $offset, string $replacement, string $message): void
    {
        $woff2 = file_get_contents(__DIR__ . '/../Fixtures/Fonts/Inter-Regular-latin.woff2');
        self::assertIsString($woff2);
        $woff2 = substr_replace($woff2, $replacement, $offset, strlen($replacement));

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage($message);

        Woff2Decoder::decode(new BinaryReader($woff2, 'invalid WOFF2'));
    }

    /**
     * @return iterable<string, array{int, string, string}>
     */
    public static function invalidHeaders(): iterable
    {
        yield 'declared length' => [8, pack('N', 1), 'declared length does not match'];
        yield 'empty directory' => [12, "\0\0", 'declares no font tables'];
        yield 'reserved field' => [14, "\0\1", 'reserved header field must be zero'];
    }

    public function testItRejectsACollectionFlavor(): void
    {
        $woff2 = file_get_contents(__DIR__ . '/../Fixtures/Fonts/Inter-Regular-latin.woff2');
        self::assertIsString($woff2);

        $this->expectException(UnsupportedFontException::class);
        $this->expectExceptionMessage('collections are not supported');

        Woff2Decoder::decode(new BinaryReader(substr_replace($woff2, 'ttcf', 4, 4), 'collection WOFF2'));
    }

    /**
     * @param list<array{tag: string, data: string, originalLength: int, transformVersion: int}> $entries
     * @param class-string<\Throwable>                                                        $exception
     */
    #[DataProvider('invalidDirectories')]
    public function testItRejectsInvalidDirectories(array $entries, string $message, string $exception): void
    {
        $this->expectException($exception);
        $this->expectExceptionMessage($message);

        self::decode(self::woff2($entries));
    }

    /**
     * @return iterable<string, array{list<array{tag: string, data: string, originalLength: int, transformVersion: int}>, string, class-string<\Throwable>}>
     */
    public static function invalidDirectories(): iterable
    {
        $glyf = self::entry('glyf', '', 0, 0);
        $loca = self::entry('loca', '', 8, 0);

        yield 'duplicate tag' => [[self::entry('name', 'a'), self::entry('name', 'b')], 'duplicate table "name"', InvalidFontException::class];
        yield 'loca carries transformed data' => [[$glyf, self::entry('loca', 'x', 8, 0)], 'loca table must have zero data length', InvalidFontException::class];
        yield 'missing loca transform' => [[$glyf], 'glyf and loca tables must use matching transforms', InvalidFontException::class];
        yield 'missing glyf transform' => [[$loca], 'glyf and loca tables must use matching transforms', InvalidFontException::class];
        yield 'loca not adjacent' => [[$glyf, self::entry('name', 'x'), $loca], 'loca table must immediately follow glyf', InvalidFontException::class];
        yield 'unsupported hmtx transform' => [[self::entry('hmtx', '', 0, 2)], 'unsupported transform version 2', UnsupportedFontException::class];
        yield 'transform on ordinary table' => [[self::entry('name', '', 0, 1)], 'unsupported transform version 1', UnsupportedFontException::class];
    }

    public function testItRejectsAnImplausibleCombinedTableSize(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('implausible total decompressed table size');

        self::decode(self::woff2([
            self::entry('AAAA', '', 60 * 1024 * 1024),
            self::entry('BBBB', '', 60 * 1024 * 1024),
        ]));
    }

    public function testItRejectsAnUnexpectedDecompressedLength(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('unexpected total length');

        self::decode(self::woff2([self::entry('name', 'x', 2)]));
    }

    public function testItReconstructsAnEmptyGlyphAndLongLoca(): void
    {
        $sfnt = self::decode(self::woff2(self::glyfEntries(self::transformedGlyf(pack('n', 0)))));

        self::assertSame('', self::sfntTable($sfnt, 'glyf'));
        self::assertSame(pack('N2', 0, 0), self::sfntTable($sfnt, 'loca'));
    }

    #[DataProvider('invalidGlyfContexts')]
    public function testItRejectsInvalidTransformedGlyfContexts(string $case, string $message): void
    {
        $entries = self::glyfEntries(self::transformedGlyf(pack('n', 0)));

        $entries = match ($case) {
            'missing-head' => array_values(array_filter($entries, static fn(array $entry): bool => 'head' !== $entry['tag'])),
            'index-format' => array_map(static function (array $entry): array {
                if ('head' === $entry['tag']) {
                    $entry['data'] = substr_replace($entry['data'], "\0\0", 50, 2);
                }

                return $entry;
            }, $entries),
            'glyph-count' => array_map(static function (array $entry): array {
                if ('maxp' === $entry['tag']) {
                    $entry['data'] = substr_replace($entry['data'], pack('n', 2), 4, 2);
                }

                return $entry;
            }, $entries),
            'loca-length' => array_map(static function (array $entry): array {
                if ('loca' === $entry['tag']) {
                    $entry['originalLength'] = 4;
                }

                return $entry;
            }, $entries),
            default => throw new \LogicException('Unknown glyf context.'),
        };

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage($message);

        self::decode(self::woff2($entries));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidGlyfContexts(): iterable
    {
        yield 'missing head' => ['missing-head', 'requires table "head"'];
        yield 'index format mismatch' => ['index-format', 'index format does not match'];
        yield 'glyph count mismatch' => ['glyph-count', 'glyph count does not match'];
        yield 'loca length mismatch' => ['loca-length', 'loca table has an unexpected length'];
    }

    #[DataProvider('invalidTransformedGlyfTables')]
    public function testItRejectsInvalidTransformedGlyf(string $data, string $message): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage($message);

        self::decode(self::woff2(self::glyfEntries($data)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidTransformedGlyfTables(): iterable
    {
        $empty = self::transformedGlyf(pack('n', 0));

        yield 'version' => [substr_replace($empty, pack('n', 1), 0, 2), 'glyf version must be zero'];
        yield 'reserved option' => [substr_replace($empty, pack('n', 2), 2, 2), 'option flags contain reserved bits'];
        yield 'loca format' => [substr_replace($empty, pack('n', 2), 6, 2), 'uses invalid loca format 2'];
        yield 'nContour size' => [substr_replace($empty, pack('N', 0), 8, 4), 'nContour stream length does not match'];
        yield 'trailing bytes' => [$empty . "\0", 'glyf table has trailing data'];
        yield 'truncated bbox bitmap' => [self::transformedGlyf(pack('n', 0), bbox: ''), 'bbox stream is truncated'];
        yield 'bbox on empty glyph' => [self::transformedGlyf(pack('n', 0), bbox: "\x80\0\0\0"), 'Empty WOFF2 glyph 0 has an explicit bounding box'];
        yield 'empty simple contour' => [self::transformedGlyf(pack('n', 1), nPoints: "\0"), 'contains an empty contour'];
        yield 'composite without bbox' => [self::transformedGlyf(pack('n', 0xFFFF)), 'Composite WOFF2 glyph 0 has no explicit bounding box'];
        yield 'invalid contour count' => [self::transformedGlyf(pack('n', 0xFFFE)), 'uses invalid contour count -2'];
        yield 'component outside font' => [self::transformedGlyf(
            pack('n', 0xFFFF),
            composite: pack('n3', 0, 1, 0),
            bbox: "\x80\0\0\0" . str_repeat("\0", 8),
        ), 'references glyph 1 outside the font'];
        yield 'unused stream data' => [self::transformedGlyf(pack('n', 0), nPoints: "\1"), 'nPoints stream has trailing data'];
        yield 'coordinate overflow' => [self::transformedGlyf(
            pack('n', 1),
            nPoints: "\2",
            flag: "\x7F\x7F",
            glyph: pack('n5', 32767, 0, 1, 0, 0),
        ), 'x coordinate is outside the signed 16-bit range'];
    }

    #[DataProvider('pointCountEncodings')]
    public function testItDecodesEvery255UInt16PointCount(string $encodedCount, int $pointCount): void
    {
        $glyph = self::transformedGlyf(
            pack('n', 1),
            nPoints: $encodedCount,
            flag: str_repeat("\0", $pointCount),
            glyph: str_repeat("\0", $pointCount) . "\0",
        );

        $sfnt = self::decode(self::woff2(self::glyfEntries($glyph)));

        self::assertNotSame('', self::sfntTable($sfnt, 'glyf'));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function pointCountEncodings(): iterable
    {
        yield 'single byte' => ["\xFC", 252];
        yield 'code 255' => ["\xFF\x00", 253];
        yield 'code 254' => ["\xFE\x00", 506];
        yield 'code 253' => ["\xFD\x02\xFA", 762];
    }

    #[DataProvider('compoundTransforms')]
    public function testItReconstructsCompositeScaleAndInstructionEncodings(string $writer): void
    {
        $document = self::document($writer);
        [$tags, $entries] = Woff2TransformEncoder::encode($document);
        $sfnt = self::decode(self::woff2(self::encodedEntries($tags, $entries)));

        self::assertNotSame('', self::sfntTable($sfnt, 'glyf'));
        self::assertSame($entries['glyf']['reconstructedLength'], strlen(self::sfntTable($sfnt, 'glyf')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function compoundTransforms(): iterable
    {
        yield 'uniform scale' => ['writeCompoundWithUniformScale'];
        yield 'independent x and y scales' => ['writeCompoundWithXYScale'];
        yield 'two by two matrix' => ['writeCompoundWithTwoByTwo'];
        yield 'instructions' => ['writeCompoundWithInstructions'];
    }

    public function testItReconstructsExplicitBoundsAndOverlap(): void
    {
        $document = self::document();
        $glyf = $document->table('glyf') ?? '';
        $glyphOffset = self::glyphOffset($document, 1);
        $glyf = substr_replace($glyf, pack('n', 99), $glyphOffset + 2, 2);
        $glyf[$glyphOffset + 14] = pack('C', ord($glyf[$glyphOffset + 14]) | 0x40);
        [$tags, $entries] = Woff2TransformEncoder::encode($document->withTables(['glyf' => $glyf]));

        $sfnt = self::decode(self::woff2(self::encodedEntries($tags, $entries)));
        $reconstructed = self::sfntTable($sfnt, 'glyf');

        self::assertSame(99, (new BinaryReader($reconstructed, 'reconstructed glyf'))->int16(self::glyphOffsetFromTables($sfnt, 1) + 2));
        self::assertSame(0x40, ord($reconstructed[self::glyphOffsetFromTables($sfnt, 1) + 14]) & 0x40);
    }

    #[DataProvider('hmtxFlags')]
    public function testItReconstructsEveryHmtxFlagCombination(int $flags): void
    {
        $xMins = [0, 0, 0, 0, 0];
        $advanceWidths = [500, 600, 700];
        $data = match ($flags) {
            1 => "\x01",
            2 => "\x02",
            3 => "\x03",
            default => throw new \LogicException('Unknown hmtx flags.'),
        };
        $expected = '';
        $proportionalBearings = 0 !== ($flags & 1) ? array_slice($xMins, 0, 3) : [1, 11, 21];
        $trailingBearings = 0 !== ($flags & 2) ? array_slice($xMins, 3) : [31, 41];

        foreach ($advanceWidths as $advanceWidth) {
            $data .= pack('n', $advanceWidth);
        }

        if (0 === ($flags & 1)) {
            foreach ($proportionalBearings as $bearing) {
                $data .= pack('n', $bearing);
            }
        }

        if (0 === ($flags & 2)) {
            foreach ($trailingBearings as $bearing) {
                $data .= pack('n', $bearing);
            }
        }

        foreach ($advanceWidths as $index => $advanceWidth) {
            $expected .= pack('n2', $advanceWidth, $proportionalBearings[$index]);
        }

        foreach ($trailingBearings as $bearing) {
            $expected .= pack('n', $bearing);
        }

        $sfnt = self::decode(self::woff2(self::hmtxEntries($data)));

        self::assertSame($expected, self::sfntTable($sfnt, 'hmtx'));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function hmtxFlags(): iterable
    {
        yield 'derive proportional bearings' => [1];
        yield 'derive trailing bearings' => [2];
        yield 'derive all bearings' => [3];
    }

    public function testItRejectsATransformedHmtxLengthMismatch(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('hmtx table has an unexpected length');

        self::decode(self::woff2(self::hmtxEntries("\x03" . pack('n3', 500, 600, 700), 22)));
    }

    #[DataProvider('invalidTransformedHmtxTables')]
    public function testItRejectsInvalidTransformedHmtx(string $case, string $message): void
    {
        $entries = self::hmtxEntries("\x03" . pack('n3', 500, 600, 700));

        $entries = match ($case) {
            'missing-hhea' => array_values(array_filter($entries, static fn(array $entry): bool => 'hhea' !== $entry['tag'])),
            'missing-glyf' => array_values(array_filter($entries, static fn(array $entry): bool => 'glyf' !== $entry['tag'])),
            'flags' => array_map(static function (array $entry): array {
                if ('hmtx' === $entry['tag']) {
                    $entry['data'] = "\0";
                }

                return $entry;
            }, $entries),
            'counts' => array_map(static function (array $entry): array {
                if ('hhea' === $entry['tag']) {
                    $entry['data'] = str_repeat("\0", 36);
                }

                return $entry;
            }, $entries),
            'trailing' => array_map(static function (array $entry): array {
                if ('hmtx' === $entry['tag']) {
                    $entry['data'] .= "\0";
                }

                return $entry;
            }, $entries),
            'loca-offset' => array_map(static function (array $entry): array {
                if ('loca' === $entry['tag']) {
                    $entry['data'] = substr_replace($entry['data'], pack('N', 4), 4, 4);
                }

                return $entry;
            }, $entries),
            default => throw new \LogicException('Unknown hmtx corruption.'),
        };

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage($message);

        self::decode(self::woff2($entries));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidTransformedHmtxTables(): iterable
    {
        yield 'missing hhea' => ['missing-hhea', 'requires table "hhea"'];
        yield 'missing glyf context' => ['missing-glyf', 'requires table "glyf"'];
        yield 'invalid flags' => ['flags', 'hmtx flags are invalid'];
        yield 'invalid metric counts' => ['counts', 'metrics counts are invalid'];
        yield 'trailing bytes' => ['trailing', 'hmtx table has trailing data'];
        yield 'invalid loca offset' => ['loca-offset', 'loca offsets are invalid'];
    }

    #[DataProvider('invalidBlockLayouts')]
    public function testItRejectsInvalidBlockLayouts(string $case, string $message): void
    {
        $woff2 = self::woff2([self::entry('name', 'Alto')]);
        $compressedOffset = 50;
        $compressedEnd = $compressedOffset + (new BinaryReader($woff2, 'WOFF2'))->uint32(20);

        $woff2 = match ($case) {
            'compressed-bounds' => substr_replace($woff2, pack('N', strlen($woff2)), 20, 4),
            'metadata-without-offset' => substr_replace(substr_replace($woff2, pack('N', 1), 32, 4), pack('N', 1), 36, 4),
            'private-without-offset' => substr_replace($woff2, pack('N', 1), 44, 4),
            'trailing-data' => self::replaceDeclaredLength($woff2 . "\0"),
            'non-zero-padding' => substr_replace($woff2, "\x01", $compressedEnd, 1),
            default => throw new \LogicException('Unknown block-layout case.'),
        };

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage($message);

        self::decode($woff2);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidBlockLayouts(): iterable
    {
        yield 'compressed data exceeds bounds' => ['compressed-bounds', 'compressed font data exceeds'];
        yield 'metadata lengths without offset' => ['metadata-without-offset', 'metadata lengths require a metadata offset'];
        yield 'private length without offset' => ['private-without-offset', 'private-data length requires a private-data offset'];
        yield 'unexpected trailing bytes' => ['trailing-data', 'unexpected trailing data'];
        yield 'non-zero compressed padding' => ['non-zero-padding', 'padding must contain only NULL bytes'];
    }

    public function testItAcceptsAdjacentMetadataAndPrivateBlocks(): void
    {
        $sfnt = self::decode(self::woff2([self::entry('name', 'Alto')], 'meta', 'private'));

        self::assertSame('Alto', self::sfntTable($sfnt, 'name'));
    }

    public function testItRejectsInvalidMetadataOffsets(): void
    {
        $woff2 = self::woff2([self::entry('name', 'Alto')], 'meta');
        $woff2 = substr_replace($woff2, pack('N', 1), 28, 4);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('metadata block has an invalid offset or length');

        self::decode($woff2);
    }

    public function testItRejectsMetadataBeyondTheContainer(): void
    {
        $woff2 = self::woff2([self::entry('name', 'Alto')], 'meta');
        $woff2 = substr_replace($woff2, pack('N', strlen($woff2)), 32, 4);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('metadata block exceeds the file bounds');

        self::decode($woff2);
    }

    public function testItRejectsInvalidPrivateDataOffsets(): void
    {
        $woff2 = self::woff2([self::entry('name', 'Alto')], null, 'private');
        $reader = new BinaryReader($woff2, 'private WOFF2');
        $woff2 = substr_replace($woff2, pack('N', $reader->uint32(40) + 4), 40, 4);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('private-data block has an invalid offset or length');

        self::decode($woff2);
    }

    public function testItRejectsTruncatedMetadataPadding(): void
    {
        $woff2 = self::woff2([self::entry('name', 'Alto')], 'abc');

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('padding exceeds the file bounds');

        self::decode($woff2);
    }

    /**
     * @param list<array{tag: string, data: string, originalLength: int, transformVersion: int}> $entries
     */
    private static function woff2(array $entries, ?string $metadata = null, ?string $privateData = null): string
    {
        $directory = '';
        $tableData = '';
        $totalSfntSize = 12 + count($entries) * 16;

        foreach ($entries as $entry) {
            $tagIndex = Woff2KnownTags::indexOf($entry['tag']);
            $flags = ($entry['transformVersion'] << 6) | ($tagIndex ?? 0x3F);
            $directory .= pack('C', $flags);

            if (null === $tagIndex) {
                $directory .= $entry['tag'];
            }

            $directory .= self::base128($entry['originalLength']);

            if (self::isTransformed($entry['tag'], $entry['transformVersion'])) {
                $directory .= self::base128(strlen($entry['data']));
            }

            $tableData .= $entry['data'];
            $totalSfntSize += (strlen($entry['data']) + 3) & ~3;
        }

        $compressed = new BrotliProcessCompressor()->compress($tableData);
        $prefixLength = 48 + strlen($directory);
        $compressedEnd = $prefixLength + strlen($compressed);
        $fontPadding = str_repeat("\0", (4 - $compressedEnd % 4) % 4);
        $metaOffset = null === $metadata ? 0 : $compressedEnd + strlen($fontPadding);
        $metaPadding = null === $metadata || null === $privateData ? '' : str_repeat("\0", (4 - ($metaOffset + strlen($metadata)) % 4) % 4);
        $privateOffset = null === $privateData ? 0 : ($metaOffset > 0 ? $metaOffset + strlen($metadata ?? '') + strlen($metaPadding) : $compressedEnd + strlen($fontPadding));
        $tail = $fontPadding . ($metadata ?? '') . $metaPadding . ($privateData ?? '');
        $length = $compressedEnd + strlen($tail);

        return 'wOF2'
            . "\x00\x01\x00\x00"
            . pack('N', $length)
            . pack('n2', count($entries), 0)
            . pack('N2', $totalSfntSize, strlen($compressed))
            . pack('n2', 0, 0)
            . pack('N3', $metaOffset, strlen($metadata ?? ''), null === $metadata ? 0 : strlen($metadata))
            . pack('N2', $privateOffset, strlen($privateData ?? ''))
            . $directory
            . $compressed
            . $tail;
    }

    /**
     * @return array{tag: string, data: string, originalLength: int, transformVersion: int}
     */
    private static function entry(string $tag, string $data, ?int $originalLength = null, int $transformVersion = 0): array
    {
        return [
            'tag' => $tag,
            'data' => $data,
            'originalLength' => $originalLength ?? strlen($data),
            'transformVersion' => $transformVersion,
        ];
    }

    /**
     * @return list<array{tag: string, data: string, originalLength: int, transformVersion: int}>
     */
    private static function glyfEntries(string $glyf): array
    {
        $head = substr_replace(str_repeat("\0", 54), pack('n', 1), 50, 2);

        return [
            self::entry('glyf', $glyf, 0, 0),
            self::entry('loca', '', 8, 0),
            self::entry('head', $head),
            self::entry('maxp', pack('Nn', 0x00010000, 1)),
        ];
    }

    /**
     * @return list<array{tag: string, data: string, originalLength: int, transformVersion: int}>
     */
    private static function hmtxEntries(string $hmtx, int $originalLength = 16): array
    {
        $head = substr_replace(str_repeat("\0", 54), pack('n', 1), 50, 2);
        $hhea = substr_replace(str_repeat("\0", 36), pack('n', 3), 34, 2);

        return [
            self::entry('glyf', '', 0, 3),
            self::entry('head', $head),
            self::entry('hhea', $hhea),
            self::entry('hmtx', $hmtx, $originalLength, 1),
            self::entry('loca', str_repeat("\0", 24), 24, 3),
            self::entry('maxp', pack('Nn', 0x00010000, 5)),
        ];
    }

    /**
     * @param list<string>                                                                                                    $tags
     * @param array<string, array{data: string, originalLength: int, reconstructedLength: int, transformVersion: int}> $entries
     *
     * @return list<array{tag: string, data: string, originalLength: int, transformVersion: int}>
     */
    private static function encodedEntries(array $tags, array $entries): array
    {
        $result = [];

        foreach ($tags as $tag) {
            $entry = $entries[$tag];
            $result[] = self::entry($tag, $entry['data'], $entry['originalLength'], $entry['transformVersion']);
        }

        return $result;
    }

    private static function transformedGlyf(
        string $nContour,
        string $nPoints = '',
        string $flag = '',
        string $glyph = '',
        string $composite = '',
        string $bbox = "\0\0\0\0",
        string $instruction = '',
    ): string {
        $streams = [$nContour, $nPoints, $flag, $glyph, $composite, $bbox, $instruction];
        $header = pack('n4', 0, 0, intdiv(strlen($nContour), 2), 1);

        foreach ($streams as $stream) {
            $header .= pack('N', strlen($stream));
        }

        return $header . implode('', $streams);
    }

    private static function decode(string $woff2): string
    {
        return Woff2Decoder::decode(new BinaryReader($woff2, 'test WOFF2'));
    }

    private static function sfntTable(string $sfnt, string $tag): string
    {
        $reader = new BinaryReader($sfnt, 'test SFNT');

        for ($index = 0; $index < $reader->uint16(4); ++$index) {
            $recordOffset = 12 + $index * 16;

            if ($tag === $reader->string($recordOffset, 4)) {
                return $reader->string($reader->uint32($recordOffset + 8), $reader->uint32($recordOffset + 12));
            }
        }

        self::fail(sprintf('SFNT table "%s" not found.', $tag));
    }

    private static function document(string $writer = 'write'): SfntDocument
    {
        $path = sys_get_temp_dir() . '/alto-font-woff2-decoder-' . bin2hex(random_bytes(4)) . '.ttf';
        TinyTrueTypeFont::$writer($path);

        return Font::fromFile($path)->sfntDocument();
    }

    private static function glyphOffset(SfntDocument $document, int $glyphId): int
    {
        $head = new BinaryReader($document->table('head') ?? '', 'test head');
        $loca = new BinaryReader($document->table('loca') ?? '', 'test loca');

        return 0 === $head->int16(50) ? $loca->uint16($glyphId * 2) * 2 : $loca->uint32($glyphId * 4);
    }

    private static function glyphOffsetFromTables(string $sfnt, int $glyphId): int
    {
        return (new BinaryReader(self::sfntTable($sfnt, 'loca'), 'test loca'))->uint32($glyphId * 4);
    }

    private static function base128(int $value): string
    {
        $bytes = [0];

        for ($index = 0; $value > 0; ++$index) {
            $bytes[$index] = $value & 0x7F;
            $value >>= 7;

            if ($value > 0) {
                $bytes[] = 0;
            }
        }

        $result = '';

        for ($index = count($bytes) - 1; $index >= 0; --$index) {
            $result .= pack('C', $bytes[$index] | (0 === $index ? 0 : 0x80));
        }

        return $result;
    }

    private static function isTransformed(string $tag, int $version): bool
    {
        return in_array($tag, ['glyf', 'loca'], true) ? 3 !== $version : 0 !== $version;
    }

    private static function replaceDeclaredLength(string $woff2): string
    {
        return substr_replace($woff2, pack('N', strlen($woff2)), 8, 4);
    }
}
