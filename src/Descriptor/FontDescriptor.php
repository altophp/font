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

namespace Alto\Font\Descriptor;

use Alto\Font\FontFace;

/**
 * Describes the names and CSS matching properties of a font.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class FontDescriptor
{
    public function __construct(
        public string $family,
        public string $subfamily,
        public ?string $fullName,
        public ?string $postScriptName,
        public FontWeight $weight,
        public FontStyle $style,
        public FontStretch $stretch,
    ) {}

    public static function fromFace(FontFace $face): self
    {
        $family = $face->name(16) ?? $face->name(1) ?? 'Unknown';
        $subfamily = $face->name(17) ?? $face->name(2) ?? 'Regular';

        return new self(
            family: $family,
            subfamily: $subfamily,
            fullName: $face->name(4),
            postScriptName: $face->name(6),
            weight: FontWeight::fromSubfamily($subfamily),
            style: FontStyle::fromSubfamily($subfamily),
            stretch: FontStretch::fromSubfamily($subfamily),
        );
    }
}
