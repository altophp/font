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
use Alto\Font\Subset\HintingPolicy;
use Alto\Font\Subset\LayoutPolicy;

/**
 * Compacts TrueType glyph data and dependent tables.
 *
 * @author Simon André <smn.andre@gmail.com>
 *
 * @internal
 */
final readonly class GlyfCompactor
{
    private const int ARG_1_AND_2_ARE_WORDS = 0x0001;
    private const int WE_HAVE_A_SCALE = 0x0008;
    private const int MORE_COMPONENTS = 0x0020;
    private const int WE_HAVE_AN_X_AND_Y_SCALE = 0x0040;
    private const int WE_HAVE_A_TWO_BY_TWO = 0x0080;

    private const array SUPPORTED_TABLES = [
        'DSIG', 'HVAR', 'MERG', 'MVAR', 'OS/2', 'STAT', 'avar', 'cmap', 'cvar', 'cvt ',
        'fpgm', 'fvar', 'gasp', 'glyf', 'gvar', 'head', 'hhea', 'hmtx', 'loca',
        'maxp', 'meta', 'name', 'post', 'prep', 'trak',
    ];

    private const array DROPPABLE_HINTING_TABLES = [
        'cvar', 'cvt ', 'fpgm', 'prep', 'hdmx', 'LTSH', 'VDMX',
    ];

    private const array LAYOUT_TABLES = ['GDEF', 'GPOS', 'GSUB'];

    /**
     * @param list<int>        $glyphOffsets
     * @param array<int, int>  $unicodeMappings
     * @param array<int, true> $retainedGlyphs
     */
    public static function compact(
        SfntDocument $document,
        string $sourceGlyf,
        string $head,
        string $hhea,
        string $hmtx,
        string $maxp,
        array $glyphOffsets,
        int $glyphCount,
        array $unicodeMappings,
        array $retainedGlyphs,
        HintingPolicy $hinting,
        LayoutPolicy $layout,
    ): GlyfSubset {
        self::rejectUnsupportedTables($document, $hinting, $layout);
        $glyphIds = GlyphIdMap::fromRetained($glyphCount, $retainedGlyphs);
        [$glyf, $loca, $newOffsets] = self::buildGlyfAndLoca(
            $sourceGlyf,
            $glyphOffsets,
            $glyphIds,
            $hinting,
        );
        [$hhea, $hmtx] = self::buildHorizontalMetrics($hhea, $hmtx, $glyphIds);

        if (\strlen($head) < 54) {
            throw new InvalidFontException('SFNT head table is truncated.');
        }

        if (\strlen($maxp) < 6) {
            throw new InvalidFontException('SFNT maxp table is truncated.');
        }

        $head = substr_replace($head, self::uint16(1), 50, 2);
        if (HintingPolicy::Drop === $hinting) {
            $maxp = TrueTypeHintingStripper::stripMaxp($maxp);
        }

        $allGlyphs = [];

        for ($glyphId = 0; $glyphId < \count($glyphIds); ++$glyphId) {
            $allGlyphs[$glyphId] = true;
        }

        $metrics = SfntMetricsRecalculator::recalculate(
            $head,
            $hhea,
            $hmtx,
            $glyf,
            $newOffsets,
            $allGlyphs,
        );
        $maxp = TrueTypeMaxpRecalculator::recalculate(
            $maxp,
            $glyf,
            $newOffsets,
            $allGlyphs,
        );
        $remappedUnicode = [];

        foreach ($unicodeMappings as $codepoint => $oldGlyphId) {
            $newGlyphId = $glyphIds->newId($oldGlyphId);

            if (null === $newGlyphId) {
                throw new InvalidFontException(\sprintf('Retained cmap glyph ID %d has no compact mapping.', $oldGlyphId));
            }

            $remappedUnicode[$codepoint] = $newGlyphId;
        }

        $os2 = $document->table('OS/2');

        $replacements = [
            'cmap' => CmapBuilder::build($remappedUnicode),
            'glyf' => $glyf,
            'head' => $metrics['head'],
            'hhea' => $metrics['hhea'],
            'hmtx' => $hmtx,
            'loca' => $loca,
            'maxp' => $maxp,
        ];

        if (null !== $os2) {
            $replacements['OS/2'] = Os2CoverageRecalculator::recalculate($os2, $remappedUnicode);
        }
        $post = $document->table('post');

        if (null !== $post) {
            if (\strlen($post) < 32) {
                throw new InvalidFontException('SFNT post table is truncated.');
            }

            $replacements['post'] = substr_replace(substr($post, 0, 32), "\0\3\0\0", 0, 4);
        }

        $gvar = $document->table('gvar');

        if (null !== $gvar) {
            $replacements['gvar'] = GvarSubsetter::compact($gvar, $glyphCount, $glyphIds);
        }

        $hvar = $document->table('HVAR');

        if (null !== $hvar) {
            $replacements['HVAR'] = HvarCompactor::compact($hvar, $glyphIds);
        }

        $merg = $document->table('MERG');

        if (null !== $merg) {
            $replacements['MERG'] = MergCompactor::compact($merg, $glyphIds);
        }

        $removed = HintingPolicy::Drop === $hinting ? self::DROPPABLE_HINTING_TABLES : [];

        if (null !== $document->table('meta')) {
            $removed[] = 'meta';
        }

        if (LayoutPolicy::Preserve === $layout) {
            $gsub = $document->table('GSUB');
            $gpos = $document->table('GPOS');
            $gdef = $document->table('GDEF');

            if (null !== $gsub) {
                $replacements['GSUB'] = GsubCompactor::compact($gsub, $glyphIds);
            }

            if (null !== $gpos) {
                $replacements['GPOS'] = GposCompactor::compact($gpos, $glyphIds);
            }

            if (null !== $gdef) {
                $replacements['GDEF'] = GdefCompactor::compact($gdef, $glyphIds);
            }
        } elseif (LayoutPolicy::Drop === $layout) {
            $removed = [...$removed, ...self::LAYOUT_TABLES];
        } elseif (LayoutPolicy::SubstitutionsOnly === $layout) {
            $removed[] = 'GPOS';

            $gsub = $document->table('GSUB');
            $gdef = $document->table('GDEF');

            if (null !== $gsub) {
                $replacements['GSUB'] = GsubCompactor::compact($gsub, $glyphIds);
            }

            if (null !== $gdef) {
                $replacements['GDEF'] = GdefCompactor::compact($gdef, $glyphIds);
            }
        }

        $warnings = ['Glyph IDs were compacted and PostScript glyph names were removed when present.'];

        if (null !== $document->table('meta')) {
            $warnings[] = 'The optional meta table was removed because private metadata cannot be remapped safely.';
        }

        if (LayoutPolicy::Preserve === $layout) {
            if (null !== $document->table('GSUB')) {
                $warnings[] = 'GSUB substitutions were compacted with remapped glyph IDs.';
            }

            if (null !== $document->table('GPOS')) {
                $warnings[] = 'GPOS positioning was compacted with remapped glyph IDs.';
            }

            if (null !== $document->table('GDEF')) {
                $warnings[] = 'GDEF glyph definitions were compacted with remapped glyph IDs.';
            }
        } elseif (LayoutPolicy::Drop === $layout && self::containsAnyTable($document, self::LAYOUT_TABLES)) {
            $warnings[] = 'OpenType layout tables GSUB, GPOS, and GDEF were removed explicitly.';
        } elseif (LayoutPolicy::SubstitutionsOnly === $layout) {
            if (null !== $document->table('GSUB')) {
                $warnings[] = 'GSUB substitutions were compacted with remapped glyph IDs.';
            }

            if (null !== $document->table('GPOS')) {
                $warnings[] = 'OpenType positioning table GPOS was removed explicitly.';
            }

            if (null !== $document->table('GDEF')) {
                $warnings[] = 'GDEF glyph definitions were compacted with remapped glyph IDs.';
            }
        }

        if (null !== $document->table('fvar')) {
            $warnings[] = 'Variable glyph and horizontal-metric mappings were compacted; axes and axis metadata were preserved.';
        }

        return new GlyfSubset(
            $document->withTables($replacements, $removed),
            \count($remappedUnicode),
            \count($glyphIds),
            $warnings,
        );
    }

    private static function rejectUnsupportedTables(
        SfntDocument $document,
        HintingPolicy $hinting,
        LayoutPolicy $layout,
    ): void {
        foreach ($document->tableTags() as $tag) {
            if (\in_array($tag, self::SUPPORTED_TABLES, true)) {
                continue;
            }

            if (HintingPolicy::Drop === $hinting && \in_array($tag, self::DROPPABLE_HINTING_TABLES, true)) {
                continue;
            }

            if (LayoutPolicy::Drop === $layout && \in_array($tag, self::LAYOUT_TABLES, true)) {
                continue;
            }

            if (LayoutPolicy::SubstitutionsOnly === $layout && \in_array($tag, self::LAYOUT_TABLES, true)) {
                continue;
            }

            if (LayoutPolicy::Preserve === $layout && \in_array($tag, self::LAYOUT_TABLES, true)) {
                continue;
            }

            throw new UnsupportedFontException(\sprintf(
                'Compacting glyph IDs requires rewriting SFNT table "%s", which is not supported yet.',
                $tag,
            ));
        }
    }

    /**
     * @param list<string> $tags
     */
    private static function containsAnyTable(SfntDocument $document, array $tags): bool
    {
        foreach ($tags as $tag) {
            if (null !== $document->table($tag)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<int> $glyphOffsets
     *
     * @return array{string, string, list<int>}
     */
    private static function buildGlyfAndLoca(
        string $source,
        array $glyphOffsets,
        GlyphIdMap $glyphIds,
        HintingPolicy $hinting,
    ): array {
        $glyf = '';
        $loca = '';
        $newOffsets = [];

        foreach ($glyphIds->pairs() as $oldGlyphId => $newGlyphId) {
            $newOffsets[] = \strlen($glyf);
            $loca .= self::uint32($newOffsets[$newGlyphId]);
            $start = $glyphOffsets[$oldGlyphId] ?? null;
            $end = $glyphOffsets[$oldGlyphId + 1] ?? null;

            if (null === $start || null === $end || $start < 0 || $end < $start || $end > \strlen($source)) {
                throw new InvalidFontException(\sprintf('Glyph ID %d has invalid glyf offsets.', $oldGlyphId));
            }

            $glyph = substr($source, $start, $end - $start);
            $glyph = self::remapCompoundComponents($glyph, $oldGlyphId, $glyphIds);

            if (HintingPolicy::Drop === $hinting) {
                $glyph = TrueTypeHintingStripper::stripGlyph($glyph);
            }

            $glyf .= $glyph . (0 === \strlen($glyph) % 2 ? '' : "\0");
        }

        $newOffsets[] = \strlen($glyf);
        $loca .= self::uint32(\strlen($glyf));

        return [$glyf, $loca, $newOffsets];
    }

    private static function remapCompoundComponents(string $glyph, int $oldGlyphId, GlyphIdMap $glyphIds): string
    {
        if ('' === $glyph) {
            return '';
        }

        $reader = new BinaryReader($glyph, \sprintf('glyf glyph %d compaction', $oldGlyphId));

        if ($reader->int16(0) >= 0) {
            return $glyph;
        }

        $cursor = 10;

        do {
            $flags = $reader->uint16($cursor);
            $componentGlyphId = $reader->uint16($cursor + 2);
            $newComponentGlyphId = $glyphIds->newId($componentGlyphId);

            if (null === $newComponentGlyphId) {
                throw new InvalidFontException(\sprintf(
                    'Compound glyph %d references discarded glyph ID %d.',
                    $oldGlyphId,
                    $componentGlyphId,
                ));
            }

            $glyph = substr_replace($glyph, self::uint16($newComponentGlyphId), $cursor + 2, 2);
            $cursor += 4;
            $cursor += 0 !== ($flags & self::ARG_1_AND_2_ARE_WORDS) ? 4 : 2;

            if (0 !== ($flags & self::WE_HAVE_A_SCALE)) {
                $cursor += 2;
            } elseif (0 !== ($flags & self::WE_HAVE_AN_X_AND_Y_SCALE)) {
                $cursor += 4;
            } elseif (0 !== ($flags & self::WE_HAVE_A_TWO_BY_TWO)) {
                $cursor += 8;
            }
        } while (0 !== ($flags & self::MORE_COMPONENTS));

        return $glyph;
    }

    /**
     * @return array{string, string}
     */
    private static function buildHorizontalMetrics(string $hhea, string $sourceHmtx, GlyphIdMap $glyphIds): array
    {
        if (\strlen($hhea) < 36) {
            throw new InvalidFontException('SFNT hhea table is truncated.');
        }

        $hheaReader = new BinaryReader($hhea, 'hhea glyph compaction');
        $sourceMetricCount = $hheaReader->uint16(34);

        if (0 === $sourceMetricCount) {
            throw new InvalidFontException('SFNT hhea numberOfHMetrics is invalid.');
        }

        $sourceReader = new BinaryReader($sourceHmtx, 'hmtx glyph compaction');
        $lastAdvanceWidth = $sourceReader->uint16(($sourceMetricCount - 1) * 4);
        $hmtx = '';

        foreach ($glyphIds->pairs() as $oldGlyphId => $_newGlyphId) {
            if ($oldGlyphId < $sourceMetricCount) {
                $advanceWidth = $sourceReader->uint16($oldGlyphId * 4);
                $leftSideBearing = $sourceReader->int16($oldGlyphId * 4 + 2);
            } else {
                $advanceWidth = $lastAdvanceWidth;
                $leftSideBearing = $sourceReader->int16(
                    $sourceMetricCount * 4 + ($oldGlyphId - $sourceMetricCount) * 2,
                );
            }

            $hmtx .= self::uint16($advanceWidth) . self::int16($leftSideBearing);
        }

        return [substr_replace($hhea, self::uint16(\count($glyphIds)), 34, 2), $hmtx];
    }

    private static function uint16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }

    private static function uint32(int $value): string
    {
        return pack('N', $value & 0xFFFFFFFF);
    }

    private static function int16(int $value): string
    {
        if ($value < -32768 || $value > 32767) {
            throw new InvalidFontException(\sprintf('SFNT metric value %d exceeds int16 bounds.', $value));
        }

        return pack('n', $value & 0xFFFF);
    }
}
