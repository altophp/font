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
use Alto\Font\OpenType\Table\CmapTable;
use Alto\Font\OpenType\Table\GsubTable;
use Alto\Font\Subset\GlyphIdPolicy;
use Alto\Font\Subset\HintingPolicy;
use Alto\Font\Subset\LayoutPolicy;
use Alto\Font\Subset\SubsetOptions;

/**
 * Builds conservative TrueType Unicode subsets.
 *
 * @author Simon André <smn.andre@gmail.com>
 *
 * @internal
 */
final readonly class GlyfSubsetter
{
    private const int ARG_1_AND_2_ARE_WORDS = 0x0001;
    private const int WE_HAVE_A_SCALE = 0x0008;
    private const int MORE_COMPONENTS = 0x0020;
    private const int WE_HAVE_AN_X_AND_Y_SCALE = 0x0040;
    private const int WE_HAVE_A_TWO_BY_TWO = 0x0080;

    private const array UNMODELED_GLYPH_TABLES = [
        'BASE', 'CBDT', 'CBLC', 'COLR', 'EBDT', 'EBLC', 'EBSC', 'Feat', 'Glat',
        'Gloc', 'JSTF', 'MATH', 'SVG ', 'Silf', 'Sill', 'VARC', 'bdat', 'bloc',
        'bsln', 'just', 'morx', 'mort', 'sbix',
    ];

    private const array HINTING_TABLES = [
        'cvar', 'cvt ', 'fpgm', 'prep', 'hdmx', 'LTSH', 'VDMX',
    ];

    /**
     * @param list<int> $glyphOffsets
     */
    public static function subset(
        SfntDocument $document,
        CmapTable $cmap,
        array $glyphOffsets,
        int $glyphCount,
        SubsetOptions $options,
    ): GlyfSubset {
        self::rejectUnsupportedTables($document);
        $glyf = $document->table('glyf');
        $head = $document->table('head');
        $hhea = $document->table('hhea');
        $hmtx = $document->table('hmtx');
        $maxp = $document->table('maxp');

        if (null === $glyf || null === $head || null === $hhea || null === $hmtx || null === $maxp) {
            throw new InvalidFontException('TrueType subsetting requires glyf, head, hhea, hmtx, and maxp tables.');
        }

        $mappings = [];
        $requestedGlyphs = [0 => true];

        foreach ($cmap->mappings() as $codepoint => $glyphId) {
            if (!$options->unicodes->contains($codepoint) || 0 === $glyphId) {
                continue;
            }

            if ($glyphId < 0 || $glyphId >= $glyphCount) {
                throw new InvalidFontException(\sprintf('cmap maps U+%04X to invalid glyph ID %d.', $codepoint, $glyphId));
            }

            $mappings[$codepoint] = $glyphId;
            $requestedGlyphs[$glyphId] = true;
        }

        $gsub = $document->table('GSUB');

        if (LayoutPolicy::Drop !== $options->layout && null !== $gsub) {
            $requestedGlyphs = GsubTable::parse(new BinaryReader($gsub, 'GSUB'))->glyphClosure($requestedGlyphs, $glyphCount);
        }

        $retained = [];

        foreach (array_keys($requestedGlyphs) as $glyphId) {
            $visiting = [];
            self::retainGlyphAndComponents($glyf, $glyphOffsets, $glyphId, $retained, $visiting);
        }

        if (GlyphIdPolicy::Compact === $options->glyphIds) {
            return GlyfCompactor::compact(
                $document,
                $glyf,
                $head,
                $hhea,
                $hmtx,
                $maxp,
                $glyphOffsets,
                $glyphCount,
                $mappings,
                $retained,
                $options->hinting,
                $options->layout,
            );
        }

        [$subsetGlyf, $subsetLoca, $subsetGlyphOffsets] = self::buildGlyfAndLoca(
            $glyf,
            $glyphOffsets,
            $glyphCount,
            $retained,
            $options->hinting,
        );

        if (\strlen($head) < 54) {
            throw new InvalidFontException('SFNT head table is truncated.');
        }

        $head = substr_replace($head, self::uint16(1), 50, 2);
        $metrics = SfntMetricsRecalculator::recalculate(
            $head,
            $hhea,
            $hmtx,
            $subsetGlyf,
            $subsetGlyphOffsets,
            $retained,
        );

        if (HintingPolicy::Drop === $options->hinting) {
            $maxp = TrueTypeHintingStripper::stripMaxp($maxp);
        }

        $maxp = TrueTypeMaxpRecalculator::recalculate(
            $maxp,
            $subsetGlyf,
            $subsetGlyphOffsets,
            $retained,
        );
        $replacements = [
            'cmap' => CmapBuilder::build($mappings),
            'glyf' => $subsetGlyf,
            'head' => $metrics['head'],
            'hhea' => $metrics['hhea'],
            'loca' => $subsetLoca,
            'maxp' => $maxp,
        ];

        $os2 = $document->table('OS/2');

        if (null !== $os2) {
            $replacements['OS/2'] = Os2CoverageRecalculator::recalculate($os2, $mappings);
        }
        $removedTables = match ($options->layout) {
            LayoutPolicy::Preserve => [],
            LayoutPolicy::SubstitutionsOnly => ['GPOS'],
            LayoutPolicy::Drop => ['GSUB', 'GPOS', 'GDEF'],
        };

        $gvar = $document->table('gvar');

        if (null !== $gvar) {
            $replacements['gvar'] = GvarSubsetter::subset($gvar, $glyphCount, $retained);
        }

        if (HintingPolicy::Drop === $options->hinting) {
            $removedTables = [...$removedTables, ...self::HINTING_TABLES];
        }

        $warnings = [];

        if (LayoutPolicy::Drop === $options->layout && (null !== $gsub || null !== $document->table('GPOS') || null !== $document->table('GDEF'))) {
            $warnings[] = 'OpenType layout tables GSUB, GPOS, and GDEF were removed explicitly.';
        } elseif (LayoutPolicy::SubstitutionsOnly === $options->layout && null !== $document->table('GPOS')) {
            $warnings[] = 'OpenType positioning table GPOS was removed explicitly.';
        } elseif (null !== $document->table('GPOS') || null !== $document->table('GDEF')) {
            $warnings[] = 'GPOS/GDEF tables are preserved with stable glyph IDs and are not compacted.';
        }

        if (LayoutPolicy::Drop !== $options->layout && null !== $gsub) {
            $warnings[] = 'GSUB is preserved with stable glyph IDs after conservative glyph closure and is not compacted.';
        }

        if (null !== $document->table('fvar')) {
            $warnings[] = null === $gvar
                ? 'Variable tables are preserved with stable glyph IDs and are not compacted.'
                : 'gvar glyph data is subset; axes and other variable tables are preserved with stable glyph IDs.';
        }

        return new GlyfSubset(
            $document->withTables($replacements, $removedTables),
            \count($mappings),
            \count($retained),
            $warnings,
        );
    }

    private static function rejectUnsupportedTables(SfntDocument $document): void
    {
        foreach (self::UNMODELED_GLYPH_TABLES as $tag) {
            if (null !== $document->table($tag)) {
                throw new UnsupportedFontException(\sprintf('Subsetting table "%s" requires glyph closure and is not supported yet.', $tag));
            }
        }
    }

    /**
     * @param list<int>        $glyphOffsets
     * @param array<int, true> $retained
     * @param array<int, true> $visiting
     */
    private static function retainGlyphAndComponents(
        string $glyf,
        array $glyphOffsets,
        int $glyphId,
        array &$retained,
        array &$visiting,
    ): void {
        if (isset($visiting[$glyphId])) {
            throw new InvalidFontException(\sprintf('Compound glyph cycle detected at glyph ID %d.', $glyphId));
        }

        if (isset($retained[$glyphId])) {
            return;
        }

        $retained[$glyphId] = true;
        $visiting[$glyphId] = true;
        $start = $glyphOffsets[$glyphId] ?? null;
        $end = $glyphOffsets[$glyphId + 1] ?? null;

        if (null === $start || null === $end || $start < 0 || $end < $start || $end > \strlen($glyf)) {
            throw new InvalidFontException(\sprintf('Glyph ID %d has invalid glyf offsets.', $glyphId));
        }

        if ($start === $end) {
            unset($visiting[$glyphId]);

            return;
        }

        $glyph = new BinaryReader(substr($glyf, $start, $end - $start), \sprintf('glyf glyph %d', $glyphId));

        if ($glyph->int16(0) >= 0) {
            unset($visiting[$glyphId]);

            return;
        }

        $cursor = 10;

        do {
            $flags = $glyph->uint16($cursor);
            $componentGlyphId = $glyph->uint16($cursor + 2);
            $cursor += 4;
            $cursor += 0 !== ($flags & self::ARG_1_AND_2_ARE_WORDS) ? 4 : 2;

            if (0 !== ($flags & self::WE_HAVE_A_SCALE)) {
                $cursor += 2;
            } elseif (0 !== ($flags & self::WE_HAVE_AN_X_AND_Y_SCALE)) {
                $cursor += 4;
            } elseif (0 !== ($flags & self::WE_HAVE_A_TWO_BY_TWO)) {
                $cursor += 8;
            }

            if (!isset($glyphOffsets[$componentGlyphId + 1])) {
                throw new InvalidFontException(\sprintf('Compound glyph %d references invalid glyph ID %d.', $glyphId, $componentGlyphId));
            }

            self::retainGlyphAndComponents($glyf, $glyphOffsets, $componentGlyphId, $retained, $visiting);
        } while (0 !== ($flags & self::MORE_COMPONENTS));

        unset($visiting[$glyphId]);
    }

    /**
     * @param list<int>        $glyphOffsets
     * @param array<int, true> $retained
     *
     * @return array{string, string, list<int>}
     */
    private static function buildGlyfAndLoca(
        string $source,
        array $glyphOffsets,
        int $glyphCount,
        array $retained,
        HintingPolicy $hinting,
    ): array {
        $glyf = '';
        $loca = '';
        $newOffsets = [];

        for ($glyphId = 0; $glyphId < $glyphCount; ++$glyphId) {
            $newOffsets[] = \strlen($glyf);
            $loca .= self::uint32($newOffsets[$glyphId]);

            if (!isset($retained[$glyphId])) {
                continue;
            }

            $start = $glyphOffsets[$glyphId];
            $end = $glyphOffsets[$glyphId + 1];
            $glyph = substr($source, $start, $end - $start);

            if (HintingPolicy::Drop === $hinting) {
                $glyph = TrueTypeHintingStripper::stripGlyph($glyph);
            }

            $glyf .= $glyph . (0 === \strlen($glyph) % 2 ? '' : "\0");
        }

        $newOffsets[] = \strlen($glyf);
        $loca .= self::uint32(\strlen($glyf));

        return [$glyf, $loca, $newOffsets];
    }

    private static function uint16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }

    private static function uint32(int $value): string
    {
        return pack('N', $value & 0xFFFFFFFF);
    }
}
