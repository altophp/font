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
use Alto\Font\Exception\UnsupportedFontException;
use Alto\Font\Font;
use Alto\Font\FontMetrics;
use Alto\Font\Geometry\BoundingBox;
use Alto\Font\Glyph\GlyphId;
use Alto\Font\Metadata\FontFormat;
use Alto\Font\OpenType\SfntFont;
use Alto\Font\Subset\GlyphIdPolicy;
use Alto\Font\Subset\SubsetOptions;
use Alto\Font\Subset\UnicodeSet;
use Alto\Font\Tests\Fixtures\TinyTrueTypeFont;
use Alto\Font\Writer\WoffWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Font::class)]
#[CoversClass(FontMetrics::class)]
#[CoversClass(BoundingBox::class)]
#[CoversClass(SfntFont::class)]
final class FontDataTest extends TestCase
{
    public function testBytesLoadWithoutAFileAndRoundTripThroughWoff(): void
    {
        $font = self::fixture();
        $bytes = $font->toSfnt();
        $loaded = Font::fromBytes($bytes);
        self::assertSame('<memory>', $loaded->face()->path);
        self::assertSame(FontFormat::TrueType, $loaded->face()->format);
        self::assertSame($bytes, $loaded->toSfnt());
        self::assertEquals($font->glyphMetrics(new GlyphId(1)), $loaded->glyphMetrics(new GlyphId(1)));
        $woff = Font::fromBytes(new WoffWriter()->dump($loaded));
        self::assertSame(FontFormat::Woff, $woff->face()->format);
        self::assertEquals($loaded->metrics(), $woff->metrics());
    }

    public function testBytesSelectCollectionFaces(): void
    {
        $font = self::fixture(collection: true);
        self::assertSame(2048, $font->face()->unitsPerEm);
        self::assertSame(1, $font->face()->faceIndex);
        self::assertSame(2, $font->face()->faceCount);
        self::assertSame(FontFormat::TrueTypeCollection, $font->face()->format);
        self::assertSame(2048, Font::fromBytes($font->toSfnt())->metrics()->unitsPerEm);
    }

