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

namespace Alto\Font\OpenType\Layout;

/**
 * Holds a compacted OpenType layout lookup before offset serialization.
 *
 * @author Simon André <smn.andre@gmail.com>
 *
 * @internal
 */
final readonly class LookupTable
{
    /**
     * @param list<string> $subtables
     */
    public function __construct(
        public int $type,
        public int $flag,
        public ?int $markFilteringSet,
        public array $subtables,
        public bool $extension = false,
    ) {
        if ([] === $subtables) {
            throw new \InvalidArgumentException('An OpenType layout lookup must contain at least one subtable.');
        }
    }
}
