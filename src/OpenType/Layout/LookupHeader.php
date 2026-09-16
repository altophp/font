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

use Alto\Font\Binary\BinaryReader;
use Alto\Font\Exception\InvalidFontException;

/**
 * Parses a source lookup header shared by GSUB and GPOS.
 *
 * @internal
 */
final readonly class LookupHeader
{
    /**
     * @param list<int> $subtableOffsets Relative to the beginning of the lookup
     */
    private function __construct(
        public int $type,
        public int $flag,
        public ?int $markFilteringSet,
        public array $subtableOffsets,
    ) {}

    /**
     * @param 'GSUB'|'GPOS' $tag
     */
    public static function parse(BinaryReader $reader, int $offset, string $tag, int $lookupIndex): self
    {
        $type = $reader->uint16($offset);
        $flag = $reader->uint16($offset + 2);
        $count = $reader->uint16($offset + 4);

        if (0 === $count) {
            throw new InvalidFontException(\sprintf('%s lookup %d must contain at least one subtable.', $tag, $lookupIndex));
        }

        $hasMarkFilteringSet = 0 !== ($flag & 0x0010);
        $headerLength = 6 + $count * 2 + ($hasMarkFilteringSet ? 2 : 0);
        $reader->string($offset, $headerLength);
        $markFilteringSet = $hasMarkFilteringSet ? $reader->uint16($offset + 6 + $count * 2) : null;
        $subtableOffsets = [];
        $kind = $type === ('GSUB' === $tag ? 7 : 9) ? 'extension' : 'subtable';

        for ($index = 0; $index < $count; ++$index) {
            $relativeOffset = $reader->uint16($offset + 6 + $index * 2);

            if (0 === $relativeOffset) {
                throw new InvalidFontException(\sprintf('%s lookup %d %s offset must not be NULL.', $tag, $lookupIndex, $kind));
            }

            if ($relativeOffset < $headerLength) {
                throw new InvalidFontException(\sprintf('%s lookup %d %s %d overlaps its lookup header.', $tag, $lookupIndex, $kind, $index));
            }

            $reader->uint16($offset + $relativeOffset);
            $subtableOffsets[] = $relativeOffset;
        }

        return new self($type, $flag, $markFilteringSet, $subtableOffsets);
    }
}
