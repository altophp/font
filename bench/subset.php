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

use Alto\Font\Font;
use Alto\Font\Subset\GlyphIdPolicy;
use Alto\Font\Subset\LayoutPolicy;
use Alto\Font\Subset\SubsetOptions;
use Alto\Font\Subset\SubsetResult;
use Alto\Font\Subset\UnicodeSet;

require __DIR__ . '/bootstrap.php';

[$path, $faceIndex] = bench_font('subset.php');

$font = Font::fromFile($path, $faceIndex);
$unicodes = UnicodeSet::fromCss(bench_latin_css());

foreach ([GlyphIdPolicy::Preserve, GlyphIdPolicy::Compact] as $glyphIds) {
    foreach ([LayoutPolicy::Preserve, LayoutPolicy::Drop] as $layout) {
        $options = new SubsetOptions($unicodes, glyphIds: $glyphIds, layout: $layout);
        $result = bench_attempt(
            \sprintf('subset() glyphIds %s, layout %s', $glyphIds->name, $layout->name),
            static fn(): SubsetResult => $font->subset($options),
        );

        if (null === $result) {
            continue;
        }

        printf("    %d glyphs kept of %d, %s\n", $result->retainedGlyphCount, $result->originalGlyphCount, bench_bytes($result->sfntSize));
    }
}
