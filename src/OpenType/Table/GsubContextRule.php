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

namespace Alto\Font\OpenType\Table;

/**
 * Carries one parsed contextual substitution rule.
 *
 * @author Simon André <smn.andre@gmail.com>
 *
 * @internal
 */
final readonly class GsubContextRule
{
    /**
     * @param list<GsubGlyphSet>                              $inputs
     * @param list<GsubGlyphSet>                              $conditions
     * @param list<array{sequenceIndex: int, lookupIndex: int}> $lookupRecords
     */
    public function __construct(
        public array $inputs,
        public array $conditions,
        public array $lookupRecords,
    ) {}
}
