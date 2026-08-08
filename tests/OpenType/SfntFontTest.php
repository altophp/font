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
use Alto\Font\Exception\InvalidFontException;
use Alto\Font\Exception\UnsupportedFontException;
use Alto\Font\Glyph\Contour;
use Alto\Font\Glyph\GlyphId;
use Alto\Font\Glyph\GlyphPoint;
use Alto\Font\Glyph\PathCommand;
use Alto\Font\OpenType\SfntFont;
use Alto\Font\Tests\Fixtures\ContourAssertions;
use Alto\Font\Tests\Fixtures\TinyTrueTypeFont;
use Alto\Font\Variation\VariationCoordinates;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SfntFont::class)]
final class SfntFontTest extends TestCase
{
    use ContourAssertions;

    public function testItParsesStaticTrueTypeMetadataGlyphsAndMetrics(): void
    {
        $font = SfntFont::open(self::fontPath());
        $face = $font->face();
        $glyphId = $font->glyphIdForCodepoint(65);
        self::assertNotNull($glyphId);

        self::assertSame(1000, $face->unitsPerEm);
        self::assertSame(800, $face->ascender);
        self::assertSame(-200, $face->descender);
        self::assertSame(5, $face->glyphCount);
        self::assertSame('Atelier Tiny', $face->name(1));
        self::assertSame(1, $glyphId->value);
        self::assertSame(600, $font->glyphMetrics($glyphId)->advanceWidth);
        self::assertSame('M 100 0 L 300 700 L 500 0 L 100 0 Z', self::describeContour($font->glyphOutline($glyphId)->contours[0]));
    }

    public function testItParsesWoffContainers(): void
    {
        $path = sys_get_temp_dir() . '/atelier-font-truetype-woff-' . bin2hex(random_bytes(4)) . '.woff';
        TinyTrueTypeFont::writeWoff($path);

        self::assertSame('Atelier Tiny', SfntFont::open($path)->face()->name(1));
    }

    public function testItParsesNullTransformWoff2Containers(): void
    {
        if (!TinyTrueTypeFont::hasBrotliEncoder()) {
            self::markTestSkipped('The brotli binary is required to generate the WOFF2 fixture.');
        }

        $path = sys_get_temp_dir() . '/atelier-font-truetype-woff2-' . bin2hex(random_bytes(4)) . '.woff2';
        TinyTrueTypeFont::writeWoff2($path);

        self::assertSame('Atelier Tiny', SfntFont::open($path)->face()->name(1));
    }

    public function testItRejectsMissingFiles(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('does not exist');

        SfntFont::open('/path/that/does/not/exist.ttf');
    }

    public function testItRejectsEmptyFiles(): void
    {
        $path = sys_get_temp_dir() . '/atelier-font-empty-' . bin2hex(random_bytes(4)) . '.ttf';
        file_put_contents($path, '');

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('could not be read or is empty');

        SfntFont::open($path);
    }

    public function testItRejectsTruncatedCollectionHeaders(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('read out of bounds');

        SfntFont::parse('ttcf');
    }

    public function testItReadsTheFirstFaceOfACollectionByDefault(): void
    {
        $path = sys_get_temp_dir() . '/atelier-font-ttc-' . bin2hex(random_bytes(4)) . '.ttc';
        TinyTrueTypeFont::writeCollection($path, [1000, 2048]);

        $font = SfntFont::open($path);

        self::assertSame(1000, $font->face()->unitsPerEm);
        self::assertSame(0, $font->face()->faceIndex);
        self::assertSame(2, $font->face()->faceCount);
    }

    public function testItReadsARequestedFaceOfACollection(): void
    {
        $path = sys_get_temp_dir() . '/atelier-font-ttc-' . bin2hex(random_bytes(4)) . '.ttc';
        TinyTrueTypeFont::writeCollection($path, [1000, 2048]);

        $font = SfntFont::open($path, faceIndex: 1);

        self::assertSame(2048, $font->face()->unitsPerEm);
        self::assertSame(1, $font->face()->faceIndex);
        self::assertSame(2, $font->face()->faceCount);
    }

