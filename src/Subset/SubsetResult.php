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

use Alto\Font\Font;

final readonly class SubsetResult
{
    /**
     * @param list<string> $warnings
     */
    public function __construct(
        public Font $font,
        public UnicodeSet $requestedUnicodes,
        public int $mappedCodepointCount,
        public int $originalGlyphCount,
        public int $retainedGlyphCount,
        public int $outputSize,
        public array $warnings = [],
    ) {}
}
