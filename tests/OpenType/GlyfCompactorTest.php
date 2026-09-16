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
use Alto\Font\Font;
use Alto\Font\Glyph\GlyphId;
use Alto\Font\OpenType\GlyfCompactor;
use Alto\Font\OpenType\GlyfSubset;
use Alto\Font\OpenType\SfntDocument;
use Alto\Font\Subset\GlyphIdPolicy;
use Alto\Font\Subset\HintingPolicy;
use Alto\Font\Subset\LayoutPolicy;
use Alto\Font\Subset\SubsetOptions;
use Alto\Font\Subset\UnicodeSet;
use Alto\Font\Tests\Fixtures\TinyTrueTypeFont;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GlyfCompactor::class)]
final class GlyfCompactorTest extends TestCase
{
    public function testItCompactsGlyphIdsAndHorizontalMetrics(): void
    {
        $result = self::compact(self::fontPath(), 'V');

        self::assertSame(2, $result->retainedGlyphCount);
        self::assertSame(2, $result->font->face()->glyphCount);
        self::assertSame(1, $result->font->glyphIdForCodepoint(86)?->value);
        self::assertSame(610, $result->font->metrics('V')->advanceWidth);
        self::assertSame(20, $result->font->metrics('V')->leftSideBearing);
    }

    public function testItRemapsCompoundComponents(): void
    {
        $result = self::compact(self::fontPath(), 'Á');

        self::assertSame(3, $result->font->face()->glyphCount);
        self::assertSame(2, $result->font->glyphIdForCodepoint(193)?->value);
        self::assertNotEmpty($result->font->glyphOutline(new GlyphId(2))->contours);
    }

    public function testItDropsHintingAndPrivateMetadata(): void
    {
        $path = self::temporaryPath('hinted.ttf');
        TinyTrueTypeFont::writeHinted($path);

        $result = Font::fromFile($path)->subset(new SubsetOptions(
            UnicodeSet::fromText('Á'),
            HintingPolicy::Drop,
            GlyphIdPolicy::Compact,
        ));

        foreach (['cvar', 'cvt ', 'fpgm', 'hdmx', 'LTSH', 'prep', 'VDMX'] as $tag) {
            self::assertNotContains($tag, $result->font->face()->tables);
        }

        $metaPath = self::temporaryPath('meta.ttf');
        TinyTrueTypeFont::writeWithTable($metaPath, 'meta');
        $withMeta = self::compact($metaPath, 'A');

        self::assertNotContains('meta', $withMeta->font->face()->tables);
        self::assertContains(
            'The optional meta table was removed because private metadata cannot be remapped safely.',
            $withMeta->warnings,
        );
    }

    public function testItAppliesLayoutPolicies(): void
    {
        $path = self::temporaryPath('gsub.ttf');
        TinyTrueTypeFont::writeWithGsubSingleSubstitution($path);

        $preserved = self::compact($path, 'A');
        $substitutions = self::compact($path, 'A', LayoutPolicy::SubstitutionsOnly);
        $dropped = self::compact($path, 'A', LayoutPolicy::Drop);

        self::assertContains('GSUB', $preserved->font->face()->tables);
        self::assertContains('GSUB', $substitutions->font->face()->tables);
        self::assertNotContains('GSUB', $dropped->font->face()->tables);
        self::assertSame(3, $preserved->retainedGlyphCount);
        self::assertSame(2, $dropped->retainedGlyphCount);
    }

    public function testItCompactsVariableGlyphAndMetricMappings(): void
    {
        $path = self::temporaryPath('variable.ttf');
        TinyTrueTypeFont::writeVariableWithHvar($path, includeGvar: true);

        $result = self::compact($path, 'A');
        $selected = $result->font->withVariations(['wght' => 900]);

        self::assertContains('gvar', $result->font->face()->tables);
        self::assertContains('HVAR', $result->font->face()->tables);
        self::assertSame(700, $selected->metrics('A')->advanceWidth);
    }

    public function testItCompactsLegacyKerningPairs(): void
    {
        $path = self::temporaryPath('kern.ttf');
        TinyTrueTypeFont::writeWithKern($path);

        $result = self::compact($path, 'Á');
        $kern = $result->font->sfntDocument()->table('kern');
        self::assertNotNull($kern);
        $reader = new BinaryReader($kern, 'compacted kern');

        self::assertSame(1, $reader->uint16(10));
        self::assertSame([1, 2, -20], [
            $reader->uint16(18),
            $reader->uint16(20),
            $reader->int16(22),
        ]);
        self::assertContains('Legacy kern pairs were compacted with remapped glyph IDs.', $result->warnings);
    }

    public function testItCompactsVerticalMetricsAndVariationMappingsTogether(): void
    {
        $path = self::temporaryPath('vvar.ttf');
        TinyTrueTypeFont::writeVariableWithVvar($path);

        $result = self::compact($path, 'V');
        $document = $result->font->sfntDocument();
        $vhea = $document->table('vhea');
        $vmtx = $document->table('vmtx');
        $vvar = $document->table('VVAR');

        self::assertNotNull($vhea);
        self::assertNotNull($vmtx);
        self::assertNotNull($vvar);
        self::assertSame(2, (new BinaryReader($vhea, 'compacted vhea'))->uint16(34));
        self::assertSame(8, \strlen($vmtx));
        $vvarReader = new BinaryReader($vvar, 'compacted VVAR');
        self::assertSame(2, $vvarReader->uint16($vvarReader->uint32(8) + 2));
        self::assertContains('Vertical metrics were compacted with remapped glyph IDs.', $result->warnings);
        self::assertContains('VVAR vertical-metric mappings were compacted; its ItemVariationStore was preserved.', $result->warnings);
    }