    public function testItRejectsCollectionsWhereAFacePointsToAnotherCollection(): void
    {
        $data = 'ttcf' . pack('N', 0x00010000) . pack('N', 1) . pack('N', 16) . 'ttcf';

        $this->expectException(UnsupportedFontException::class);
        $this->expectExceptionMessage('Nested font collections are not supported');

        SfntFont::parse($data);
    }

    public function testItRejectsOutOfRangeFaceIndexes(): void
    {
        $path = sys_get_temp_dir() . '/atelier-font-ttc-' . bin2hex(random_bytes(4)) . '.ttc';
        TinyTrueTypeFont::writeCollection($path, [1000, 2048]);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('has 2 face(s); requested index 5 is out of range');

        SfntFont::open($path, faceIndex: 5);
    }

    public function testItRejectsCollectionsDeclaringNoFaces(): void
    {
        $path = sys_get_temp_dir() . '/atelier-font-ttc-' . bin2hex(random_bytes(4)) . '.ttc';
        TinyTrueTypeFont::writeCollectionHeaderOnly($path, 0);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('declares no faces');

        SfntFont::open($path);
    }

    public function testFontFaceReportsSingleFaceForNonCollectionFonts(): void
    {
        $path = sys_get_temp_dir() . '/atelier-font-' . bin2hex(random_bytes(4)) . '.ttf';
        TinyTrueTypeFont::write($path);

        $font = SfntFont::open($path);

        self::assertSame(0, $font->face()->faceIndex);
        self::assertSame(1, $font->face()->faceCount);
    }

    public function testItRejectsCffScalerTypes(): void
    {
        $this->expectException(UnsupportedFontException::class);
        $this->expectExceptionMessage('CFF/OpenType outlines are not supported');

        SfntFont::parse('OTTO');
    }

    public function testItRejectsInvalidScalerTypes(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Unsupported sfnt scaler type.');

        SfntFont::parse('nope');
    }

    public function testItRejectsSfntFilesWithMissingRequiredTables(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Required table "cmap" is missing.');

        SfntFont::parse("\x00\x01\x00\x00" . self::u16(0) . self::u16(0) . self::u16(0) . self::u16(0));
    }

    public function testItRejectsUnsupportedCffFonts(): void
    {
        $this->expectException(UnsupportedFontException::class);
        $this->expectExceptionMessage('CFF/OpenType outlines are not supported');

        SfntFont::open(self::fontPath('unsupported.ttf', unsupportedCff: true));
    }

    public function testItRejectsUnsupportedCffOutlinesFromMemory(): void
    {
        $this->expectException(UnsupportedFontException::class);
        $this->expectExceptionMessage('CFF2/OpenType outlines are not supported');

        SfntFont::parse(self::sfnt(['CFF2' => "\0\0\0\0"]));
    }

    public function testItRejectsUnsupportedLocaFormats(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Unsupported loca format 2.');

        SfntFont::parse(self::tinyFontDataWithTablePatch('head', 50, self::i16(2)));
    }

    public function testItRejectsFontsWithoutHorizontalMetrics(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('hhea numberOfHMetrics must be positive.');

        SfntFont::parse(self::tinyFontDataWithTablePatch('hhea', 34, self::u16(0)));
    }

    public function testItReadsLeftSideBearingsAfterTheLastFullHorizontalMetric(): void
    {
        $font = SfntFont::parse(self::tinyFontDataWithTablePatch('hhea', 34, self::u16(3)));

        self::assertSame(610, $font->glyphMetrics(new GlyphId(3))->advanceWidth);
        self::assertSame(500, $font->glyphMetrics(new GlyphId(3))->leftSideBearing);
        self::assertSame(610, $font->glyphMetrics(new GlyphId(4))->advanceWidth);
        self::assertSame(0, $font->glyphMetrics(new GlyphId(4))->leftSideBearing);
    }

    public function testItRejectsWoffContainersWithInvalidLengths(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('WOFF declared length exceeds file length.');

        SfntFont::parse('wOFF' . "\x00\x01\x00\x00" . self::u32(100) . self::u16(0) . self::u16(0));
    }

    public function testItRejectsWoffTablesThatCannotBeDecompressed(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('WOFF table "test" could not be decompressed.');

        SfntFont::parse(self::woffWithTable('test', 'bad', 4));
    }

