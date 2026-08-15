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

use Alto\Font\Binary\BinaryReader;
use Alto\Font\Exception\InvalidFontException;
use Alto\Font\Exception\UnsupportedFontException;
use Alto\Font\Font;
use Alto\Font\Glyph\GlyphId;
use Alto\Font\Metadata\FontFormat;
use Alto\Font\OpenType\Table\GsubTable;
use Alto\Font\Subset\GlyphIdPolicy;
use Alto\Font\Subset\HintingPolicy;
use Alto\Font\Subset\LayoutPolicy;
use Alto\Font\Subset\SubsetOptions;
use Alto\Font\Subset\UnicodeSet;
use Alto\Font\Tests\Fixtures\ContourAssertions;
use Alto\Font\Tests\Fixtures\TinyTrueTypeFont;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testItDoesNotMistakeASelectedVariableViewForAStaticFont(): void
    {
        $font = Font::fromFile(self::variableGvarFontPath())->withVariations(['wght' => 800]);

        $this->expectException(UnsupportedFontException::class);
        $this->expectExceptionMessage('selected variable-font instance is not supported');

        $font->toSfnt();
    }

    public function testItCanReturnFromASelectedViewToTheVariableSource(): void
    {
        $font = Font::fromFile(self::variableGvarFontPath())->withVariations(['wght' => 800]);
        $source = $font->withoutVariations();

        self::assertNull($source->variationCoordinates());
        self::assertNotNull($source->variations());
        self::assertNotSame('', $source->toSfnt());
        self::assertSame($source, $source->withoutVariations());
    }

    public function testItPreservesAnUnchangedStandaloneSfntByteForByte(): void
    {
        $path = self::temporaryPath('signed.ttf');
        TinyTrueTypeFont::writeWithDsig($path);
        $source = file_get_contents($path);

        self::assertIsString($source);
        self::assertSame($source, Font::fromFile($path)->toSfnt());
    }

    public function testItRemovesDsigFromATransformedSubset(): void
    {
        $path = self::temporaryPath('signed.ttf');
        TinyTrueTypeFont::writeWithDsig($path);

        $result = Font::fromFile($path)->subset(new SubsetOptions(UnicodeSet::fromText('A')));

        self::assertNotContains('DSIG', $result->font->face()->tables);
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

    public function testItSubsetsStaticTrueTypeFontsByUnicodeWithoutRenumberingGlyphs(): void
    {
        $result = Font::fromFile(self::fontPath())->subset(new SubsetOptions(UnicodeSet::fromText('A')));

        self::assertSame(1, $result->mappedCodepointCount);
        self::assertSame(5, $result->originalGlyphCount);
        self::assertSame(2, $result->retainedGlyphCount);
        self::assertSame('M 100 0 L 300 700 L 500 0 L 100 0 Z', self::describeContour($result->font->glyphOutline(new GlyphId(1))->contours[0]));
        self::assertSame(600, $result->font->getMetrics('A')->advanceWidth);
        self::assertNull($result->font->glyphIdForCodepoint(86));
        self::assertSame(\strlen($result->font->toSfnt()), $result->outputSize);
        self::assertSame(FontFormat::TrueType, $result->font->face()->format);
        self::assertSame(FontFormat::TrueType, $result->font->metadata()->format);

        $head = $result->font->sfntDocument()->table('head');
        $hhea = $result->font->sfntDocument()->table('hhea');
        self::assertNotNull($head);
        self::assertNotNull($hhea);
        $headReader = new BinaryReader($head, 'subset head');
        $hheaReader = new BinaryReader($hhea, 'subset hhea');
        self::assertSame([100, 0, 500, 700], [
            $headReader->int16(36),
            $headReader->int16(38),
            $headReader->int16(40),
            $headReader->int16(42),
        ]);
        self::assertSame([650, 10, 190, 410], [
            $hheaReader->uint16(10),
            $hheaReader->int16(12),
            $hheaReader->int16(14),
            $hheaReader->int16(16),
        ]);
    }

    public function testItCompactsGlyphIdsForSupportedStaticTrueTypeFonts(): void
    {
        $font = Font::fromFile(self::fontPath());
        $preserved = $font->subset(new SubsetOptions(UnicodeSet::fromText('A')));
        $compact = $font->subset(new SubsetOptions(
            UnicodeSet::fromText('A'),
            glyphIds: GlyphIdPolicy::Compact,
        ));

        self::assertSame(5, $compact->originalGlyphCount);
        self::assertSame(2, $compact->retainedGlyphCount);
        self::assertSame(2, $compact->font->face()->glyphCount);
        self::assertSame(1, $compact->font->glyphIdForCodepoint(65)?->value);
        self::assertSame('M 100 0 L 300 700 L 500 0 L 100 0 Z', self::describeContour($compact->font->glyphOutline(new GlyphId(1))->contours[0]));
        self::assertLessThan($preserved->outputSize, $compact->outputSize);
        self::assertContains(
            'Glyph IDs were compacted and PostScript glyph names were removed when present.',
            $compact->warnings,
        );
    }

    public function testItRemapsCompoundGlyphComponentsWhileCompacting(): void
    {
        $compact = Font::fromFile(self::fontPath())->subset(new SubsetOptions(
            UnicodeSet::fromText('Á'),
            glyphIds: GlyphIdPolicy::Compact,
        ));

        self::assertSame(3, $compact->font->face()->glyphCount);
        self::assertSame(2, $compact->font->glyphIdForCodepoint(193)?->value);
        self::assertSame('M 150 0 L 350 700 L 550 0 L 150 0 Z', self::describeContour($compact->font->glyphOutline(new GlyphId(2))->contours[0]));
    }

    public function testItRemapsMetricsForNonAdjacentCompactGlyphs(): void
    {
        $compact = Font::fromFile(self::fontPath())->subset(new SubsetOptions(
            UnicodeSet::fromText('V'),
            glyphIds: GlyphIdPolicy::Compact,
        ));

        self::assertSame(2, $compact->font->face()->glyphCount);
        self::assertSame(1, $compact->font->glyphIdForCodepoint(86)?->value);
        self::assertSame(610, $compact->font->getMetrics('V')->advanceWidth);
        self::assertSame(20, $compact->font->getMetrics('V')->leftSideBearing);
    }

    public function testItCompactsSupportedLayoutByDefault(): void
    {
        $path = self::temporaryPath('gsub-compact.ttf');
        TinyTrueTypeFont::writeWithGsubSingleSubstitution($path);

        $compact = Font::fromFile($path)->subset(new SubsetOptions(
            UnicodeSet::fromText('A'),
            glyphIds: GlyphIdPolicy::Compact,
        ));

        self::assertSame(3, $compact->retainedGlyphCount);
        self::assertContains('GSUB', $compact->font->face()->tables);
        self::assertContains('GSUB substitutions were compacted with remapped glyph IDs.', $compact->warnings);
    }

    public function testItCanDropLayoutTablesExplicitlyWhileCompacting(): void
    {
        $path = self::temporaryPath('gsub-drop-compact.ttf');
        TinyTrueTypeFont::writeWithGsubSingleSubstitution($path);

        $compact = Font::fromFile($path)->subset(new SubsetOptions(
            UnicodeSet::fromText('A'),
            glyphIds: GlyphIdPolicy::Compact,
            layout: LayoutPolicy::Drop,
        ));

        self::assertSame(2, $compact->retainedGlyphCount);
        self::assertSame(2, $compact->font->face()->glyphCount);
        self::assertNotContains('GSUB', $compact->font->face()->tables);
        self::assertContains(
            'OpenType layout tables GSUB, GPOS, and GDEF were removed explicitly.',
            $compact->warnings,
        );
    }

    public function testItDropsPrivateMetadataWhileCompacting(): void
    {
        $path = self::temporaryPath('meta-compact.ttf');
        TinyTrueTypeFont::writeWithTable($path, 'meta');

        $compact = Font::fromFile($path)->subset(new SubsetOptions(
            UnicodeSet::fromText('A'),
            glyphIds: GlyphIdPolicy::Compact,
        ));

        self::assertNotContains('meta', $compact->font->face()->tables);
        self::assertContains(
            'The optional meta table was removed because private metadata cannot be remapped safely.',
            $compact->warnings,
        );
    }

    public function testItCompactsGsubSubstitutionsWhileDroppingPositioning(): void
    {
        $path = self::temporaryPath('gsub-substitutions-compact.ttf');
        TinyTrueTypeFont::writeWithGsubExtensionSubstitution($path);

        $compact = Font::fromFile($path)->subset(new SubsetOptions(
            UnicodeSet::fromText('A'),
            glyphIds: GlyphIdPolicy::Compact,
            layout: LayoutPolicy::SubstitutionsOnly,
        ));
        $gsub = $compact->font->sfntDocument()->table('GSUB');
        self::assertNotNull($gsub);
        $closure = GsubTable::parse(new BinaryReader($gsub, 'compacted test GSUB'))
            ->glyphClosure([1 => true], $compact->font->face()->glyphCount);

        self::assertSame(3, $compact->retainedGlyphCount);
        self::assertSame([1, 2], array_keys($closure));
        self::assertContains('GSUB substitutions were compacted with remapped glyph IDs.', $compact->warnings);
    }

    public function testItCompactsGsubLigatureComponentsAndOutputs(): void
    {
        $path = self::temporaryPath('gsub-ligature-compact.ttf');
        TinyTrueTypeFont::writeWithGsubLigatureSubstitution($path);

        $compact = Font::fromFile($path)->subset(new SubsetOptions(
            UnicodeSet::fromText('A'),
            glyphIds: GlyphIdPolicy::Compact,
            layout: LayoutPolicy::SubstitutionsOnly,
        ));
        $gsub = $compact->font->sfntDocument()->table('GSUB');
        self::assertNotNull($gsub);
        $closure = GsubTable::parse(new BinaryReader($gsub, 'compacted ligature GSUB'))
            ->glyphClosure([1 => true], $compact->font->face()->glyphCount);
        $closureGlyphIds = array_keys($closure);
        sort($closureGlyphIds, \SORT_NUMERIC);

        self::assertSame(4, $compact->retainedGlyphCount);
        self::assertSame([1, 2, 3], $closureGlyphIds);
    }

    public function testItRetainsCompoundGlyphComponents(): void
    {
        $result = Font::fromFile(self::fontPath())->subset(new SubsetOptions(UnicodeSet::fromText('Á')));

        self::assertSame(3, $result->retainedGlyphCount);
        self::assertSame(4, $result->font->glyphIdForCodepoint(193)?->value);
        self::assertNull($result->font->glyphIdForCodepoint(65));
        self::assertSame('M 150 0 L 350 700 L 550 0 L 150 0 Z', self::describeContour($result->font->glyphOutline(new GlyphId(4))->contours[0]));
    }

    public function testItRetainsGlyphsIntroducedByGsub(): void
    {
        $path = self::temporaryPath('gsub-single.ttf');
        TinyTrueTypeFont::writeWithGsubSingleSubstitution($path);

        $result = Font::fromFile($path)->subset(new SubsetOptions(UnicodeSet::fromText('A')));

        self::assertSame(3, $result->retainedGlyphCount);
        self::assertNotEmpty($result->font->glyphOutline(new GlyphId(2))->contours);
        self::assertContains('GSUB', $result->font->face()->tables);
        self::assertContains(
            'GSUB is preserved with stable glyph IDs after conservative glyph closure and is not compacted.',
            $result->warnings,
        );
    }

    public function testGsubLigatureClosureAlsoRetainsCompoundComponents(): void
    {
        $path = self::temporaryPath('gsub-ligature.ttf');
        TinyTrueTypeFont::writeWithGsubLigatureSubstitution($path);

        $result = Font::fromFile($path)->subset(new SubsetOptions(UnicodeSet::fromText('A')));

        self::assertSame(4, $result->retainedGlyphCount);
        self::assertNotEmpty($result->font->glyphOutline(new GlyphId(4))->contours);
    }

    public function testItClosesGsubExtensionLookups(): void
    {
        $path = self::temporaryPath('gsub-extension.ttf');
        TinyTrueTypeFont::writeWithGsubExtensionSubstitution($path);

        $result = Font::fromFile($path)->subset(new SubsetOptions(UnicodeSet::fromText('A')));

        self::assertSame(3, $result->retainedGlyphCount);
        self::assertNotEmpty($result->font->glyphOutline(new GlyphId(2))->contours);
    }

    public function testItFailsClosedForUnsupportedContextualGsubFormats(): void
    {
        $path = self::temporaryPath('gsub-contextual.ttf');
        TinyTrueTypeFont::writeWithUnsupportedGsubLookup($path);

        $this->expectException(UnsupportedFontException::class);
        $this->expectExceptionMessage('type 6 uses unsupported format 4');

        Font::fromFile($path)->subset(new SubsetOptions(UnicodeSet::fromText('A')));
    }

    public function testItFailsClosedForUnsupportedGsubSubtableFormats(): void
    {
        $path = self::temporaryPath('gsub-format.ttf');
        TinyTrueTypeFont::writeWithUnsupportedGsubFormat($path);

        $this->expectException(UnsupportedFontException::class);
        $this->expectExceptionMessage('type 1 uses unsupported format 3');

        Font::fromFile($path)->subset(new SubsetOptions(UnicodeSet::fromText('A')));
    }

    #[DataProvider('unmodeledGlyphTables')]
    public function testItFailsClosedForUnmodeledGlyphTables(string $tag): void
    {
        $path = self::temporaryPath('unmodeled-table.ttf');
        TinyTrueTypeFont::writeWithTable($path, $tag);

        $this->expectException(UnsupportedFontException::class);

        Font::fromFile($path)->subset(new SubsetOptions(UnicodeSet::fromText('A')));
    }

    public function testItRejectsCompoundGlyphCyclesWhileSubsetting(): void
    {
        $path = self::temporaryPath('compound-cycle.ttf');
        TinyTrueTypeFont::write($path, compoundCycle: true);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Compound glyph cycle detected');

        Font::fromFile($path)->subset(new SubsetOptions(UnicodeSet::fromText('Á')));
    }

    public function testItSubsetsVariableGlyphDataWhilePreservingAxesAndHvar(): void
    {
        $font = Font::fromFile(self::variableHvarAndGvarFontPath());
        $result = $font->subset(new SubsetOptions(UnicodeSet::fromText('A')));
        $selected = $result->font->withVariations(['wght' => 900]);

        self::assertSame(2, $result->retainedGlyphCount);
        self::assertNotNull($result->font->variations());
        self::assertContains('gvar', $result->font->face()->tables);
        self::assertContains('HVAR', $result->font->face()->tables);
        self::assertSame('M 80 0 L 300 700 L 520 0 L 80 0 Z', self::describeContour($selected->glyphOutline(new GlyphId(1))->contours[0]));
        self::assertSame(700, $selected->getMetrics('A')->advanceWidth);
        self::assertContains(
            'gvar glyph data is subset; axes and other variable tables are preserved with stable glyph IDs.',
            $result->warnings,
        );
    }

    public function testItCompactsVariableGlyphAndHorizontalMetricMappings(): void
    {
        $path = self::variableHvarAndGvarFontPath();
        $source = Font::fromFile($path)->withVariations(['wght' => 900]);
        $result = Font::fromFile($path)->subset(new SubsetOptions(
            UnicodeSet::fromText('A'),
            glyphIds: GlyphIdPolicy::Compact,
        ));
        $selected = $result->font->withVariations(['wght' => 900]);
        $sourceGlyphId = $source->glyphIdForCodepoint(65);
        $selectedGlyphId = $selected->glyphIdForCodepoint(65);
        self::assertNotNull($sourceGlyphId);
        self::assertNotNull($selectedGlyphId);

        self::assertSame(2, $result->retainedGlyphCount);
        self::assertSame($source->getMetrics('A')->advanceWidth, $selected->getMetrics('A')->advanceWidth);
        self::assertSame($source->getMetrics('A')->leftSideBearing, $selected->getMetrics('A')->leftSideBearing);
        self::assertSame(
            self::describeContour($source->glyphOutline($sourceGlyphId)->contours[0]),
            self::describeContour($selected->glyphOutline($selectedGlyphId)->contours[0]),
        );
        self::assertContains('gvar', $result->font->face()->tables);
        self::assertContains('HVAR', $result->font->face()->tables);
        self::assertContains(
            'Variable glyph and horizontal-metric mappings were compacted; axes and axis metadata were preserved.',
            $result->warnings,
        );
    }

    public function testItRemovesVariationDataForDiscardedGlyphs(): void
    {
        $font = Font::fromFile(self::variableGvarFontPath());
        $sourceGvar = $font->sfntDocument()->table('gvar');
        self::assertNotNull($sourceGvar);

        $result = $font->subset(new SubsetOptions(UnicodeSet::fromText('V')));
        $subsetGvar = $result->font->sfntDocument()->table('gvar');
        self::assertNotNull($subsetGvar);

        self::assertLessThan(\strlen($sourceGvar), \strlen($subsetGvar));
        self::assertSame(610, $result->font->withVariations(['wght' => 900])->getMetrics('V')->advanceWidth);
    }

    public function testItDropsGlyphAndGlobalHintingDataExplicitly(): void
    {
        $path = self::temporaryPath('hinted.ttf');
        TinyTrueTypeFont::writeHinted($path);

        $result = Font::fromFile($path)->subset(new SubsetOptions(UnicodeSet::fromText('Á'), HintingPolicy::Drop));

        foreach (['cvar', 'cvt ', 'fpgm', 'hdmx', 'LTSH', 'prep', 'VDMX'] as $removedTable) {
            self::assertNotContains($removedTable, $result->font->face()->tables);
        }

        self::assertSame('M 150 0 L 350 700 L 550 0 L 150 0 Z', self::describeContour($result->font->glyphOutline(new GlyphId(4))->contours[0]));
    }

    private static function fontPath(): string
    {
        $path = sys_get_temp_dir() . '/alto-font-font-' . bin2hex(random_bytes(4)) . '.ttf';
        TinyTrueTypeFont::write($path);

        return $path;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unmodeledGlyphTables(): iterable
    {
        foreach ([
            'BASE', 'CBDT', 'CBLC', 'COLR', 'EBDT', 'EBLC', 'EBSC', 'Feat', 'Glat',
            'Gloc', 'JSTF', 'MATH', 'SVG ', 'Silf', 'Sill', 'VARC', 'bdat', 'bloc',
            'bsln', 'just', 'morx', 'mort', 'sbix',
        ] as $tag) {
            yield $tag => [$tag];
        }
    }

    private static function temporaryPath(string $suffix): string
    {
        return sys_get_temp_dir() . '/alto-font-font-' . bin2hex(random_bytes(4)) . '-' . $suffix;
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
