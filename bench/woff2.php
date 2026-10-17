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

use Alto\Font\Compression\BrotliProcessCompressor;
use Alto\Font\Font;
use Alto\Font\Writer\Woff2Writer;

require __DIR__ . '/bootstrap.php';

[$path, $faceIndex] = bench_font('woff2.php');

$font = Font::fromFile($path, $faceIndex);
$writer = new Woff2Writer(new BrotliProcessCompressor());

$woff2 = bench_measure('Woff2Writer::dump() complete face', static fn(): string => $writer->dump($font));
printf("    %s\n", bench_bytes(\strlen($woff2)));
unset($woff2);

$subset = bench_latin_subset($font);

if (null === $subset) {
    exit(0);
}

$woff2 = bench_measure('Woff2Writer::dump() Latin subset', static fn(): string => $writer->dump($subset));
printf("    %s\n", bench_bytes(\strlen($woff2)));
