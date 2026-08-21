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

namespace Alto\Font\OpenType;

/**
 * Carries an internal TrueType subset result.
 *
 * @author Simon André <smn.andre@gmail.com>
 *
 * @internal
 */
final readonly class GlyfSubset
{
    /**
     * @param list<string> $warnings
     */
    public function __construct(
        public SfntDocument $document,
        public int $mappedCodepointCount,
        public int $retainedGlyphCount,
        public array $warnings,
    ) {}
}
