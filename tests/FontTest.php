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

namespace Alto\Font\Tests;

use Alto\Font\Exception\InvalidFontException;
use Alto\Font\Font;
use Alto\Font\Glyph\GlyphId;
use Alto\Font\Tests\Fixtures\ContourAssertions;
use Alto\Font\Tests\Fixtures\TinyTrueTypeFont;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Font::class)]
final class FontTest extends TestCase
{
    use ContourAssertions;

    public function testItLoadsFromStringableFiles(): void
    {
        $path = self::fontPath();
        $file = new class ($path) implements \Stringable {
            public function __construct(private readonly string $path) {}

            public function __toString(): string
            {
                return $this->path;
            }
        };

        self::assertSame('Atelier Tiny', Font::fromFile($file)->getFace()->name(1));
    }

    public function testItExposesFaceDescriptorMetadataAndGlyphOutlines(): void
    {
        $font = Font::fromFile(self::fontPath());
        $glyphId = new GlyphId(1);

        self::assertEquals($font->face(), $font->getFace());
        self::assertSame('Atelier Tiny', $font->getDescriptor()->family);
        self::assertSame('Atelier Tiny', $font->metadata()->family);
        self::assertSame('M 100 0 L 300 700 L 500 0 L 100 0 Z', self::describeContour($font->glyphOutline($glyphId)->contours[0]));
    }

    public function testItExposesAndSelectsVariationCoordinates(): void
    {
        $font = Font::fromFile(self::variableFontPath());
        $variations = $font->variations();
        self::assertNotNull($variations);

        self::assertSame(['wght', 'wdth'], array_map(
            static fn($axis): string => $axis->tag,
            $variations->axes,
        ));

        $selected = $font->withVariations(['wght' => 800, 'wdth' => 50])->variationCoordinates();
        self::assertNotNull($selected);

        self::assertSame(['wght' => 800.0, 'wdth' => 75.0], $selected->values);
    }

    public function testItAppliesVariationCoordinatesToGlyphOutlines(): void
    {
        $font = Font::fromFile(self::variableGvarFontPath());
        $glyphId = new GlyphId(1);

        self::assertSame('M 100 0 L 300 700 L 500 0 L 100 0 Z', self::describeContour($font->glyphOutline($glyphId)->contours[0]));
        self::assertSame('M 80 0 L 300 700 L 520 0 L 80 0 Z', self::describeContour($font->withVariations(['wght' => 900])->glyphOutline($glyphId)->contours[0]));
        self::assertSame('M 120 0 L 300 700 L 480 0 L 120 0 Z', self::describeContour($font->withVariations(['wdth' => 75])->glyphOutline($glyphId)->contours[0]));
        self::assertSame('M 100 0 L 300 700 L 500 0 L 100 0 Z', self::describeContour($font->withVariations(['wght' => 900, 'wdth' => 75])->glyphOutline($glyphId)->contours[0]));
        self::assertSame(640, $font->withVariations(['wght' => 900])->getMetrics('A')->advanceWidth);
        self::assertSame(520, $font->withVariations(['wdth' => 75])->getMetrics('A')->advanceWidth);
    }

    public function testItAppliesVariationCoordinatesToCompoundGlyphComponents(): void
    {
        $font = Font::fromFile(self::variableGvarFontPath())->withVariations(['wght' => 900]);

        self::assertSame('M 130 0 L 350 700 L 570 0 L 130 0 Z', self::describeContour($font->glyphOutline(new GlyphId(4))->contours[0]));
    }

    public function testItAppliesCompoundGlyphOwnVariationDeltasToComponentOffsets(): void
    {
        $font = Font::fromFile(self::variableCompoundGvarFontPath())->withVariations(['wght' => 900]);

        self::assertSame('M 180 40 L 380 740 L 580 40 L 180 40 Z', self::describeContour($font->glyphOutline(new GlyphId(4))->contours[0]));
    }

    public function testItUsesComponentMetricsForVariableCompoundsWithUseMyMetrics(): void
    {
        $font = Font::fromFile(self::variableUseMyMetricsGvarFontPath())->withVariations(['wght' => 900]);
        $metrics = $font->getMetrics('Á');

        self::assertSame(600, $metrics->advanceWidth);
        self::assertSame(10, $metrics->leftSideBearing);
    }

    public function testItInfersSparseGvarDeltasInsideSimpleGlyphContours(): void
    {
        $font = Font::fromFile(self::variableSparseGvarFontPath())->withVariations(['wght' => 900]);

        self::assertSame('M 100 0 L 100 700 L 400 700 L 400 0 L 100 0 Z', self::describeContour($font->glyphOutline(new GlyphId(3))->contours[0]));
    }

