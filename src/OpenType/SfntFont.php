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
use Alto\Font\FontFace;
use Alto\Font\Glyph\Contour;
use Alto\Font\Glyph\GlyphId;
use Alto\Font\Glyph\GlyphMetrics;
use Alto\Font\Glyph\GlyphOutline;
use Alto\Font\Glyph\GlyphPoint;
use Alto\Font\Glyph\PathCommand;
use Alto\Font\Metadata\FontFormat;
use Alto\Font\OpenType\Table\CmapTable;
use Alto\Font\OpenType\Table\NameTable;
use Alto\Font\OpenType\Table\TableRecord;
use Alto\Font\Subset\SubsetOptions;
use Alto\Font\Variation\FontVariations;
use Alto\Font\Variation\NormalizedCoordinates;
use Alto\Font\Variation\Table\AvarTable;
use Alto\Font\Variation\Table\FvarTable;
use Alto\Font\Variation\Table\GvarTable;
use Alto\Font\Variation\Table\HvarTable;
use Alto\Font\Variation\VariationCoordinates;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final class SfntFont
{
    private const int ARG_1_AND_2_ARE_WORDS = 0x0001;
    private const int ARGS_ARE_XY_VALUES = 0x0002;
    private const int WE_HAVE_A_SCALE = 0x0008;
    private const int MORE_COMPONENTS = 0x0020;
    private const int WE_HAVE_AN_X_AND_Y_SCALE = 0x0040;
    private const int WE_HAVE_A_TWO_BY_TWO = 0x0080;
    private const int WE_HAVE_INSTRUCTIONS = 0x0100;
    private const int USE_MY_METRICS = 0x0200;

    /**
     * Hard ceiling on the total decompressed table size for WOFF/WOFF2
     * containers, checked before decompression runs. Real fonts never get
     * remotely close to this; a small file claiming to need more is a
     * decompression-bomb attempt, not a legitimate font.
     */
    private const int MAX_DECOMPRESSED_TABLE_BLOCK_SIZE = 100 * 1024 * 1024;

    /**
     * @param array<string, TableRecord> $tables
     * @param list<int>                  $glyphOffsets
     * @param array<int, GlyphMetrics>   $metrics
     * @param array<int, string>         $names
     */
    private function __construct(
        private readonly BinaryReader $reader,
        private readonly string $path,
        private readonly FontFormat $format,
        private readonly SfntDocument $document,
        private readonly array $tables,
        private readonly CmapTable $cmap,
        private readonly int $unitsPerEm,
        private readonly int $ascender,
        private readonly int $descender,
        private readonly int $glyphCount,
        private readonly array $glyphOffsets,
        private readonly array $metrics,
        private readonly array $names,
        private readonly ?FontVariations $variations,
        private readonly ?AvarTable $avar,
        private readonly ?GvarTable $gvar,
        private readonly ?HvarTable $hvar,
        private readonly int $faceIndex = 0,
        private readonly int $faceCount = 1,
    ) {}

    public static function open(string $path, int $faceIndex = 0): self
    {
        if (!is_file($path)) {
            throw new InvalidFontException(\sprintf('Font file "%s" does not exist.', $path));
        }

        $data = file_get_contents($path);

        if (!\is_string($data) || '' === $data) {
            throw new InvalidFontException(\sprintf('Font file "%s" could not be read or is empty.', $path));
        }

        return self::parse($data, $path, $faceIndex);
    }

    public static function parse(string $data, string $path = '<memory>', int $faceIndex = 0): self
    {
        $reader = new BinaryReader($data, $path);
        $scalerType = $reader->string(0, 4);

        if ('wOFF' === $scalerType) {
            return self::parseSfntDirectory(
                new BinaryReader(self::sfntFromWoff($reader), $path),
                $path,
                0,
                $faceIndex,
                1,
                format: FontFormat::Woff,
            );
        }

        if ('wOF2' === $scalerType) {
            return self::parseSfntDirectory(
                new BinaryReader(Woff2Decoder::decode($reader), $path),
                $path,
                0,
                $faceIndex,
                1,
                format: FontFormat::Woff2,
            );
        }

        if ('ttcf' === $scalerType) {
            return self::parseCollection($reader, $path, $faceIndex);
        }

        return self::parseSfntDirectory(
            $reader,
            $path,
            0,
            $faceIndex,
            1,
            format: FontFormat::fromSignature($scalerType),
        );
    }

    private static function parseCollection(BinaryReader $reader, string $path, int $faceIndex): self
    {
        $numFonts = $reader->uint32(8);

        if ($numFonts <= 0) {
            throw new InvalidFontException(\sprintf('Font collection "%s" declares no faces.', $path));
        }

        if ($faceIndex < 0 || $faceIndex >= $numFonts) {
            throw new InvalidFontException(\sprintf('Font collection "%s" has %d face(s); requested index %d is out of range.', $path, $numFonts, $faceIndex));
        }

        $directoryOffset = $reader->uint32(12 + $faceIndex * 4);

        return self::parseSfntDirectory(
            $reader,
            $path,
            $directoryOffset,
            $faceIndex,
            $numFonts,
            false,
            FontFormat::TrueTypeCollection,
        );
    }

    private static function parseSfntDirectory(
        BinaryReader $reader,
        string $path,
        int $directoryOffset,
        int $faceIndex,
        int $faceCount,
        bool $standalone = true,
        FontFormat $format = FontFormat::Unknown,
    ): self {
        $scalerType = $reader->string($directoryOffset, 4);

        if ("\x00\x01\x00\x00" !== $scalerType && 'true' !== $scalerType) {
            if ('OTTO' === $scalerType) {
                throw new UnsupportedFontException('CFF/OpenType outlines are not supported.');
            }

            if ('ttcf' === $scalerType) {
                throw new UnsupportedFontException('Nested font collections are not supported.');
            }

            throw new InvalidFontException('Unsupported sfnt scaler type.');
        }

        $tables = self::parseTableRecords($reader, $directoryOffset);
        self::rejectUnsupportedGlyphTables($tables);

        foreach (['cmap', 'head', 'hhea', 'hmtx', 'loca', 'maxp', 'glyf'] as $required) {
            if (!isset($tables[$required])) {
                throw new InvalidFontException(\sprintf('Required table "%s" is missing.', $required));
            }
        }

        $head = $reader->table($tables['head']);
        $hhea = $reader->table($tables['hhea']);
        $maxp = $reader->table($tables['maxp']);
        $cmap = CmapTable::parse($reader->table($tables['cmap']));
        $unitsPerEm = $head->uint16(18);
        $indexToLocFormat = $head->int16(50);
        $ascender = $hhea->int16(4);
        $descender = $hhea->int16(6);
        $numberOfHMetrics = $hhea->uint16(34);
        $glyphCount = $maxp->uint16(4);

        if (!\in_array($indexToLocFormat, [0, 1], true)) {
            throw new InvalidFontException(\sprintf('Unsupported loca format %d.', $indexToLocFormat));
        }

        $names = isset($tables['name']) ? NameTable::parse($reader->table($tables['name'])) : [];
        $variations = isset($tables['fvar']) ? FvarTable::parse($reader->table($tables['fvar']), $names) : null;
        $avar = null === $variations || !isset($tables['avar']) ? null : AvarTable::parse($reader->table($tables['avar']), $variations);
        $gvar = null === $variations || !isset($tables['gvar']) ? null : GvarTable::parse($reader->table($tables['gvar']), $variations, $glyphCount);
        $hvar = null === $variations || !isset($tables['HVAR']) ? null : HvarTable::parse($reader->table($tables['HVAR']), $variations);
        $glyphOffsets = self::parseLoca($reader->table($tables['loca']), $glyphCount, $indexToLocFormat);
        $metrics = self::parseMetrics($reader->table($tables['hmtx']), $glyphCount, $numberOfHMetrics);

        return new self(
            $reader,
            $path,
            $format,
            new SfntDocument($reader, $scalerType, $tables, $path, $faceIndex, $faceCount, $standalone),
            $tables,
            $cmap,
            $unitsPerEm,
            $ascender,
            $descender,
            $glyphCount,
            $glyphOffsets,
            $metrics,
            $names,
            $variations,
            $avar,
            $gvar,
            $hvar,
            $faceIndex,
            $faceCount,
        );
    }

    private static function sfntFromWoff(BinaryReader $woff): string
    {
        $flavor = $woff->string(4, 4);
        $declaredLength = $woff->uint32(8);
        $numTables = $woff->uint16(12);

        if ($declaredLength !== $woff->length()) {
            throw new InvalidFontException('WOFF declared length does not match file length.');
        }

        if (0 === $numTables) {
            throw new InvalidFontException('WOFF declares no font tables.');
        }

        $declaredSfntSize = $woff->uint32(16);
        $directoryEnd = 44 + $numTables * 20;
        $entries = [];
        $ranges = [];
        $previousTag = null;
        $totalOriginalLength = 0;

        for ($i = 0; $i < $numTables; ++$i) {
            $entryOffset = 44 + $i * 20;
            $tag = $woff->string($entryOffset, 4);
            $offset = $woff->uint32($entryOffset + 4);
            $compressedLength = $woff->uint32($entryOffset + 8);
            $originalLength = $woff->uint32($entryOffset + 12);
            $declaredChecksum = $woff->uint32($entryOffset + 16);

            if (null !== $previousTag && strcmp($previousTag, $tag) >= 0) {
                throw new InvalidFontException('WOFF table directory must contain unique tags in ascending order.');
            }

            if ($compressedLength > $originalLength) {
                throw new InvalidFontException(\sprintf('WOFF table "%s" has a compressed length greater than its original length.', $tag));
            }

            if (0 !== $offset % 4 || $offset < $directoryEnd || $offset + $compressedLength > $declaredLength) {
                throw new InvalidFontException(\sprintf('WOFF table "%s" has an invalid data range.', $tag));
            }

            $previousTag = $tag;
            $totalOriginalLength += $originalLength;

            if ($totalOriginalLength > self::MAX_DECOMPRESSED_TABLE_BLOCK_SIZE) {
                throw new InvalidFontException('WOFF declares an implausible total decompressed table size.');
            }

            $entries[] = [$tag, $offset, $compressedLength, $originalLength, $declaredChecksum];
            $ranges[] = [$offset, ($offset + $compressedLength + 3) & ~3];
        }

        usort($ranges, static fn(array $left, array $right): int => $left[0] <=> $right[0]);
        $previousEnd = $directoryEnd;

        foreach ($ranges as [$start, $end]) {
            if ($start < $previousEnd) {
                throw new InvalidFontException('WOFF table data ranges overlap.');
            }

            $previousEnd = $end;
        }

        $tables = [];

        foreach ($entries as [$tag, $offset, $compressedLength, $originalLength, $declaredChecksum]) {
            $payload = $woff->string($offset, $compressedLength);

            if ($compressedLength === $originalLength) {
                $table = $payload;
            } else {
                if ($originalLength > self::MAX_DECOMPRESSED_TABLE_BLOCK_SIZE) {
                    throw new InvalidFontException(\sprintf('WOFF table "%s" declares an implausible decompressed size.', $tag));
                }

                $table = @gzuncompress($payload, $originalLength);

                if (!\is_string($table)) {
                    throw new InvalidFontException(\sprintf('WOFF table "%s" could not be decompressed.', $tag));
                }
            }

            if (\strlen($table) !== $originalLength) {
                throw new InvalidFontException(\sprintf('WOFF table "%s" decompressed to an unexpected length.', $tag));
            }

            $checksumData = $table;

            if ('head' === $tag) {
                if (\strlen($checksumData) < 12) {
                    throw new InvalidFontException('SFNT head table is truncated.');
                }

                $checksumData = substr_replace($checksumData, "\0\0\0\0", 8, 4);
            }

            if (SfntChecksum::calculate($checksumData) !== $declaredChecksum) {
                throw new InvalidFontException(\sprintf('WOFF table "%s" checksum does not match its declared checksum.', $tag));
            }

            $tables[$tag] = $table;
        }

        $sfnt = SfntBuilder::build($flavor, $tables);

        if (\strlen($sfnt) !== $declaredSfntSize) {
            throw new InvalidFontException('WOFF totalSfntSize does not match the reconstructed font size.');
        }

        return $sfnt;
    }

    public function face(): FontFace
    {
        $tables = array_keys($this->tables);
        sort($tables);

        return new FontFace(
            path: $this->path,
            unitsPerEm: $this->unitsPerEm,
            ascender: $this->ascender,
            descender: $this->descender,
            glyphCount: $this->glyphCount,
            tables: $tables,
            names: $this->names,
            faceIndex: $this->faceIndex,
            faceCount: $this->faceCount,
            format: $this->format,
        );
    }

    public function toSfnt(): string
    {
        return $this->document->toSfnt();
    }

    /**
     * @internal
     */
    public function document(): SfntDocument
    {
        return $this->document;
    }

    /**
     * @internal
     */
    public function subset(SubsetOptions $options): GlyfSubset
    {
        return GlyfSubsetter::subset(
            $this->document,
            $this->cmap,
            $this->glyphOffsets,
            $this->glyphCount,
            $options,
        );
    }

    public function glyphIdForCodepoint(int $codepoint): ?GlyphId
    {
        $glyphId = $this->cmap->glyphIdForCodepoint($codepoint);

        return null === $glyphId ? null : new GlyphId($glyphId);
    }

    public function variations(): ?FontVariations
    {
        return $this->variations;
    }

    public function glyphMetrics(GlyphId $glyphId, ?VariationCoordinates $coordinates = null): GlyphMetrics
    {
        $this->assertGlyphId($glyphId);

        $metrics = $this->metrics[$glyphId->value];
        $normalizedCoordinates = $this->normalizedCoordinates($coordinates);

        if (null === $normalizedCoordinates) {
            return $metrics;
        }

        if (null !== $this->hvar) {
            $advanceWidthDelta = (int) round($this->hvar->advanceWidthDelta($glyphId->value, $normalizedCoordinates));
            $leftSideBearingDelta = (int) round($this->hvar->leftSideBearingDelta($glyphId->value, $normalizedCoordinates));

            if (0 === $advanceWidthDelta && 0 === $leftSideBearingDelta) {
                return $metrics;
            }

            return new GlyphMetrics(
                $glyphId,
                $metrics->advanceWidth + $advanceWidthDelta,
                $metrics->leftSideBearing + $leftSideBearingDelta,
            );
        }

        if (null === $this->gvar) {
            return $metrics;
        }

        $metricsGlyphId = $this->componentMetricsGlyphId($glyphId);

        if (null !== $metricsGlyphId) {
            if ($metricsGlyphId->value === $glyphId->value) {
                throw new InvalidFontException(\sprintf('Compound glyph metrics cycle detected at glyph ID %d.', $glyphId->value));
            }

            return $this->glyphMetrics($metricsGlyphId, $coordinates);
        }

        $pointCount = $this->glyphPointCount($glyphId);
        $deltas = $this->gvar->deltasForGlyph($glyphId->value, $pointCount + 4, $normalizedCoordinates);
        $leftSideBearingDelta = (int) round($deltas->x[$pointCount] ?? 0.0);
        $advanceWidthDelta = (int) round(($deltas->x[$pointCount + 1] ?? 0.0) - $leftSideBearingDelta);

        if (0 === $leftSideBearingDelta && 0 === $advanceWidthDelta) {
            return $metrics;
        }

        return new GlyphMetrics(
            $glyphId,
            $metrics->advanceWidth + $advanceWidthDelta,
            $metrics->leftSideBearing + $leftSideBearingDelta,
        );
    }

    public function glyphOutline(GlyphId $glyphId, ?VariationCoordinates $coordinates = null): GlyphOutline
    {
        return $this->readGlyph($glyphId, [], $this->normalizedCoordinates($coordinates));
    }

    /**
     * @param array<int, true> $visited
     */
    private function readGlyph(GlyphId $glyphId, array $visited, ?NormalizedCoordinates $coordinates): GlyphOutline
    {
        $this->assertGlyphId($glyphId);

        if (isset($visited[$glyphId->value])) {
            throw new InvalidFontException(\sprintf('Compound glyph cycle detected at glyph ID %d.', $glyphId->value));
        }

        $visited[$glyphId->value] = true;
        $start = $this->glyphOffsets[$glyphId->value];
        $end = $this->glyphOffsets[$glyphId->value + 1];

        if ($start === $end) {
            return new GlyphOutline($glyphId, []);
        }

        $glyf = $this->reader->table($this->tables['glyf']);
        $numberOfContours = $glyf->int16($start);

        if ($numberOfContours >= 0) {
            return $this->readSimpleGlyph($glyf, $glyphId, $start, $numberOfContours, $coordinates);
        }

        return $this->readCompoundGlyph($glyf, $glyphId, $start, $visited, $coordinates);
    }

    private function readSimpleGlyph(
        BinaryReader $glyf,
        GlyphId $glyphId,
        int $offset,
        int $numberOfContours,
        ?NormalizedCoordinates $coordinates,
    ): GlyphOutline {
        if (0 === $numberOfContours) {
            return new GlyphOutline($glyphId, []);
        }

        $endPoints = [];
        $endPointsOffset = $offset + 10;

        for ($i = 0; $i < $numberOfContours; ++$i) {
            $endPoints[] = $glyf->uint16($endPointsOffset + $i * 2);
        }

        $pointCount = $endPoints[\count($endPoints) - 1] + 1;
        $instructionLengthOffset = $endPointsOffset + $numberOfContours * 2;
        $instructionLength = $glyf->uint16($instructionLengthOffset);
        $flagsOffset = $instructionLengthOffset + 2 + $instructionLength;
        $flags = [];
        $cursor = $flagsOffset;

        while (\count($flags) < $pointCount) {
            $flag = $glyf->uint8($cursor++);
            $flags[] = $flag;

            if (0 !== ($flag & 0x08)) {
                $repeatCount = $glyf->uint8($cursor++);

                for ($i = 0; $i < $repeatCount; ++$i) {
                    $flags[] = $flag;
                }
            }
        }

        if (\count($flags) !== $pointCount) {
            throw new InvalidFontException(\sprintf('Glyph %d has invalid repeated flags.', $glyphId->value));
        }

        $xCoordinates = [];
        $x = 0;

        foreach ($flags as $flag) {
            if (0 !== ($flag & 0x02)) {
                $delta = $glyf->uint8($cursor++);
                $x += 0 !== ($flag & 0x10) ? $delta : -$delta;
            } elseif (0 === ($flag & 0x10)) {
                $x += $glyf->int16($cursor);
                $cursor += 2;
            }

            $xCoordinates[] = $x;
        }

        $yCoordinates = [];
        $y = 0;

        foreach ($flags as $flag) {
            if (0 !== ($flag & 0x04)) {
                $delta = $glyf->uint8($cursor++);
                $y += 0 !== ($flag & 0x20) ? $delta : -$delta;
            } elseif (0 === ($flag & 0x20)) {
                $y += $glyf->int16($cursor);
                $cursor += 2;
            }

            $yCoordinates[] = $y;
        }

        if (null !== $coordinates && null !== $this->gvar) {
            [$xDeltas, $yDeltas] = $this->simpleGlyphVariationDeltas(
                $glyphId,
                $pointCount,
                $coordinates,
                $endPoints,
                $xCoordinates,
                $yCoordinates,
            );

            for ($i = 0; $i < $pointCount; ++$i) {
                $xCoordinates[$i] += $xDeltas[$i];
                $yCoordinates[$i] += $yDeltas[$i];
            }
        }

        $contours = [];
        $startPoint = 0;

        foreach ($endPoints as $endPoint) {
            $points = [];

            for ($i = $startPoint; $i <= $endPoint; ++$i) {
                $points[] = new GlyphPoint(
                    (float) $xCoordinates[$i],
                    (float) $yCoordinates[$i],
                    0 !== ($flags[$i] & 0x01),
                );
            }

            $contours[] = new Contour(self::commandsForContour($points));
            $startPoint = $endPoint + 1;
        }

        return new GlyphOutline($glyphId, $contours);
    }

    /**
     * @param array<int, true> $visited
     */
    private function readCompoundGlyph(
        BinaryReader $glyf,
        GlyphId $glyphId,
        int $offset,
        array $visited,
        ?NormalizedCoordinates $coordinates,
    ): GlyphOutline {
        $cursor = $offset + 10;
        $components = [];

        do {
            $flags = $glyf->uint16($cursor);
            $componentGlyphId = new GlyphId($glyf->uint16($cursor + 2));
            $cursor += 4;

            if (0 === ($flags & self::ARGS_ARE_XY_VALUES)) {
                throw new UnsupportedFontException('Point-matched compound glyphs are not supported.');
            }

            if (0 !== ($flags & self::ARG_1_AND_2_ARE_WORDS)) {
                $dx = $glyf->int16($cursor);
                $dy = $glyf->int16($cursor + 2);
                $cursor += 4;
            } else {
                $dx = $glyf->int8($cursor);
                $dy = $glyf->int8($cursor + 1);
                $cursor += 2;
            }

            $xx = 1.0;
            $yx = 0.0;
            $xy = 0.0;
            $yy = 1.0;

            if (0 !== ($flags & self::WE_HAVE_A_SCALE)) {
                $xx = $yy = $glyf->fixed2Dot14($cursor);
                $cursor += 2;
            } elseif (0 !== ($flags & self::WE_HAVE_AN_X_AND_Y_SCALE)) {
                $xx = $glyf->fixed2Dot14($cursor);
                $yy = $glyf->fixed2Dot14($cursor + 2);
                $cursor += 4;
            } elseif (0 !== ($flags & self::WE_HAVE_A_TWO_BY_TWO)) {
                $xx = $glyf->fixed2Dot14($cursor);
                $yx = $glyf->fixed2Dot14($cursor + 2);
                $xy = $glyf->fixed2Dot14($cursor + 4);
                $yy = $glyf->fixed2Dot14($cursor + 6);
                $cursor += 8;
            }

            $components[] = [
                'glyphId' => $componentGlyphId,
                'dx' => (float) $dx,
                'dy' => (float) $dy,
                'xx' => $xx,
                'yx' => $yx,
                'xy' => $xy,
                'yy' => $yy,
            ];
        } while (0 !== ($flags & self::MORE_COMPONENTS));

        if (0 !== ($flags & self::WE_HAVE_INSTRUCTIONS)) {
            $instructionLength = $glyf->uint16($cursor);
            $cursor += 2 + $instructionLength;
        }

        if (null !== $coordinates && null !== $this->gvar) {
            $deltas = $this->gvar->deltasForGlyph($glyphId->value, \count($components) + 4, $coordinates);

            foreach ($components as $index => $component) {
                $components[$index]['dx'] = $component['dx'] + ($deltas->x[$index] ?? 0.0);
                $components[$index]['dy'] = $component['dy'] + ($deltas->y[$index] ?? 0.0);
            }
        }

        $contours = [];

        foreach ($components as $component) {
            foreach ($this->readGlyph($component['glyphId'], $visited, $coordinates)->contours as $contour) {
                $contours[] = $contour->transform(
                    $component['xx'],
                    $component['yx'],
                    $component['xy'],
                    $component['yy'],
                    $component['dx'],
                    $component['dy'],
                );
            }
        }

        return new GlyphOutline($glyphId, $contours);
    }

    private function normalizedCoordinates(?VariationCoordinates $coordinates): ?NormalizedCoordinates
    {
        if (null === $coordinates || null === $this->variations) {
            return null;
        }

        return $coordinates->normalized($this->variations, $this->avar);
    }

    private function glyphPointCount(GlyphId $glyphId): int
    {
        $start = $this->glyphOffsets[$glyphId->value];
        $end = $this->glyphOffsets[$glyphId->value + 1];

        if ($start === $end) {
            return 0;
        }

        $glyf = $this->reader->table($this->tables['glyf']);
        $numberOfContours = $glyf->int16($start);

        if ($numberOfContours <= 0) {
            return 0;
        }

        return $glyf->uint16($start + 10 + ($numberOfContours - 1) * 2) + 1;
    }

    private function componentMetricsGlyphId(GlyphId $glyphId): ?GlyphId
    {
        $start = $this->glyphOffsets[$glyphId->value];
        $end = $this->glyphOffsets[$glyphId->value + 1];

        if ($start === $end) {
            return null;
        }

        $glyf = $this->reader->table($this->tables['glyf']);

        if ($glyf->int16($start) >= 0) {
            return null;
        }

        $cursor = $start + 10;
        $metricsGlyphId = null;

        do {
            $flags = $glyf->uint16($cursor);
            $componentGlyphId = new GlyphId($glyf->uint16($cursor + 2));
            $cursor += 4;
            $cursor += 0 !== ($flags & self::ARG_1_AND_2_ARE_WORDS) ? 4 : 2;

            if (0 !== ($flags & self::WE_HAVE_A_SCALE)) {
                $cursor += 2;
            } elseif (0 !== ($flags & self::WE_HAVE_AN_X_AND_Y_SCALE)) {
                $cursor += 4;
            } elseif (0 !== ($flags & self::WE_HAVE_A_TWO_BY_TWO)) {
                $cursor += 8;
            }

            if (0 !== ($flags & self::USE_MY_METRICS)) {
                $metricsGlyphId = $componentGlyphId;
            }
        } while (0 !== ($flags & self::MORE_COMPONENTS));

        return $metricsGlyphId;
    }

    /**
     * @param list<int> $endPoints
     * @param list<int> $xCoordinates
     * @param list<int> $yCoordinates
     *
     * @return array{0: list<float>, 1: list<float>}
     */
    private function simpleGlyphVariationDeltas(
        GlyphId $glyphId,
        int $pointCount,
        NormalizedCoordinates $coordinates,
        array $endPoints,
        array $xCoordinates,
        array $yCoordinates,
    ): array {
        \assert(null !== $this->gvar);
        \assert(null !== $this->variations);

        $totalPointCount = $pointCount + 4;
        $xDeltas = array_fill(0, $totalPointCount, 0.0);
        $yDeltas = array_fill(0, $totalPointCount, 0.0);

        foreach ($this->gvar->tupleVariationsForGlyph($glyphId->value, $totalPointCount) as $variation) {
            $scalar = $variation->region->scalar($coordinates, $this->variations);

            if (0.0 === $scalar) {
                continue;
            }

            $tupleX = array_fill(0, $totalPointCount, 0.0);
            $tupleY = array_fill(0, $totalPointCount, 0.0);
            $explicitPoints = $variation->pointNumbers ?? range(0, $totalPointCount - 1);

            foreach ($explicitPoints as $index => $pointNumber) {
                $tupleX[$pointNumber] = ($variation->xDeltas[$index] ?? 0) * $scalar;
                $tupleY[$pointNumber] = ($variation->yDeltas[$index] ?? 0) * $scalar;
            }

            if (null !== $variation->pointNumbers) {
                $tupleX = self::inferContourDeltas(array_values($tupleX), $variation->pointNumbers, $endPoints, $xCoordinates);
                $tupleY = self::inferContourDeltas(array_values($tupleY), $variation->pointNumbers, $endPoints, $yCoordinates);
            }

            foreach ($tupleX as $index => $delta) {
                $xDeltas[$index] += $delta;
            }

            foreach ($tupleY as $index => $delta) {
                $yDeltas[$index] += $delta;
            }
        }

        return [
            array_values(\array_slice($xDeltas, 0, $pointCount)),
            array_values(\array_slice($yDeltas, 0, $pointCount)),
        ];
    }

    /**
     * @param list<float> $deltas
     * @param list<int>   $explicitPoints
     * @param list<int>   $endPoints
     * @param list<int>   $coordinates
     *
     * @return list<float>
     */
    private static function inferContourDeltas(array $deltas, array $explicitPoints, array $endPoints, array $coordinates): array
    {
        $explicit = array_fill_keys($explicitPoints, true);
        $startPoint = 0;

        foreach ($endPoints as $endPoint) {
            $touched = [];

            for ($i = $startPoint; $i <= $endPoint; ++$i) {
                if (isset($explicit[$i])) {
                    $touched[] = $i;
                }
            }

            if (1 === \count($touched)) {
                $delta = $deltas[$touched[0]];

                for ($i = $startPoint; $i <= $endPoint; ++$i) {
                    $deltas[$i] = $delta;
                }
            } elseif (\count($touched) > 1) {
                $touchedCount = \count($touched);

                for ($i = 0; $i < $touchedCount; ++$i) {
                    $left = $touched[$i];
                    $right = $touched[($i + 1) % $touchedCount];
                    $pointsBetween = self::contourPointsBetween($left, $right, $startPoint, $endPoint);

                    foreach ($pointsBetween as $point) {
                        $deltas[$point] = self::interpolateDelta(
                            $coordinates[$point],
                            $coordinates[$left],
                            $coordinates[$right],
                            $deltas[$left],
                            $deltas[$right],
                        );
                    }
                }
            }

            $startPoint = $endPoint + 1;
        }

        return array_values($deltas);
    }

    /**
     * @return list<int>
     */
    private static function contourPointsBetween(int $left, int $right, int $startPoint, int $endPoint): array
    {
        $points = [];
        $point = $left;

        while (true) {
            $point = $point === $endPoint ? $startPoint : $point + 1;

            if ($point === $right) {
                return $points;
            }

            $points[] = $point;
        }
    }

    private static function interpolateDelta(int $coordinate, int $leftCoordinate, int $rightCoordinate, float $leftDelta, float $rightDelta): float
    {
        if ($leftCoordinate > $rightCoordinate) {
            return self::interpolateDelta($coordinate, $rightCoordinate, $leftCoordinate, $rightDelta, $leftDelta);
        }

        if ($leftCoordinate === $rightCoordinate) {
            return $leftDelta;
        }

        if ($coordinate <= $leftCoordinate) {
            return $leftDelta;
        }

        if ($coordinate >= $rightCoordinate) {
            return $rightDelta;
        }

        return $leftDelta + ($rightDelta - $leftDelta) * (($coordinate - $leftCoordinate) / ($rightCoordinate - $leftCoordinate));
    }

    /**
     * @param list<GlyphPoint> $points
     *
     * @return list<PathCommand>
     */
    private static function commandsForContour(array $points): array
    {
        if ([] === $points) {
            return [];
        }

        $pointCount = \count($points);
        $first = $points[0];
        $last = $points[$pointCount - 1];

        if ($first->onCurve) {
            $start = $first;
            $startIndex = 1;
        } elseif ($last->onCurve) {
            $start = $last;
            $startIndex = 0;
        } else {
            $start = GlyphPoint::midpoint($last, $first);
            $startIndex = 0;
        }

        $commands = [PathCommand::moveTo($start->x, $start->y)];
        $consumed = 0;

        while ($consumed < $pointCount) {
            $point = $points[($startIndex + $consumed) % $pointCount];

            if ($point->onCurve) {
                $commands[] = PathCommand::lineTo($point->x, $point->y);
                ++$consumed;
                continue;
            }

            $next = $points[($startIndex + $consumed + 1) % $pointCount];

            if ($next->onCurve) {
                $commands[] = PathCommand::quadraticTo($point->x, $point->y, $next->x, $next->y);
                $consumed += 2;
                continue;
            }

            $implied = GlyphPoint::midpoint($point, $next);
            $commands[] = PathCommand::quadraticTo($point->x, $point->y, $implied->x, $implied->y);
            ++$consumed;
        }

        $commands[] = PathCommand::closePath();

        return $commands;
    }

    /**
     * @return array<string, TableRecord>
     */
    private static function parseTableRecords(BinaryReader $reader, int $directoryOffset = 0): array
    {
        $numTables = $reader->uint16($directoryOffset + 4);
        $tables = [];

        for ($i = 0; $i < $numTables; ++$i) {
            $recordOffset = $directoryOffset + 12 + $i * 16;
            $tag = $reader->string($recordOffset, 4);
            $tableOffset = $reader->uint32($recordOffset + 8);
            $length = $reader->uint32($recordOffset + 12);
            $tables[$tag] = new TableRecord($tag, $tableOffset, $length);
        }

        ksort($tables);

        return $tables;
    }

    /**
     * @param array<string, TableRecord> $tables
     */
    private static function rejectUnsupportedGlyphTables(array $tables): void
    {
        $unsupported = [
            'CFF ' => 'CFF/OpenType outlines',
            'CFF2' => 'CFF2/OpenType outlines',
            'CBDT' => 'bitmap color glyphs',
            'CBLC' => 'bitmap color glyphs',
            'COLR' => 'layered color glyphs',
            'CPAL' => 'color palettes',
            'sbix' => 'Apple bitmap glyphs',
            'SVG ' => 'SVG-in-OpenType glyphs',
        ];

        foreach ($unsupported as $tag => $label) {
            if (isset($tables[$tag])) {
                throw new UnsupportedFontException(\sprintf('%s are not supported.', $label));
            }
        }
    }

    /**
     * @return list<int>
     */
    private static function parseLoca(BinaryReader $loca, int $glyphCount, int $indexToLocFormat): array
    {
        $offsets = [];

        for ($i = 0; $i <= $glyphCount; ++$i) {
            $offsets[] = 0 === $indexToLocFormat ? $loca->uint16($i * 2) * 2 : $loca->uint32($i * 4);
        }

        return $offsets;
    }

    /**
     * @return array<int, GlyphMetrics>
     */
    private static function parseMetrics(BinaryReader $hmtx, int $glyphCount, int $numberOfHMetrics): array
    {
        if ($numberOfHMetrics <= 0) {
            throw new InvalidFontException('hhea numberOfHMetrics must be positive.');
        }

        $metrics = [];
        $lastAdvanceWidth = 0;

        for ($glyphId = 0; $glyphId < $glyphCount; ++$glyphId) {
            if ($glyphId < $numberOfHMetrics) {
                $offset = $glyphId * 4;
                $lastAdvanceWidth = $hmtx->uint16($offset);
                $leftSideBearing = $hmtx->int16($offset + 2);
            } else {
                $offset = $numberOfHMetrics * 4 + ($glyphId - $numberOfHMetrics) * 2;
                $leftSideBearing = $hmtx->int16($offset);
            }

            $id = new GlyphId($glyphId);
            $metrics[$glyphId] = new GlyphMetrics($id, $lastAdvanceWidth, $leftSideBearing);
        }

        return $metrics;
    }

    private function assertGlyphId(GlyphId $glyphId): void
    {
        if ($glyphId->value >= $this->glyphCount) {
            throw new InvalidFontException(\sprintf('Glyph ID %d is outside font glyph count %d.', $glyphId->value, $this->glyphCount));
        }
    }
}