    #[DataProvider('invalidBytes')]
    public function testBytesRejectInvalidContainers(string $bytes): void
    {
        $this->expectException(InvalidFontException::class);
        Font::fromBytes($bytes);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidBytes(): iterable
    {
        yield 'empty' => [''];
        yield 'truncated' => ["\x00\x01"];
        yield 'unknown' => ['invalid font'];
    }

    #[DataProvider('invalidFaceIndexes')]
    public function testBytesRejectInvalidFaceIndexes(int $index, bool $woff): void
    {
        $font = self::fixture();
        $bytes = $woff ? new WoffWriter()->dump($font) : $font->toSfnt();
        $this->expectException(InvalidFontException::class);
        Font::fromBytes($bytes, $index);
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function invalidFaceIndexes(): iterable
    {
        yield 'negative SFNT' => [-1, false];
        yield 'nonzero SFNT' => [1, false];
        yield 'negative WOFF' => [-1, true];
        yield 'nonzero WOFF' => [1, true];
    }

    public function testMetricsExposeDeclaredBoundsWithoutReadingOutlines(): void
    {
        // A cyclic outline would throw if bounds were computed by walking glyphs.
        $font = self::fixture(cycle: true);
        $head = $font->sfntDocument()->table('head');
        self::assertNotNull($head);
        $head = substr_replace($head, pack('n4', -120, -250, 1050, 950), 36, 8);
        $hhea = $font->sfntDocument()->table('hhea');
        self::assertNotNull($hhea);
        $hhea = substr_replace($hhea, pack('n', -25), 8, 2);
        $font = self::withTables($font, ['head' => $head, 'hhea' => $hhea]);
        $metrics = $font->metrics();
        self::assertSame(1000, $metrics->unitsPerEm);
        self::assertSame(800, $metrics->ascender);
        self::assertSame(-200, $metrics->descender);
        self::assertSame(-25, $metrics->lineGap);
        self::assertEquals(new BoundingBox(-120, -250, 1050, 950), $metrics->bounds);
        self::assertNull($metrics->capHeight);
        self::assertNull($metrics->xHeight);
        self::assertNull($metrics->italicAngle);
        self::assertNull($metrics->isFixedPitch);
        self::assertNull($metrics->embeddingFlags);
    }

    #[DataProvider('optionalMetrics')]
    public function testMetricsRespectVersionsAndMissingValues(int $version, int $height, int $fixedPitch, float $angle): void
    {
        $os2 = str_repeat("\0", 100);
        $os2 = substr_replace($os2, pack('n', $version), 0, 2);
        $os2 = substr_replace($os2, pack('n', 0x0304), 8, 2);
        $os2 = substr_replace($os2, pack('n2', $height, $height + 100), 86, 4);
        $post = str_repeat("\0", 32);
        $post = substr_replace($post, pack('N', (int) ($angle * 65536)), 4, 4);
        $post = substr_replace($post, pack('N', $fixedPitch), 12, 4);
        $metrics = self::withTables(self::fixture(), ['OS/2' => $os2, 'post' => $post])->metrics();
        self::assertSame($version >= 2 && $height !== 0 ? $height : null, $metrics->xHeight);
        self::assertSame($version >= 2 && $height !== -100 ? $height + 100 : null, $metrics->capHeight);
        self::assertSame($angle, $metrics->italicAngle);
        self::assertSame($fixedPitch !== 0, $metrics->isFixedPitch);
        self::assertSame(0x0304, $metrics->embeddingFlags);
    }

    /**
     * @return iterable<string, array{int, int, int, float}>
     */
    public static function optionalMetrics(): iterable
    {
        yield 'version zero ignores trailing heights' => [0, 500, 0, 0.0];
        yield 'version one ignores trailing heights' => [1, 500, 2, -12.5];
        yield 'version two' => [2, 500, 1, -12.5];
        yield 'version three signed heights' => [3, -200, 0, 7.25];
        yield 'version four unspecified x height' => [4, 0, 0, 0.0];
        yield 'version five unspecified cap height' => [5, -100, 0xFFFFFFFF, 0.0];
    }

    public function testLegacyOs2DoesNotRequireNewerFields(): void
    {
        $os2 = str_repeat("\0", 68);
        $metrics = self::withTables(self::fixture(), ['OS/2' => $os2])->metrics();
        self::assertSame(0, $metrics->embeddingFlags);
        self::assertNull($metrics->capHeight);
        self::assertNull($metrics->xHeight);
    }

    #[DataProvider('truncatedTables')]
    public function testTruncatedOptionalFieldsFailInsteadOfInventingMetrics(string $tag, string $data): void
    {
        $font = self::withTables(self::fixture(), [$tag => $data]);
        $this->expectException(InvalidFontException::class);
        $font->metrics();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function truncatedTables(): iterable
    {
        yield 'OS/2 version' => ['OS/2', "\0"];
        yield 'OS/2 flags' => ['OS/2', str_repeat("\0", 9)];
        yield 'OS/2 x height' => ['OS/2', "\0\2" . str_repeat("\0", 85)];
        yield 'OS/2 cap height' => ['OS/2', "\0\2" . str_repeat("\0", 87)];
        yield 'post angle' => ['post', str_repeat("\0", 7)];
        yield 'post fixed pitch' => ['post', str_repeat("\0", 15)];
    }

    public function testSelectedVariableMetricsAreExplicitlyUnsupported(): void
    {
        $font = self::fixture(variable: true);
        self::assertSame(1000, $font->metrics()->unitsPerEm);
        $selected = $font->withVariations(['wght' => 700]);
        self::assertEquals($font->metrics(), $selected->withoutVariations()->metrics());
        $this->expectException(UnsupportedFontException::class);
        $selected->metrics();
    }

    public function testSubsetBytesAndBoundsDescribeTheResultingFont(): void
    {
        $source = self::fixture();
        $subset = $source->subset(new SubsetOptions(UnicodeSet::fromText('A'), glyphIds: GlyphIdPolicy::Compact))->font;
        $reopened = Font::fromBytes($subset->toSfnt());
        self::assertSame(2, $reopened->face()->glyphCount);
        self::assertEquals(new BoundingBox(100, 0, 500, 700), $reopened->metrics()->bounds);
        self::assertNotNull($reopened->glyphIdForCodepoint(65));
        self::assertSame(5, $source->face()->glyphCount);
    }

    /**
     * @param array<string, string> $tables
     */
    private static function withTables(Font $font, array $tables): Font
    {
        return Font::fromBytes($font->sfntDocument()->withTables($tables)->toSfnt());
    }

    private static function fixture(bool $collection = false, bool $cycle = false, bool $variable = false): Font
    {
        $path = sys_get_temp_dir() . '/alto-font-data-' . bin2hex(random_bytes(8));
        try {
            if ($collection) {
                TinyTrueTypeFont::writeCollection($path);
            } elseif ($variable) {
                TinyTrueTypeFont::writeVariable($path);
            } else {
                TinyTrueTypeFont::write($path, compoundCycle: $cycle);
            }
            $bytes = file_get_contents($path);
            self::assertIsString($bytes);
            return Font::fromBytes($bytes, $collection ? 1 : 0);
        } finally {
            unlink($path);
        }
    }
}
