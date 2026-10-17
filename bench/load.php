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

[$path, $faceIndex] = bench_font('load.php');

$font = bench_measure('Font::fromFile()', static fn(): Font => Font::fromFile($path, $faceIndex), 3);

printf("\n%s, %d glyphs, %s\n", $font->metadata()->family, $font->face()->glyphCount, $font->face()->format->name);
