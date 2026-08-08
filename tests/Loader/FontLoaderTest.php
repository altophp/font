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

namespace Alto\Font\Tests\Loader;

use Alto\Font\Exception\InvalidFontException;
use Alto\Font\Exception\UnsupportedFontException;
use Alto\Font\Font;
use Alto\Font\FontFinder;
use Alto\Font\FontQuery;
use Alto\Font\Glyph\GlyphId;
use Alto\Font\Loader\FontLoader;
use Alto\Font\Tests\Fixtures\ContourAssertions;
use Alto\Font\Tests\Fixtures\TinyTrueTypeFont;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FontLoader::class)]
final class FontLoaderTest extends TestCase
{
    use ContourAssertions;

    public function testItLoadsStaticTrueTypeMetadata(): void
    {
        $font = self::loadFont($this->fontPath());
        $face = $font->face();

        self::assertSame(1000, $face->unitsPerEm);
        self::assertSame(800, $face->ascender);
        self::assertSame(-200, $face->descender);
        self::assertSame(5, $face->glyphCount);
        self::assertSame('Atelier Tiny', $face->name(1));
        self::assertContains('glyf', $face->tables);
    }

    public function testItLoadsWoffTrueTypeContainers(): void
    {
        $path = sys_get_temp_dir() . '/atelier-font-tiny.woff';
        TinyTrueTypeFont::writeWoff($path);
        $font = self::loadFont($path);
        $glyphId = $font->glyphIdForCodepoint(65);
        self::assertNotNull($glyphId);

        self::assertSame(1000, $font->face()->unitsPerEm);
        self::assertSame('M 100 0 L 300 700 L 500 0 L 100 0 Z', self::describeContour($font->glyphOutline($glyphId)->contours[0]));
    }

    public function testItLoadsNullTransformWoff2TrueTypeContainers(): void
    {
        if (!TinyTrueTypeFont::hasBrotliEncoder()) {
            self::markTestSkipped('The brotli binary is required to generate the WOFF2 fixture.');
        }

        $path = sys_get_temp_dir() . '/atelier-font-tiny.woff2';
        TinyTrueTypeFont::writeWoff2($path);
        $font = self::loadFont($path);
        $glyphId = $font->glyphIdForCodepoint(65);
        self::assertNotNull($glyphId);

        self::assertSame(1000, $font->face()->unitsPerEm);
        self::assertSame('M 100 0 L 300 700 L 500 0 L 100 0 Z', self::describeContour($font->glyphOutline($glyphId)->contours[0]));
    }

    public function testItRejectsTransformedWoff2Tables(): void
    {
        $path = sys_get_temp_dir() . '/atelier-font-transformed.woff2';
        TinyTrueTypeFont::writeTransformedWoff2($path);

        $this->expectException(UnsupportedFontException::class);
        $this->expectExceptionMessage('WOFF2 transformed table "glyf" is not supported');

        self::loadFont($path);
    }

    public function testItMapsAsciiCodepointsToGlyphIdsAndMetrics(): void
    {
        $font = self::loadFont($this->fontPath());
        $glyphId = $font->glyphIdForCodepoint(65);

        self::assertInstanceOf(GlyphId::class, $glyphId);
        self::assertSame(1, $glyphId->value);

        $metrics = $font->glyphMetrics($glyphId);

        self::assertSame(600, $metrics->advanceWidth);
        self::assertSame(10, $metrics->leftSideBearing);
    }

    public function testItProvidesConvenienceAccessors(): void
    {
        $font = Font::fromFile($this->fontPath());
        $glyphId = $font->glyphIdForCodepoint(65);
        self::assertNotNull($glyphId);

        self::assertEquals($font->face(), $font->getFace());
        self::assertEquals($font->glyphMetrics($glyphId), $font->getGlyphMetrics($glyphId));

        $metrics = $font->getMetrics('A');

        self::assertSame(600, $metrics->advanceWidth);
        self::assertSame(10, $metrics->leftSideBearing);
    }

    public function testItExposesFontDescriptor(): void
    {
        $font = Font::fromFile($this->fontPath());
        $descriptor = $font->getDescriptor();
        $metadata = $font->metadata();

        self::assertSame('Atelier Tiny', $descriptor->family);
        self::assertSame('Regular', $descriptor->subfamily);
        self::assertNull($descriptor->fullName);
        self::assertNull($descriptor->postScriptName);
        self::assertSame(400, $descriptor->weight->value);
        self::assertSame('normal', $descriptor->style->value);
        self::assertSame(100, $descriptor->stretch->percentage);
        self::assertSame('Atelier Tiny', $metadata->family);
        self::assertSame('Regular', $metadata->subfamily);
        self::assertSame('truetype', $metadata->format->value);
    }

