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

require __DIR__ . '/bootstrap.php';

[$path, $faceIndex] = bench_font('cmap.php');

$font = Font::fromFile($path, $faceIndex);
$codepoints = bench_latin_codepoints();

$mapped = bench_measure(
    \sprintf('glyphIdForCodepoint() x %d', \count($codepoints)),
    static function () use ($font, $codepoints): int {
        $mapped = 0;

        foreach ($codepoints as $codepoint) {
            if (null !== $font->glyphIdForCodepoint($codepoint)) {
                ++$mapped;
            }
        }

        return $mapped;
    },
    20,
);

printf("\n%d of %d codepoints mapped\n", $mapped, \count($codepoints));
