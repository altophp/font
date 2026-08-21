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
use Alto\Font\Font;
use Alto\Font\Glyph\GlyphId;
use Alto\Font\OpenType\CmapBuilder;
use Alto\Font\OpenType\GlyfSubsetter;
use Alto\Font\OpenType\SfntDocument;
use Alto\Font\OpenType\Table\CmapTable;
use Alto\Font\Subset\GlyphIdPolicy;
use Alto\Font\Subset\HintingPolicy;
use Alto\Font\Subset\LayoutPolicy;
use Alto\Font\Subset\SubsetOptions;
use Alto\Font\Subset\UnicodeSet;
use Alto\Font\Tests\Fixtures\TinyTrueTypeFont;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GlyfSubsetter::class)]
final class GlyfSubsetterTest extends TestCase
{
    public function testItSubsetsWithoutRenumberingGlyphs(): void
    {
        $result = Font::fromFile(self::fontPath())->subset(new SubsetOptions(UnicodeSet::fromText('A')));

        self::assertSame(1, $result->mappedCodepointCount);
        self::assertSame(2, $result->retainedGlyphCount);
        self::assertSame(5, $result->font->face()->glyphCount);
        self::assertSame(1, $result->font->glyphIdForCodepoint(65)?->value);
        self::assertNull($result->font->glyphIdForCodepoint(86));
    }

    public function testItRetainsCompoundComponents(): void
    {
        $result = Font::fromFile(self::fontPath())->subset(new SubsetOptions(UnicodeSet::fromText('Á')));

        self::assertSame(3, $result->retainedGlyphCount);
        self::assertSame(4, $result->font->glyphIdForCodepoint(193)?->value);
        self::assertNotEmpty($result->font->glyphOutline(new GlyphId(4))->contours);
    }

    public function testItDelegatesCompactGlyphIds(): void
    {
        $result = Font::fromFile(self::fontPath())->subset(new SubsetOptions(
            UnicodeSet::fromText('A'),
            glyphIds: GlyphIdPolicy::Compact,
        ));

        self::assertSame(2, $result->retainedGlyphCount);
        self::assertSame(2, $result->font->face()->glyphCount);
    }

    public function testItUsesGsubClosureWhenLayoutIsPreserved(): void
    {
        $path = self::temporaryPath('gsub.ttf');
        TinyTrueTypeFont::writeWithGsubSingleSubstitution($path);

        $result = Font::fromFile($path)->subset(new SubsetOptions(UnicodeSet::fromText('A')));

        self::assertSame(3, $result->retainedGlyphCount);
        self::assertContains('GSUB', $result->font->face()->tables);
        self::assertContains(
            'GSUB is preserved with stable glyph IDs after conservative glyph closure and is not compacted.',
            $result->warnings,
        );
    }

    public function testItDropsLayoutAndHintingWhenRequested(): void
    {
        $path = self::temporaryPath('hinted-gsub.ttf');
        TinyTrueTypeFont::writeHinted($path);

        $result = Font::fromFile($path)->subset(new SubsetOptions(
            UnicodeSet::fromText('Á'),
            HintingPolicy::Drop,
            layout: LayoutPolicy::Drop,
        ));

        foreach (['cvar', 'cvt ', 'fpgm', 'hdmx', 'LTSH', 'prep', 'VDMX'] as $tag) {
            self::assertNotContains($tag, $result->font->face()->tables);
        }
    }

    public function testItRejectsUnmodeledGlyphTables(): void
    {
        $path = self::temporaryPath('jstf.ttf');
        TinyTrueTypeFont::writeWithTable($path, 'JSTF');

        $this->expectException(UnsupportedFontException::class);
        $this->expectExceptionMessage('Subsetting table "JSTF" requires glyph closure');

        Font::fromFile($path)->subset(new SubsetOptions(UnicodeSet::fromText('A')));
    }

    public function testItRejectsCompoundCycles(): void
    {
        $path = self::temporaryPath('cycle.ttf');
        TinyTrueTypeFont::write($path, compoundCycle: true);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Compound glyph cycle detected');

        Font::fromFile($path)->subset(new SubsetOptions(UnicodeSet::fromText('Á')));
    }

