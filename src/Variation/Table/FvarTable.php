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
use Alto\Font\Variation\VariationAxis;
use Alto\Font\Variation\VariationInstance;

/**
 * Parses axes and named instances from an fvar table.
 *
 * @author Simon André <smn.andre@gmail.com>
 *
 * @internal
 */
final readonly class FvarTable
{
    /**
     * @param array<int, string> $names
     */
    public static function parse(BinaryReader $reader, array $names = []): ?FontVariations
    {
        $majorVersion = $reader->uint16(0);
        $minorVersion = $reader->uint16(2);

        if (1 !== $majorVersion || 0 !== $minorVersion) {
            throw new InvalidFontException(\sprintf('Unsupported fvar table version %d.%d.', $majorVersion, $minorVersion));
        }

        $axesArrayOffset = $reader->uint16(4);
        $axisCount = $reader->uint16(8);
        $axisSize = $reader->uint16(10);
        $instanceCount = $reader->uint16(12);
        $instanceSize = $reader->uint16(14);

        if (0 === $axisCount) {
            return null;
        }

        if ($axisSize < 20) {
            throw new InvalidFontException(\sprintf('Invalid fvar axis record size %d.', $axisSize));
        }

        $minimumInstanceSize = 4 + $axisCount * 4;

        if ($instanceSize < $minimumInstanceSize) {
            throw new InvalidFontException(\sprintf('Invalid fvar instance record size %d.', $instanceSize));
        }

        $axes = [];

        for ($i = 0; $i < $axisCount; ++$i) {
            $offset = $axesArrayOffset + $i * $axisSize;
            $axisNameId = $reader->uint16($offset + 18);
            $axes[] = new VariationAxis(
                tag: $reader->string($offset, 4),
                minimum: $reader->fixed16Dot16($offset + 4),
                default: $reader->fixed16Dot16($offset + 8),
                maximum: $reader->fixed16Dot16($offset + 12),
                flags: $reader->uint16($offset + 16),
                name: $names[$axisNameId] ?? null,
            );
        }

        $instances = [];
        $instancesOffset = $axesArrayOffset + $axisCount * $axisSize;

        for ($i = 0; $i < $instanceCount; ++$i) {
            $offset = $instancesOffset + $i * $instanceSize;
            $subfamilyNameId = $reader->uint16($offset);
            $coordinates = [];

            foreach ($axes as $axisIndex => $axis) {
                $coordinates[$axis->tag] = $reader->fixed16Dot16($offset + 4 + $axisIndex * 4);
            }

            $postScriptName = null;

            if ($instanceSize >= $minimumInstanceSize + 2) {
                $postScriptNameId = $reader->uint16($offset + $minimumInstanceSize);
                $postScriptName = 0xFFFF === $postScriptNameId ? null : ($names[$postScriptNameId] ?? null);
            }

            $instances[] = new VariationInstance(
                subfamilyName: $names[$subfamilyNameId] ?? \sprintf('Instance %d', $i + 1),
                coordinates: $coordinates,
                postScriptName: $postScriptName,
            );
        }

        return new FontVariations($axes, $instances);
    }
}