    public function testItRejectsWoffTablesWithUnexpectedDecompressedLengths(): void
    {
        $payload = gzcompress('abc');
        self::assertIsString($payload);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('WOFF table "test" decompressed to an unexpected length.');

        SfntFont::parse(self::woffWithTable('test', $payload, 4));
    }

    public function testItRejectsWoffTablesDeclaringAnImplausibleDecompressedSize(): void
    {
        $payload = gzcompress('abc');
        self::assertIsString($payload);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('WOFF table "test" declares an implausible decompressed size.');

        SfntFont::parse(self::woffWithTable('test', $payload, 200 * 1024 * 1024));
    }

    public function testItRejectsWoff2CollectionsAndInvalidLengths(): void
    {
        $this->expectException(UnsupportedFontException::class);
        $this->expectExceptionMessage('WOFF2 font collections are not supported');

        SfntFont::parse('wOF2ttcf');
    }

    public function testItRejectsWoff2ContainersWithInvalidLengths(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('WOFF2 declared length exceeds file length.');

        SfntFont::parse('wOF2' . "\x00\x01\x00\x00" . self::u32(100) . self::u16(0) . self::u16(0) . self::u32(0) . self::u32(0));
    }

    public function testItRejectsTransformedWoff2Tables(): void
    {
        $path = sys_get_temp_dir() . '/atelier-font-truetype-transformed-' . bin2hex(random_bytes(4)) . '.woff2';
        TinyTrueTypeFont::writeTransformedWoff2($path);

        $this->expectException(UnsupportedFontException::class);
        $this->expectExceptionMessage('WOFF2 transformed table "glyf" is not supported');

        SfntFont::open($path);
    }

    public function testItRejectsWoff2TablesDeclaringAnImplausibleDecompressedSize(): void
    {
        if (!TinyTrueTypeFont::hasBrotliEncoder()) {
            self::markTestSkipped('The brotli binary is required to generate the WOFF2 fixture.');
        }

        $path = sys_get_temp_dir() . '/atelier-font-truetype-woff2-implausible-' . bin2hex(random_bytes(4)) . '.woff2';
        TinyTrueTypeFont::writeWoff2WithImplausibleTableLength($path);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('declares an implausible decompressed size');

        SfntFont::open($path);
    }

    public function testItRejectsCompoundGlyphCycles(): void
    {
        $font = SfntFont::open(self::fontPath('cycle.ttf', compoundCycle: true));

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Compound glyph cycle detected');

        $font->glyphOutline(new GlyphId(4));
    }

    public function testItRejectsPointMatchedCompoundGlyphs(): void
    {
        $path = sys_get_temp_dir() . '/atelier-font-truetype-point-matched-' . bin2hex(random_bytes(4)) . '.ttf';
        TinyTrueTypeFont::writePointMatchedCompound($path);

        $this->expectException(UnsupportedFontException::class);
        $this->expectExceptionMessage('Point-matched compound glyphs are not supported');

        SfntFont::open($path)->glyphOutline(new GlyphId(4));
    }

    public function testItRejectsGlyphIdsOutsideGlyphCount(): void
    {
        $font = SfntFont::open(self::fontPath());

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Glyph ID 99 is outside font glyph count 5.');

        $font->glyphMetrics(new GlyphId(99));
    }

    public function testItExposesVariationMetadata(): void
    {
        $path = sys_get_temp_dir() . '/atelier-font-truetype-variable-' . bin2hex(random_bytes(4)) . '.ttf';
        TinyTrueTypeFont::writeVariable($path);
        $variations = SfntFont::open($path)->variations();

        self::assertNotNull($variations);
        self::assertSame('wght', $variations->axes[0]->tag);
        self::assertSame(100.0, $variations->axes[0]->minimum);
        self::assertSame(400.0, $variations->axes[0]->default);
        self::assertSame(900.0, $variations->axes[0]->maximum);
        self::assertSame('wdth', $variations->axes[1]->tag);
        self::assertCount(2, $variations->instances);
    }

