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

namespace Alto\Font\Subset;

final readonly class SubsetOptions
{
    public function __construct(
        public UnicodeSet $unicodes,
        public HintingPolicy $hinting = HintingPolicy::Keep,
        public GlyphIdPolicy $glyphIds = GlyphIdPolicy::Preserve,
        public LayoutPolicy $layout = LayoutPolicy::Preserve,
    ) {}
}