    public function testItFindsFontsThroughFontFinder(): void
    {
        $directory = sys_get_temp_dir() . '/atelier-font-finder';

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            self::fail(\sprintf('Could not create "%s".', $directory));
        }

        TinyTrueTypeFont::write($directory . '/atelier-tiny.ttf');

        $finder = FontFinder::fromDirectories($directory);

        self::assertTrue($finder->has('Atelier Tiny'));
        self::assertTrue($finder->has(FontQuery::family('Atelier Tiny')->weight(400)));
        self::assertFalse($finder->has('Missing Family'));
        self::assertSame('Atelier Tiny', $finder->get('Atelier Tiny')->getDescriptor()->family);
    }

    public function testItRejectsConvenienceMetricsForTextRuns(): void
    {
        $font = self::loadFont($this->fontPath());

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Font metrics can only be read for a single glyph or character.');

        $font->getMetrics('AV');
    }

    public function testItExportsSimpleGlyphOutlinesAsRawContours(): void
    {
        $font = self::loadFont($this->fontPath());
        $glyphId = $font->glyphIdForCodepoint(65);
        self::assertNotNull($glyphId);

        self::assertSame('M 100 0 L 300 700 L 500 0 L 100 0 Z', self::describeContour($font->glyphOutline($glyphId)->contours[0]));
    }

    public function testItExpandsCompoundGlyphs(): void
    {
        $font = self::loadFont($this->fontPath());
        $glyphId = $font->glyphIdForCodepoint(193);
        self::assertNotNull($glyphId);

        self::assertSame('M 150 0 L 350 700 L 550 0 L 150 0 Z', self::describeContour($font->glyphOutline($glyphId)->contours[0]));
    }

    public function testItReportsMissingGlyphs(): void
    {
        $font = self::loadFont($this->fontPath());

        self::assertNull($font->glyphIdForCodepoint(66));

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Font has no glyph for codepoint U+0042.');

        $font->getMetrics('B');
    }

    public function testItRejectsMissingFiles(): void
    {
        $this->expectException(InvalidFontException::class);

        self::loadFont(__DIR__ . '/missing.ttf');
    }

    public function testItRejectsUnsupportedCffFonts(): void
    {
        $path = $this->fontPath('unsupported.ttf', unsupportedCff: true);

        $this->expectException(UnsupportedFontException::class);
        $this->expectExceptionMessage('CFF/OpenType outlines are not supported');

        self::loadFont($path);
    }

    public function testItRejectsCompoundGlyphCycles(): void
    {
        $font = self::loadFont($this->fontPath('cycle.ttf', compoundCycle: true));
        $glyphId = $font->glyphIdForCodepoint(193);
        self::assertNotNull($glyphId);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Compound glyph cycle detected');

        $font->glyphOutline($glyphId);
    }

    private function fontPath(
        string $filename = 'tiny.ttf',
        bool $compoundCycle = false,
        bool $unsupportedCff = false,
    ): string {
        $path = sys_get_temp_dir() . '/atelier-font-' . $filename;
        TinyTrueTypeFont::write($path, $compoundCycle, $unsupportedCff);

        return $path;
    }

    public function testItLoadsARequestedFaceFromAFontCollection(): void
    {
        $path = sys_get_temp_dir() . '/atelier-font-tiny.ttc';
        TinyTrueTypeFont::writeCollection($path, [1000, 2048]);

        $firstFace = self::loadFont($path)->face();
        $secondFace = (new FontLoader())->load($path, faceIndex: 1)->face();

        self::assertSame(1000, $firstFace->unitsPerEm);
        self::assertSame(0, $firstFace->faceIndex);
        self::assertSame(2, $firstFace->faceCount);
        self::assertSame(2048, $secondFace->unitsPerEm);
        self::assertSame(1, $secondFace->faceIndex);
    }

    public function testFontFromFileAcceptsAFaceIndex(): void
    {
        $path = sys_get_temp_dir() . '/atelier-font-tiny.ttc';
        TinyTrueTypeFont::writeCollection($path, [1000, 2048]);

        self::assertSame(2048, Font::fromFile($path, faceIndex: 1)->face()->unitsPerEm);
    }

    private static function loadFont(string $path): Font
    {
        return (new FontLoader())->load($path);
    }
}