    public function testItAppliesGlyphVariationDeltasToOutlinesAndMetrics(): void
    {
        $path = sys_get_temp_dir() . '/atelier-font-truetype-variable-gvar-' . bin2hex(random_bytes(4)) . '.ttf';
        TinyTrueTypeFont::writeVariableWithGvar($path);
        $font = SfntFont::open($path);
        $glyphId = new GlyphId(1);
        $coordinates = new VariationCoordinates(['wght' => 900]);

        self::assertSame('M 80 0 L 300 700 L 520 0 L 80 0 Z', self::describeContour($font->glyphOutline($glyphId, $coordinates)->contours[0]));
        self::assertSame(640, $font->glyphMetrics($glyphId, $coordinates)->advanceWidth);
        self::assertSame(610, $font->glyphMetrics(new GlyphId(2), $coordinates)->advanceWidth);
        self::assertSame(500, $font->glyphMetrics(new GlyphId(0), $coordinates)->advanceWidth);
    }

    public function testItKeepsStaticMetricsWhenAVariableFontHasNoGvarTable(): void
    {
        $path = sys_get_temp_dir() . '/atelier-font-truetype-variable-no-gvar-' . bin2hex(random_bytes(4)) . '.ttf';
        TinyTrueTypeFont::writeVariable($path);

        self::assertSame(600, SfntFont::open($path)->glyphMetrics(new GlyphId(1), new VariationCoordinates(['wght' => 900]))->advanceWidth);
    }

    public function testItAppliesHvarDeltasToMetrics(): void
    {
        $path = sys_get_temp_dir() . '/atelier-font-truetype-variable-hvar-' . bin2hex(random_bytes(4)) . '.ttf';
        TinyTrueTypeFont::writeVariableWithHvar($path);
        $metrics = SfntFont::open($path)->glyphMetrics(new GlyphId(1), new VariationCoordinates(['wght' => 900]));

        self::assertSame(700, $metrics->advanceWidth);
        self::assertSame(15, $metrics->leftSideBearing);
        self::assertSame(600, SfntFont::open($path)->glyphMetrics(new GlyphId(1), new VariationCoordinates(['wght' => 400]))->advanceWidth);
    }

    public function testItReadsEmptyAndCompoundGlyphOutlines(): void
    {
        $font = SfntFont::open(self::fontPath());

        self::assertSame([], $font->glyphOutline(new GlyphId(0))->contours);
        self::assertSame('M 150 0 L 350 700 L 550 0 L 150 0 Z', self::describeContour($font->glyphOutline(new GlyphId(4))->contours[0]));
    }

    public function testItReadsZeroContourSimpleGlyphs(): void
    {
        self::assertSame([], SfntFont::parse(self::tinyFontDataWithGlyphPatch(1, 0, self::i16(0)))->glyphOutline(new GlyphId(1))->contours);
    }

    public function testItReadsRepeatedFlagsAndShortCoordinates(): void
    {
        $data = self::tinyFontDataWithGlyphPatch(1, 14, "\x3F\x02" . str_repeat('d', 6));

        self::assertSame(
            'M 100 100 L 200 200 L 300 300 L 100 100 Z',
            self::describeContour(SfntFont::parse($data)->glyphOutline(new GlyphId(1))->contours[0]),
        );
    }

    public function testItRejectsRepeatedFlagsThatExceedThePointCount(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Glyph 1 has invalid repeated flags.');

        SfntFont::parse(self::tinyFontDataWithGlyphPatch(1, 14, "\x09\x03"))->glyphOutline(new GlyphId(1));
    }

    public function testItReadsCompoundGlyphsWithByteArguments(): void
    {
        $data = self::tinyFontDataWithGlyphPatch(4, 10, self::u16(0x0002) . self::u16(1) . "\x32\x00\x00\x00");

        self::assertSame(
            'M 150 0 L 350 700 L 550 0 L 150 0 Z',
            self::describeContour(SfntFont::parse($data)->glyphOutline(new GlyphId(4))->contours[0]),
        );
    }