    public function testItRejectsMissingRequiredTables(): void
    {
        $path = self::fontPath();
        [$document, $cmap, $offsets, $glyphCount] = self::source($path);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('requires glyf, head, hhea, hmtx, and maxp tables');

        GlyfSubsetter::subset(
            $document->withTables([], ['glyf']),
            $cmap,
            $offsets,
            $glyphCount,
            new SubsetOptions(UnicodeSet::fromText('A')),
        );
    }

    public function testItRejectsCmapMappingsOutsideTheGlyphArray(): void
    {
        [$document, $_cmap, $offsets, $glyphCount] = self::source(self::fontPath());
        $invalidCmap = CmapTable::parse(new BinaryReader(CmapBuilder::build([65 => 9]), 'invalid test cmap'));

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('cmap maps U+0041 to invalid glyph ID 9');

        GlyfSubsetter::subset(
            $document,
            $invalidCmap,
            $offsets,
            $glyphCount,
            new SubsetOptions(UnicodeSet::fromText('A')),
        );
    }

    public function testItRejectsInvalidGlyphOffsets(): void
    {
        [$document, $cmap, $_offsets, $glyphCount] = self::source(self::fontPath());

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Glyph ID 0 has invalid glyf offsets');

        GlyfSubsetter::subset(
            $document,
            $cmap,
            [0],
            $glyphCount,
            new SubsetOptions(UnicodeSet::fromText('A')),
        );
    }

    public function testItRejectsATruncatedHeadTable(): void
    {
        [$document, $cmap, $offsets, $glyphCount] = self::source(self::fontPath());

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('SFNT head table is truncated');

        GlyfSubsetter::subset(
            $document->withTables(['head' => '']),
            $cmap,
            $offsets,
            $glyphCount,
            new SubsetOptions(UnicodeSet::fromText('A')),
        );
    }

    public function testItHandlesEveryCompoundTransformWhileFindingDependencies(): void
    {
        foreach (['writeCompoundWithUniformScale', 'writeCompoundWithXYScale', 'writeCompoundWithTwoByTwo'] as $writer) {
            $path = self::temporaryPath($writer . '.ttf');
            TinyTrueTypeFont::{$writer}($path);

            $result = Font::fromFile($path)->subset(new SubsetOptions(UnicodeSet::fromText('Á')));

            self::assertSame(3, $result->retainedGlyphCount);
        }
    }

    public function testItSubsetsVariableGlyphDataAndRecalculatesOs2Coverage(): void
    {
        $variablePath = self::temporaryPath('variable.ttf');
        TinyTrueTypeFont::writeVariableWithGvar($variablePath);
        $variable = Font::fromFile($variablePath)->subset(new SubsetOptions(UnicodeSet::fromText('A')));

        self::assertContains('gvar', $variable->font->face()->tables);
        self::assertContains(
            'gvar glyph data is subset; axes and other variable tables are preserved with stable glyph IDs.',
            $variable->warnings,
        );

        $os2Path = self::temporaryPath('os2.ttf');
        TinyTrueTypeFont::writeWithOs2($os2Path);
        $os2 = Font::fromFile($os2Path)->subset(new SubsetOptions(UnicodeSet::fromText('A')));

        self::assertContains('OS/2', $os2->font->face()->tables);
    }

    private static function fontPath(): string
    {
        $path = self::temporaryPath('source.ttf');
        TinyTrueTypeFont::write($path);

        return $path;
    }

    private static function temporaryPath(string $suffix): string
    {
        return sys_get_temp_dir() . '/alto-font-glyf-subsetter-' . bin2hex(random_bytes(4)) . '-' . $suffix;
    }

    /**
     * @return array{SfntDocument, CmapTable, list<int>, int}
     */
    private static function source(string $path): array
    {
        $font = Font::fromFile($path);
        $document = $font->sfntDocument();
        $cmapData = $document->table('cmap');
        $loca = $document->table('loca');
        self::assertNotNull($cmapData);
        self::assertNotNull($loca);
        $cmap = CmapTable::parse(new BinaryReader($cmapData, 'test cmap'));
        $reader = new BinaryReader($loca, 'test loca');
        $offsets = [];

        for ($index = 0; $index <= $font->face()->glyphCount; ++$index) {
            $offsets[] = $reader->uint32($index * 4);
        }

        return [$document, $cmap, $offsets, $font->face()->glyphCount];
    }
}