    public function testItRequiresVerticalHeaderAndMetricsTogether(): void
    {
        $vhea = str_repeat("\0", 36);
        $vhea = substr_replace($vhea, "\0\1\x10\0", 0, 4);
        $vhea = substr_replace($vhea, "\0\5", 34, 2);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('require vhea and vmtx together');

        self::compactDirect(self::fontPath(), ['vhea' => $vhea]);
    }

    public function testItRejectsTruncatedCoreTables(): void
    {
        foreach ([
            'head' => 'SFNT head table is truncated',
            'maxp' => 'SFNT maxp table is truncated',
            'hhea' => 'SFNT hhea table is truncated',
        ] as $tag => $message) {
            try {
                self::compactDirect(self::fontPath(), [$tag => '']);
                self::fail('Expected an invalid font exception for ' . $tag . '.');
            } catch (InvalidFontException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }
    }

    public function testItRejectsAnInvalidHorizontalMetricCount(): void
    {
        $path = self::fontPath();
        $hhea = Font::fromFile($path)->sfntDocument()->table('hhea');
        self::assertNotNull($hhea);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('numberOfHMetrics is invalid');

        self::compactDirect($path, ['hhea' => substr_replace($hhea, "\0\0", 34, 2)]);
    }

    public function testItRejectsInvalidGlyphOffsets(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Glyph ID 0 has invalid glyf offsets');

        self::compactDirect(self::fontPath(), glyphOffsets: [0]);
    }

    public function testItRejectsCmapGlyphsMissingFromTheCompactMapping(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Retained cmap glyph ID 2 has no compact mapping');

        self::compactDirect(
            self::fontPath(),
            unicodeMappings: [65 => 2],
            retainedGlyphs: [1 => true],
        );
    }

    public function testItRejectsCompoundsWhoseComponentsWereDiscarded(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Compound glyph 4 references discarded glyph ID 1');

        self::compactDirect(
            self::fontPath(),
            unicodeMappings: [193 => 4],
            retainedGlyphs: [4 => true],
        );
    }

    public function testItRemapsComponentsUsingEveryCompoundTransform(): void
    {
        foreach (['writeCompoundWithUniformScale', 'writeCompoundWithXYScale', 'writeCompoundWithTwoByTwo'] as $writer) {
            $path = self::temporaryPath($writer . '.ttf');
            TinyTrueTypeFont::{$writer}($path);

            $result = self::compact($path, 'Á');

            self::assertSame(3, $result->retainedGlyphCount);
            self::assertNotEmpty($result->font->glyphOutline(new GlyphId(2))->contours);
        }
    }

    public function testItRewritesPostAndOs2Tables(): void
    {
        $path = self::fontPath();
        $result = self::compactDirect($path, ['post' => str_repeat("\0", 32)]);
        $post = $result->document->table('post');
        self::assertNotNull($post);
        self::assertSame("\0\3\0\0", substr($post, 0, 4));

        $os2Path = self::temporaryPath('os2.ttf');
        TinyTrueTypeFont::writeWithOs2($os2Path);
        $os2 = self::compact($os2Path, 'A')->font->sfntDocument()->table('OS/2');

        self::assertNotNull($os2);
    }

    public function testItRejectsATruncatedPostTable(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('SFNT post table is truncated');

        self::compactDirect(self::fontPath(), ['post' => "\0"]);
    }

    private static function compact(string $path, string $characters, LayoutPolicy $layout = LayoutPolicy::Preserve): \Alto\Font\Subset\SubsetResult
    {
        return Font::fromFile($path)->subset(new SubsetOptions(
            UnicodeSet::fromText($characters),
            glyphIds: GlyphIdPolicy::Compact,
            layout: $layout,
        ));
    }

    private static function fontPath(): string
    {
        $path = self::temporaryPath('source.ttf');
        TinyTrueTypeFont::write($path);

        return $path;
    }

    private static function temporaryPath(string $suffix): string
    {
        return sys_get_temp_dir() . '/alto-font-glyf-compactor-' . bin2hex(random_bytes(4)) . '-' . $suffix;
    }

    /**
     * @param array<string, string> $replacements
     * @param null|list<int>        $glyphOffsets
     * @param null|array<int, int>  $unicodeMappings
     * @param null|array<int, true> $retainedGlyphs
     */
    private static function compactDirect(
        string $path,
        array $replacements = [],
        ?array $glyphOffsets = null,
        ?array $unicodeMappings = null,
        ?array $retainedGlyphs = null,
    ): GlyfSubset {
        $font = Font::fromFile($path);
        $document = $font->sfntDocument()->withTables($replacements);
        $glyphCount = $font->face()->glyphCount;
        $glyf = self::table($document, 'glyf');
        $glyphOffsets ??= self::glyphOffsets($document, $glyphCount);

        return GlyfCompactor::compact(
            $document,
            $glyf,
            self::table($document, 'head'),
            self::table($document, 'hhea'),
            self::table($document, 'hmtx'),
            self::table($document, 'maxp'),
            $glyphOffsets,
            $glyphCount,
            $unicodeMappings ?? [65 => 1],
            $retainedGlyphs ?? [1 => true],
            HintingPolicy::Keep,
            LayoutPolicy::Preserve,
        );
    }

    /**
     * @return list<int>
     */
    private static function glyphOffsets(SfntDocument $document, int $glyphCount): array
    {
        $reader = new BinaryReader(self::table($document, 'loca'), 'test loca');
        $offsets = [];

        for ($index = 0; $index <= $glyphCount; ++$index) {
            $offsets[] = $reader->uint32($index * 4);
        }

        return $offsets;
    }

    private static function table(SfntDocument $document, string $tag): string
    {
        $table = $document->table($tag);
        self::assertNotNull($table);

        return $table;
    }
}