    public function testItReadsCompoundGlyphsWithUniformScale(): void
    {
        $path = sys_get_temp_dir() . '/atelier-font-truetype-compound-scale-' . bin2hex(random_bytes(4)) . '.ttf';
        TinyTrueTypeFont::writeCompoundWithUniformScale($path);

        self::assertSame(
            'M 100 0 L 200 350 L 300 0 L 100 0 Z',
            self::describeContour(SfntFont::open($path)->glyphOutline(new GlyphId(4))->contours[0]),
        );
    }

    public function testItReadsCompoundGlyphsWithXYScale(): void
    {
        $path = sys_get_temp_dir() . '/atelier-font-truetype-compound-xy-scale-' . bin2hex(random_bytes(4)) . '.ttf';
        TinyTrueTypeFont::writeCompoundWithXYScale($path);

        self::assertSame(
            'M 100 0 L 200 175 L 300 0 L 100 0 Z',
            self::describeContour(SfntFont::open($path)->glyphOutline(new GlyphId(4))->contours[0]),
        );
    }

    public function testItReadsCompoundGlyphsWithTwoByTwoTransforms(): void
    {
        $path = sys_get_temp_dir() . '/atelier-font-truetype-compound-two-by-two-' . bin2hex(random_bytes(4)) . '.ttf';
        TinyTrueTypeFont::writeCompoundWithTwoByTwo($path);

        self::assertSame(
            'M 150 25 L 350 775 L 550 125 L 150 25 Z',
            self::describeContour(SfntFont::open($path)->glyphOutline(new GlyphId(4))->contours[0]),
        );
    }

    public function testItReadsCompoundGlyphsWithInstructions(): void
    {
        $path = sys_get_temp_dir() . '/atelier-font-truetype-compound-instructions-' . bin2hex(random_bytes(4)) . '.ttf';
        TinyTrueTypeFont::writeCompoundWithInstructions($path);

        self::assertSame(
            'M 150 0 L 350 700 L 550 0 L 150 0 Z',
            self::describeContour(SfntFont::open($path)->glyphOutline(new GlyphId(4))->contours[0]),
        );
    }

    public function testItSkipsCompoundTransformBytesWhenReadingComponentMetrics(): void
    {
        foreach ([
            'scale' => static function (string $path): void {
                TinyTrueTypeFont::writeVariableCompoundWithUniformScaleAndUseMyMetrics($path);
            },
            'xy-scale' => static function (string $path): void {
                TinyTrueTypeFont::writeVariableCompoundWithXYScaleAndUseMyMetrics($path);
            },
            'two-by-two' => static function (string $path): void {
                TinyTrueTypeFont::writeVariableCompoundWithTwoByTwoAndUseMyMetrics($path);
            },
        ] as $name => $write) {
            $path = sys_get_temp_dir() . '/atelier-font-truetype-compound-metrics-' . $name . '-' . bin2hex(random_bytes(4)) . '.ttf';
            $write($path);

            self::assertSame(600, SfntFont::open($path)->glyphMetrics(new GlyphId(4), new VariationCoordinates(['wght' => 900]))->advanceWidth);
        }
    }

    public function testItAppliesCompoundGlyphVariationDeltas(): void
    {
        $path = sys_get_temp_dir() . '/atelier-font-truetype-compound-gvar-' . bin2hex(random_bytes(4)) . '.ttf';
        TinyTrueTypeFont::writeVariableWithCompoundGvar($path);
        $font = SfntFont::open($path);

        self::assertSame(
            'M 180 40 L 380 740 L 580 40 L 180 40 Z',
            self::describeContour($font->glyphOutline(new GlyphId(4), new VariationCoordinates(['wght' => 900]))->contours[0]),
        );
        self::assertSame(620, $font->glyphMetrics(new GlyphId(4), new VariationCoordinates(['wght' => 900]))->advanceWidth);
    }

    public function testItUsesComponentMetricsForVariableCompounds(): void
    {
        $path = sys_get_temp_dir() . '/atelier-font-truetype-use-my-metrics-' . bin2hex(random_bytes(4)) . '.ttf';
        TinyTrueTypeFont::writeVariableWithUseMyMetricsGvar($path);
        $metrics = SfntFont::open($path)->glyphMetrics(new GlyphId(4), new VariationCoordinates(['wght' => 900]));

        self::assertSame(600, $metrics->advanceWidth);
        self::assertSame(10, $metrics->leftSideBearing);
    }

