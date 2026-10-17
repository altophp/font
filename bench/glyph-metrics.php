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
use Alto\Font\Glyph\GlyphId;

require __DIR__ . '/bootstrap.php';

[$path, $faceIndex] = bench_font('glyph-metrics.php');

$font = Font::fromFile($path, $faceIndex);
$glyphCount = $font->face()->glyphCount;

$advance = bench_measure(
    \sprintf('glyphMetrics() x %d', $glyphCount),
    static function () use ($font, $glyphCount): int {
        $advance = 0;

        for ($glyphId = 0; $glyphId < $glyphCount; ++$glyphId) {
            $advance += $font->glyphMetrics(new GlyphId($glyphId))->advanceWidth;
        }

        return $advance;
    },
    $glyphCount > 10_000 ? 1 : 5,
);

printf("\nsum of advance widths: %d\n", $advance);
