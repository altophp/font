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
use Alto\Font\FontFinder;

require __DIR__ . '/bootstrap.php';

$arguments = bench_arguments();
$directory = $arguments[1] ?? '/System/Library/Fonts/Supplemental';
$family = $arguments[2] ?? 'Verdana';

if (!is_dir($directory)) {
    fwrite(STDERR, \sprintf("Directory \"%s\" does not exist.\n", $directory));
    exit(1);
}

printf("%s\n", bench_environment());
printf("directory: %s, family: %s\n\n", $directory, $family);

$finder = FontFinder::fromDirectories($directory);

$font = bench_measure('FontFinder::find() first query', static fn(): ?Font => $finder->find($family));
bench_measure('FontFinder::find() same query again', static fn(): ?Font => $finder->find($family), 5);
bench_measure('FontFinder::find() other family, described', static fn(): ?Font => $finder->find($family . ' Bold'), 5);

printf("\n%s\n", null === $font ? 'not found' : $font->face()->path);
printf("%d candidate(s) skipped\n", \count($finder->diagnostics()));
