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
use Alto\Font\Exception\InvalidFontException;

final readonly class DeltaSetIndexMap
{
    private const int INNER_INDEX_BIT_COUNT_MASK = 0x0F;
    private const int MAP_ENTRY_SIZE_MASK = 0x30;

    /**
     * @param list<array{0: int, 1: int}> $entries
     */
    private function __construct(private array $entries) {}

    public static function parse(BinaryReader $reader): self
    {
        $format = $reader->uint8(0);
        $entryFormat = $reader->uint8(1);

        if (!\in_array($format, [0, 1], true)) {
            throw new InvalidFontException(\sprintf('Unsupported DeltaSetIndexMap format %d.', $format));
        }

        $mapCount = 0 === $format ? $reader->uint16(2) : $reader->uint32(2);
        $cursor = 0 === $format ? 4 : 6;

        if (0 === $mapCount) {
            throw new InvalidFontException('DeltaSetIndexMap must contain at least one entry.');
        }

        if (0 !== ($entryFormat & 0xC0)) {
            throw new InvalidFontException('DeltaSetIndexMap entry format uses reserved bits.');
        }

        $innerBitCount = ($entryFormat & self::INNER_INDEX_BIT_COUNT_MASK) + 1;
        $entrySize = (($entryFormat & self::MAP_ENTRY_SIZE_MASK) >> 4) + 1;
        $innerMask = (1 << $innerBitCount) - 1;
        $entries = [];

        for ($i = 0; $i < $mapCount; ++$i) {
            $entry = 0;

            for ($j = 0; $j < $entrySize; ++$j) {
                $entry = ($entry << 8) | $reader->uint8($cursor++);
            }

            $entries[] = [$entry >> $innerBitCount, $entry & $innerMask];
        }

        return new self($entries);
    }

    /**
     * @return array{0: int, 1: int}
     */
    public function deltaSetIndex(int $index): array
    {
        return $this->entries[min($index, \count($this->entries) - 1)];
    }
}
