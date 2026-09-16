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

namespace Alto\Font\Variation\ItemStore;

use Alto\Font\Binary\BinaryReader;
use Alto\Font\OpenType\Layout\DeltaSetIndexMapTable;

/**
 * Maps glyph identifiers to variation delta set indexes.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class DeltaSetIndexMap
{
    /**
     * @param list<array{0: int, 1: int}> $entries
     */
    private function __construct(private array $entries) {}

    public static function parse(BinaryReader $reader): self
    {
        return new self(DeltaSetIndexMapTable::parse($reader, 0)['entries']);
    }

    /**
     * @return array{0: int, 1: int}
     */
    public function deltaSetIndex(int $index): array
    {
        return $this->entries[min($index, \count($this->entries) - 1)];
    }
}
