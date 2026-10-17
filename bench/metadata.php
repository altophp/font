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

use Alto\Font\Descriptor\FontDescriptor;
use Alto\Font\Font;
use Alto\Font\Metadata\FontMetadata;

require __DIR__ . '/bootstrap.php';

[$path, $faceIndex] = bench_font('metadata.php');

$font = Font::fromFile($path, $faceIndex);

$metadata = bench_measure('metadata()', static fn(): FontMetadata => $font->metadata(), 100);
$descriptor = bench_measure('descriptor()', static fn(): FontDescriptor => $font->descriptor(), 100);

printf("\n%s %s, weight %d, style %s\n", $metadata->family, $metadata->subfamily, $descriptor->weight->value, $descriptor->style->name);
