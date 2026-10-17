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

[$path, $faceIndex] = bench_font('glyph-outlines.php');

$font = Font::fromFile($path, $faceIndex);
$glyphCount = $font->face()->glyphCount;

$contours = bench_measure(
    \sprintf('glyphOutline() x %d', $glyphCount),
    static function () use ($font, $glyphCount): int {
        $contours = 0;

        for ($glyphId = 0; $glyphId < $glyphCount; ++$glyphId) {
            $contours += \count($font->glyphOutline(new GlyphId($glyphId))->contours);
        }

        return $contours;
    },
    $glyphCount > 10_000 ? 1 : 3,
);

printf("\n%d contours decoded\n", $contours);
