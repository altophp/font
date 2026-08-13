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

namespace Alto\Font;

use Alto\Font\Descriptor\FontDescriptor;
use Alto\Font\Exception\InvalidFontException;
use Alto\Font\Glyph\GlyphId;
use Alto\Font\Glyph\GlyphMetrics;
use Alto\Font\Glyph\GlyphOutline;
use Alto\Font\Loader\FontLoader;
use Alto\Font\Metadata\FontMetadata;
use Alto\Font\OpenType\SfntFont;
use Alto\Font\Text\UnicodeString;
use Alto\Font\Variation\FontVariations;
use Alto\Font\Variation\VariationCoordinates;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Font
{
    public function __construct(
        private SfntFont $font,
        private ?VariationCoordinates $variationCoordinates = null,
    ) {}

    public static function fromFile(string|\Stringable $file, int $faceIndex = 0): self
    {
        return (new FontLoader())->load($file, $faceIndex);
    }

    public function face(): FontFace
    {
        return $this->font->face();
    }

    public function getFace(): FontFace
    {
        return $this->face();
    }

    public function getDescriptor(): FontDescriptor
    {
        return FontDescriptor::fromFace($this->face());
    }

    public function metadata(): FontMetadata
    {
        return FontMetadata::fromFace($this->face());
    }

    public function variations(): ?FontVariations
    {
        return $this->font->variations();
    }

    /**
     * @param array<string, float|int>|VariationCoordinates $coordinates
     */
    public function withVariations(array|VariationCoordinates $coordinates): self
    {
        $variations = $this->variations();

        if (null === $variations) {
            throw new InvalidFontException('Font does not define variation axes.');
        }

        if (\is_array($coordinates)) {
            $coordinates = new VariationCoordinates($coordinates);
        }

        return new self($this->font, $coordinates->resolve($variations));
    }

    public function variationCoordinates(): ?VariationCoordinates
    {
        return $this->variationCoordinates;
    }

    public function glyphIdForCodepoint(int $codepoint): ?GlyphId
    {
        return $this->font->glyphIdForCodepoint($codepoint);
    }

    public function glyphMetrics(GlyphId $glyphId): GlyphMetrics
    {
        return $this->font->glyphMetrics($glyphId, $this->variationCoordinates);
    }

    public function getGlyphMetrics(GlyphId $glyphId): GlyphMetrics
    {
        return $this->glyphMetrics($glyphId);
    }

    public function getMetrics(GlyphId|string $glyph): GlyphMetrics
    {
        if ($glyph instanceof GlyphId) {
            return $this->glyphMetrics($glyph);
        }

        $codepoints = UnicodeString::codepoints($glyph);

        if (1 !== \count($codepoints)) {
            throw new InvalidFontException('Font metrics can only be read for a single glyph or character.');
        }

        $glyphId = $this->glyphIdForCodepoint($codepoints[0]);

        if (null === $glyphId) {
            throw new InvalidFontException(\sprintf('Font has no glyph for codepoint U+%04X.', $codepoints[0]));
        }

        return $this->glyphMetrics($glyphId);
    }

    public function glyphOutline(GlyphId $glyphId): GlyphOutline
    {
        return $this->font->glyphOutline($glyphId, $this->variationCoordinates);
    }
}
