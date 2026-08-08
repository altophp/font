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

namespace Alto\Font\Variation\Table;

use Alto\Font\Binary\BinaryReader;
use Alto\Font\Exception\InvalidFontException;
use Alto\Font\Variation\FontVariations;

final readonly class AvarTable
{
    /**
     * @param array<string, list<array{0: float, 1: float}>> $maps
     */
    public function __construct(private array $maps) {}

    public static function parse(BinaryReader $reader, FontVariations $variations): self
    {
        $majorVersion = $reader->uint16(0);
        $minorVersion = $reader->uint16(2);

        if (1 !== $majorVersion || 0 !== $minorVersion) {
            throw new InvalidFontException(\sprintf('Unsupported avar table version %d.%d.', $majorVersion, $minorVersion));
        }

        $axisCount = $reader->uint16(6);

        if ($axisCount !== \count($variations->axes)) {
            throw new InvalidFontException(\sprintf('avar axis count %d does not match fvar axis count %d.', $axisCount, \count($variations->axes)));
        }

        $cursor = 8;
        $maps = [];

        foreach ($variations->axes as $axis) {
            $positionMapCount = $reader->uint16($cursor);
            $cursor += 2;
            $map = [];

            for ($i = 0; $i < $positionMapCount; ++$i) {
                $from = $reader->fixed2Dot14($cursor);
                $to = $reader->fixed2Dot14($cursor + 2);
                $cursor += 4;

                $map[] = [$from, $to];
            }

            $maps[$axis->tag] = self::validMap($map) ? $map : [];
        }

        return new self($maps);
    }

    public function map(string $axisTag, float $value): float
    {
        $map = $this->maps[$axisTag] ?? [];

        if ([] === $map) {
            return $value;
        }

        $value = max(-1.0, min(1.0, $value));
        $previous = $map[0];

        foreach ($map as $current) {
            if ($value === $current[0]) {
                return $current[1];
            }

            if ($value < $current[0]) {
                [$fromA, $toA] = $previous;
                [$fromB, $toB] = $current;

                if ($fromA === $fromB) {
                    return max(-1.0, min(1.0, $toA));
                }

                $ratio = ($value - $fromA) / ($fromB - $fromA);

                return max(-1.0, min(1.0, $toA + ($toB - $toA) * $ratio));
            }

            $previous = $current;
        }

        return max(-1.0, min(1.0, $previous[1]));
    }

    /**
     * @param list<array{0: float, 1: float}> $map
     */
    private static function validMap(array $map): bool
    {
        if (\count($map) < 3) {
            return false;
        }

        $required = [
            '-1' => false,
            '0' => false,
            '1' => false,
        ];
        $previousFrom = null;
        $previousTo = null;

        foreach ($map as [$from, $to]) {
            if (null !== $previousFrom && $from <= $previousFrom) {
                return false;
            }

            if (null !== $previousTo && $to < $previousTo) {
                return false;
            }

            if (-1.0 === $from && -1.0 === $to) {
                $required['-1'] = true;
            }

            if (0.0 === $from && 0.0 === $to) {
                $required['0'] = true;
            }

            if (1.0 === $from && 1.0 === $to) {
                $required['1'] = true;
            }

            $previousFrom = $from;
            $previousTo = $to;
        }

        return !\in_array(false, $required, true);
    }
}
