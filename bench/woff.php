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
use Alto\Font\Writer\WoffWriter;

require __DIR__ . '/bootstrap.php';

[$path, $faceIndex] = bench_font('woff.php');

$font = Font::fromFile($path, $faceIndex);
$writer = new WoffWriter();

$woff = bench_measure('WoffWriter::dump() complete face', static fn(): string => $writer->dump($font));
printf("    %s\n", bench_bytes(\strlen($woff)));
unset($woff);

$subset = bench_latin_subset($font);

if (null === $subset) {
    exit(0);
}

$woff = bench_measure('WoffWriter::dump() Latin subset', static fn(): string => $writer->dump($subset));
printf("    %s\n", bench_bytes(\strlen($woff)));