    public function testItUsesHvarForVariableMetrics(): void
    {
        $font = Font::fromFile(self::variableHvarFontPath());

        self::assertSame(700, $font->withVariations(['wght' => 900])->getMetrics('A')->advanceWidth);
        self::assertSame(15, $font->withVariations(['wght' => 900])->getMetrics('A')->leftSideBearing);
        self::assertSame(480, $font->withVariations(['wdth' => 75])->getMetrics('A')->advanceWidth);
        self::assertSame(0, $font->withVariations(['wdth' => 75])->getMetrics('A')->leftSideBearing);
    }

    public function testItPrefersHvarOverGvarPhantomMetrics(): void
    {
        $font = Font::fromFile(self::variableHvarAndGvarFontPath())->withVariations(['wght' => 900]);

        self::assertSame(700, $font->getMetrics('A')->advanceWidth);
    }

    public function testItRejectsVariationsForStaticFonts(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Font does not define variation axes.');

        Font::fromFile(self::fontPath())->withVariations(['wght' => 800]);
    }

    public function testItRejectsUnknownVariationAxes(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Variation axis "opsz" is not defined');

        Font::fromFile(self::variableFontPath())->withVariations(['opsz' => 14]);
    }

    public function testItExposesGlyphMetricsByIdOrCharacter(): void
    {
        $font = Font::fromFile(self::fontPath());
        $glyphId = new GlyphId(1);

        self::assertSame(600, $font->glyphMetrics($glyphId)->advanceWidth);
        self::assertSame(600, $font->getGlyphMetrics($glyphId)->advanceWidth);
        self::assertSame(600, $font->getMetrics($glyphId)->advanceWidth);
        self::assertSame(600, $font->getMetrics('A')->advanceWidth);
    }

    public function testItRejectsMetricsForRuns(): void
    {
        $this->expectException(InvalidFontException::class);

        Font::fromFile(self::fontPath())->getMetrics('AV');
    }

    public function testItRejectsMetricsForMissingCharacters(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Font has no glyph for codepoint U+0042.');

        Font::fromFile(self::fontPath())->getMetrics('B');
    }

    private static function fontPath(): string
    {
        $path = sys_get_temp_dir() . '/alto-font-font-' . bin2hex(random_bytes(4)) . '.ttf';
        TinyTrueTypeFont::write($path);

        return $path;
    }

    private static function variableFontPath(): string
    {
        $path = sys_get_temp_dir() . '/alto-font-variable-font-' . bin2hex(random_bytes(4)) . '.ttf';
        TinyTrueTypeFont::writeVariable($path);

        return $path;
    }

    private static function variableGvarFontPath(): string
    {
        $path = sys_get_temp_dir() . '/alto-font-variable-gvar-font-' . bin2hex(random_bytes(4)) . '.ttf';
        TinyTrueTypeFont::writeVariableWithGvar($path);

        return $path;
    }

    private static function variableHvarFontPath(): string
    {
        $path = sys_get_temp_dir() . '/alto-font-variable-hvar-font-' . bin2hex(random_bytes(4)) . '.ttf';
        TinyTrueTypeFont::writeVariableWithHvar($path);

        return $path;
    }

    private static function variableSparseGvarFontPath(): string
    {
        $path = sys_get_temp_dir() . '/alto-font-variable-sparse-gvar-font-' . bin2hex(random_bytes(4)) . '.ttf';
        TinyTrueTypeFont::writeVariableWithSparseGvar($path);

        return $path;
    }

    private static function variableCompoundGvarFontPath(): string
    {
        $path = sys_get_temp_dir() . '/alto-font-variable-compound-gvar-font-' . bin2hex(random_bytes(4)) . '.ttf';
        TinyTrueTypeFont::writeVariableWithCompoundGvar($path);

        return $path;
    }

    private static function variableUseMyMetricsGvarFontPath(): string
    {
        $path = sys_get_temp_dir() . '/alto-font-variable-use-my-metrics-gvar-font-' . bin2hex(random_bytes(4)) . '.ttf';
        TinyTrueTypeFont::writeVariableWithUseMyMetricsGvar($path);

        return $path;
    }

    private static function variableHvarAndGvarFontPath(): string
    {
        $path = sys_get_temp_dir() . '/alto-font-variable-hvar-gvar-font-' . bin2hex(random_bytes(4)) . '.ttf';
        TinyTrueTypeFont::writeVariableWithHvar($path, includeGvar: true);

        return $path;
    }
}
