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

use Alto\Font\Binary\BinaryReader;
use Alto\Font\Exception\InvalidFontException;
use Alto\Font\Exception\UnsupportedFontException;
use Alto\Font\OpenType\Layout\ClassDefinitionTable;

/**
 * Compacts MERG glyph class definitions.
 *
 * @author Simon André <smn.andre@gmail.com>
 *
 * @internal
 */
final readonly class MergCompactor
{
    public static function compact(string $merg, GlyphIdMap $glyphIds): string
    {
        $reader = new BinaryReader($merg, 'MERG compaction source');

        if (0 !== $reader->uint16(0)) {
            throw new UnsupportedFontException('Compact MERG output supports version 0 only.');
        }

        $classCount = $reader->uint16(2);
        $mergeDataOffset = $reader->uint16(4);
        $classDefinitionCount = $reader->uint16(6);
        $classDefinitionOffsetsOffset = $reader->uint16(8);

        if (0 === $classCount || 0 === $mergeDataOffset || 0 === $classDefinitionOffsetsOffset) {
            throw new InvalidFontException('MERG header contains a NULL required offset or class count.');
        }

        $mergeData = $reader->string($mergeDataOffset, $classCount * $classCount);
        $classDefinitions = [];
        $lastOldGlyphId = -1;

        for ($index = 0; $index < $classDefinitionCount; ++$index) {
            $classDefinitionOffset = $reader->uint16($classDefinitionOffsetsOffset + $index * 2);
            $classes = ClassDefinitionTable::parse($reader, 0, $classDefinitionOffset);
            $remapped = [];

            foreach ($classes as $oldGlyphId => $class) {
                if ($oldGlyphId <= $lastOldGlyphId) {
                    throw new InvalidFontException('MERG class definition glyph IDs must be globally increasing.');
                }

                $lastOldGlyphId = $oldGlyphId;
                $newGlyphId = $glyphIds->newId($oldGlyphId);

                if (null !== $newGlyphId) {
                    $remapped[$newGlyphId] = $class;
                }
            }

            $classDefinitions[] = ClassDefinitionTable::build($remapped);
        }

        $offsetArrayOffset = 10;
        $cursor = $offsetArrayOffset + $classDefinitionCount * 2;
        $offsets = '';
        $data = '';

        foreach ($classDefinitions as $classDefinition) {
            $offsets .= self::offset16($cursor);
            $data .= $classDefinition;
            $cursor += \strlen($classDefinition);
        }

        return self::uint16(0)
            . self::uint16($classCount)
            . self::offset16($cursor)
            . self::uint16($classDefinitionCount)
            . self::offset16($offsetArrayOffset)
            . $offsets
            . $data
            . $mergeData;
    }

    private static function offset16(int $value): string
    {
        if ($value < 0 || $value > 0xFFFF) {
            throw new UnsupportedFontException('Compacted MERG data exceeds a 16-bit OpenType offset.');
        }

        return self::uint16($value);
    }

    private static function uint16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }
}
