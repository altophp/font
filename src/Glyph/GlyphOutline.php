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

namespace Alto\Font\Glyph;

/**
 * Represents immutable contour geometry for one glyph.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class GlyphOutline
{
    /**
     * @param list<Contour> $contours
     */
    public function __construct(public GlyphId $glyphId, public array $contours) {}

    public function isEmpty(): bool
    {
        return [] === $this->contours;
    }

    public function transform(
        float $xx,
        float $yx,
        float $xy,
        float $yy,
        float $dx,
        float $dy,
    ): self {
        return new self($this->glyphId, array_map(
            static fn(Contour $contour): Contour => $contour->transform($xx, $yx, $xy, $yy, $dx, $dy),
            $this->contours,
        ));
    }
}