    public function testItReadsUIntBase128ValuesAndRejectsInvalidEncodings(): void
    {
        self::assertSame(128, self::readUIntBase128("\x81\x00"));

        foreach ([
            "\x80\x00" => 'leading zeros',
            "\x90\x80\x80\x80\x00" => 'exceeds 32 bits',
            "\x81\x81\x81\x81\x81" => 'sequence exceeds 5 bytes',
        ] as $bytes => $message) {
            try {
                self::readUIntBase128($bytes);
                self::fail('Expected invalid UIntBase128 encoding to be rejected.');
            } catch (InvalidFontException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }
    }

    public function testItInfersContourDeltasForSparseVariationPoints(): void
    {
        self::assertSame(
            [5.0, 5.0, 5.0, 5.0],
            self::invokePrivateStatic('inferContourDeltas', [
                [0.0, 5.0, 0.0, 0.0],
                [1],
                [3],
                [0, 10, 20, 30],
            ]),
        );

        self::assertSame(
            [10.0, 10.0, 20.0, 30.0],
            self::invokePrivateStatic('inferContourDeltas', [
                [0.0, 10.0, 0.0, 30.0],
                [1, 3],
                [3],
                [0, 10, 20, 30],
            ]),
        );
        self::assertSame([0], self::invokePrivateStatic('contourPointsBetween', [3, 1, 0, 3]));
        self::assertSame(5.0, self::invokePrivateStatic('interpolateDelta', [10, 10, 10, 5.0, 7.0]));
        self::assertSame(15.0, self::invokePrivateStatic('interpolateDelta', [15, 20, 10, 20.0, 10.0]));
        self::assertSame(10.0, self::invokePrivateStatic('interpolateDelta', [0, 10, 20, 10.0, 20.0]));
        self::assertSame(20.0, self::invokePrivateStatic('interpolateDelta', [30, 10, 20, 10.0, 20.0]));
    }

    public function testItBuildsPathCommandsForOnAndOffCurveContours(): void
    {
        self::assertSame('', self::pathData(self::commandsForContour([])));
        self::assertSame('M 0 0 L 10 0 L 0 10 L 0 0 Z', self::pathData(self::commandsForContour([
            new GlyphPoint(0.0, 0.0, true),
            new GlyphPoint(10.0, 0.0, true),
            new GlyphPoint(0.0, 10.0, true),
        ])));
        self::assertSame('M 0 0 Q 5 10 0 0 Z', self::pathData(self::commandsForContour([
            new GlyphPoint(5.0, 10.0, false),
            new GlyphPoint(0.0, 0.0, true),
        ])));
        self::assertSame('M 10 10 Q 0 0 5 0 Q 10 0 10 10 Z', self::pathData(self::commandsForContour([
            new GlyphPoint(0.0, 0.0, false),
            new GlyphPoint(10.0, 0.0, false),
            new GlyphPoint(10.0, 10.0, true),
        ])));
        self::assertSame('M 5 0 Q 0 0 5 0 Q 10 0 5 0 Z', self::pathData(self::commandsForContour([
            new GlyphPoint(0.0, 0.0, false),
            new GlyphPoint(10.0, 0.0, false),
        ])));
    }

    private static function fontPath(
        string $filename = 'tiny.ttf',
        bool $compoundCycle = false,
        bool $unsupportedCff = false,
    ): string {
        $path = sys_get_temp_dir() . '/atelier-font-truetype-' . $filename . '-' . bin2hex(random_bytes(4));
        TinyTrueTypeFont::write($path, $compoundCycle, $unsupportedCff);

        return $path;
    }

    private static function readUIntBase128(string $bytes): int
    {
        $cursor = 0;
        $method = new \ReflectionMethod(SfntFont::class, 'readUIntBase128');
        $arguments = [new BinaryReader($bytes, 'woff2'), &$cursor];
        $result = $method->invokeArgs(null, $arguments);

        self::assertIsInt($result);

        return $result;
    }

    /**
     * @param list<mixed> $arguments
     */
    private static function invokePrivateStatic(string $methodName, array $arguments): mixed
    {
        $method = new \ReflectionMethod(SfntFont::class, $methodName);

        return $method->invokeArgs(null, $arguments);
    }

    /**
     * @param list<GlyphPoint> $points
     *
     * @return list<PathCommand>
     */
    private static function commandsForContour(array $points): array
    {
        $result = self::invokePrivateStatic('commandsForContour', [$points]);

        self::assertIsArray($result);
        self::assertContainsOnlyInstancesOf(PathCommand::class, $result);

        return array_values($result);
    }

    /**
     * @param list<PathCommand> $commands
     */
    private static function pathData(array $commands): string
    {
        return self::describeContour(new Contour($commands));
    }

    /**
     * @param array<string, string> $tables
     */
    private static function sfnt(array $tables): string
    {
        ksort($tables);
        $offset = 12 + \count($tables) * 16;
        $records = '';
        $data = '';

        foreach ($tables as $tag => $table) {
            $records .= $tag . self::u32(0) . self::u32($offset) . self::u32(\strlen($table));
            $padded = self::pad4($table);
            $data .= $padded;
            $offset += \strlen($padded);
        }

        return "\x00\x01\x00\x00" . self::u16(\count($tables)) . self::u16(0) . self::u16(0) . self::u16(0) . $records . $data;
    }

    private static function woffWithTable(string $tag, string $payload, int $originalLength): string
    {
        $offset = 64;
        $length = $offset + \strlen($payload);

        return 'wOFF'
            . "\x00\x01\x00\x00"
            . self::u32($length)
            . self::u16(1)
            . self::u16(0)
            . self::u32(12 + 16 + \strlen(self::pad4(str_repeat("\0", $originalLength))))
            . self::u16(1)
            . self::u16(0)
            . str_repeat("\0", 20)
            . $tag
            . self::u32($offset)
            . self::u32(\strlen($payload))
            . self::u32($originalLength)
            . self::u32(0)
            . $payload;
    }

    private static function pad4(string $data): string
    {
        return $data . str_repeat("\0", (4 - \strlen($data) % 4) % 4);
    }

    private static function tinyFontDataWithTablePatch(string $tag, int $relativeOffset, string $replacement): string
    {
        $path = self::fontPath();
        $data = file_get_contents($path);
        self::assertIsString($data);

        $tableOffset = self::tableOffset($data, $tag);

        return substr_replace($data, $replacement, $tableOffset + $relativeOffset, \strlen($replacement));
    }

    private static function tinyFontDataWithGlyphPatch(int $glyphId, int $relativeOffset, string $replacement): string
    {
        $path = self::fontPath();
        $data = file_get_contents($path);
        self::assertIsString($data);

        $glyfOffset = self::tableOffset($data, 'glyf');
        $locaOffset = self::tableOffset($data, 'loca');
        $glyphOffset = self::unpackUnsignedLong(substr($data, $locaOffset + $glyphId * 4, 4));

        return substr_replace($data, $replacement, $glyfOffset + $glyphOffset + $relativeOffset, \strlen($replacement));
    }

    private static function tableOffset(string $data, string $tag): int
    {
        $numTables = self::unpackUnsignedShort(substr($data, 4, 2));

        for ($i = 0; $i < $numTables; ++$i) {
            $recordOffset = 12 + $i * 16;

            if ($tag === substr($data, $recordOffset, 4)) {
                return self::unpackUnsignedLong(substr($data, $recordOffset + 8, 4));
            }
        }

        self::fail(\sprintf('Table "%s" was not found.', $tag));
    }

    private static function unpackUnsignedShort(string $bytes): int
    {
        $unpacked = unpack('n', $bytes);
        self::assertIsArray($unpacked);

        $value = $unpacked[1];
        self::assertIsInt($value);

        return $value;
    }

    private static function unpackUnsignedLong(string $bytes): int
    {
        $unpacked = unpack('N', $bytes);
        self::assertIsArray($unpacked);

        $value = $unpacked[1];
        self::assertIsInt($value);

        return $value;
    }

    private static function u16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }

    private static function i16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }

    private static function u32(int $value): string
    {
        return pack('N', $value);
    }
}
