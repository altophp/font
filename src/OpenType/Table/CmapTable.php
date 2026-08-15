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

use Alto\Font\Binary\BinaryReader;
use Alto\Font\Exception\InvalidFontException;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class CmapTable
{
    /**
     * @param array<int, int> $codepointToGlyphId
     */
    public function __construct(private array $codepointToGlyphId) {}

    public function glyphIdForCodepoint(int $codepoint): ?int
    {
        return $this->codepointToGlyphId[$codepoint] ?? null;
    }

    /**
     * @return array<int, int>
     */
    public function mappings(): array
    {
        return $this->codepointToGlyphId;
    }

    public static function parse(BinaryReader $reader): self
    {
        if (0 !== $reader->uint16(0)) {
            throw new InvalidFontException('Unsupported cmap table version.');
        }

        $numTables = $reader->uint16(2);
        $bestFormat4Offset = null;
        $bestFormat12Offset = null;

        for ($i = 0; $i < $numTables; ++$i) {
            $recordOffset = 4 + $i * 8;
            $platformId = $reader->uint16($recordOffset);
            $encodingId = $reader->uint16($recordOffset + 2);
            $subtableOffset = $reader->uint32($recordOffset + 4);
            $format = $reader->uint16($subtableOffset);

            if (12 === $format && self::isPreferredCmap($platformId, $encodingId)) {
                $bestFormat12Offset = $subtableOffset;
            }

            if (4 === $format && self::isPreferredCmap($platformId, $encodingId)) {
                $bestFormat4Offset = $subtableOffset;
            }
        }

        if (null !== $bestFormat12Offset) {
            return new self(self::parseFormat12($reader, $bestFormat12Offset));
        }

        if (null !== $bestFormat4Offset) {
            return new self(self::parseFormat4($reader, $bestFormat4Offset));
        }

        throw new InvalidFontException('No supported cmap format 4 or 12 subtable found.');
    }

    private static function isPreferredCmap(int $platformId, int $encodingId): bool
    {
        return 0 === $platformId || (3 === $platformId && \in_array($encodingId, [1, 10], true));
    }

    /**
     * @return array<int, int>
     */
    private static function parseFormat4(BinaryReader $reader, int $offset): array
    {
        $length = $reader->uint16($offset + 2);
        $segCount = intdiv($reader->uint16($offset + 6), 2);
        $endCodeOffset = $offset + 14;
        $startCodeOffset = $endCodeOffset + $segCount * 2 + 2;
        $idDeltaOffset = $startCodeOffset + $segCount * 2;
        $idRangeOffsetOffset = $idDeltaOffset + $segCount * 2;
        $map = [];

        for ($segment = 0; $segment < $segCount; ++$segment) {
            $endCode = $reader->uint16($endCodeOffset + $segment * 2);
            $startCode = $reader->uint16($startCodeOffset + $segment * 2);
            $idDelta = $reader->int16($idDeltaOffset + $segment * 2);
            $idRangeOffset = $reader->uint16($idRangeOffsetOffset + $segment * 2);

            if (0xFFFF === $startCode && 0xFFFF === $endCode) {
                continue;
            }

            for ($codepoint = $startCode; $codepoint <= $endCode; ++$codepoint) {
                if (0 === $idRangeOffset) {
                    $map[$codepoint] = ($codepoint + $idDelta) & 0xFFFF;
                    continue;
                }

                $glyphOffset = $idRangeOffsetOffset + $segment * 2 + $idRangeOffset + ($codepoint - $startCode) * 2;

                if ($glyphOffset + 2 > $offset + $length) {
                    continue;
                }

                $glyphId = $reader->uint16($glyphOffset);

                if (0 !== $glyphId) {
                    $glyphId = ($glyphId + $idDelta) & 0xFFFF;
                }

                $map[$codepoint] = $glyphId;
            }
        }

        return $map;
    }

    /**
     * @return array<int, int>
     */
    private static function parseFormat12(BinaryReader $reader, int $offset): array
    {
        if (0 !== $reader->uint16($offset + 2)) {
            throw new InvalidFontException('Unsupported cmap format 12 reserved value.');
        }

        $groups = $reader->uint32($offset + 12);
        $map = [];

        for ($i = 0; $i < $groups; ++$i) {
            $groupOffset = $offset + 16 + $i * 12;
            $startCharCode = $reader->uint32($groupOffset);
            $endCharCode = $reader->uint32($groupOffset + 4);
            $startGlyphId = $reader->uint32($groupOffset + 8);

            for ($codepoint = $startCharCode; $codepoint <= $endCharCode; ++$codepoint) {
                $map[$codepoint] = $startGlyphId + ($codepoint - $startCharCode);
            }
        }

        return $map;
    }
}
